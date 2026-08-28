<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace aiprovider_ragflow;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the download token / URL helpers.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(helper::class)]
final class helper_test extends \advanced_testcase {
    /**
     * Insert an enabled RAGflow provider with the given config, return its id.
     *
     * @param array $config Provider config (merged over baseurl/apikey defaults).
     * @param array $actionconfig Action config (frankenstyle action keys).
     * @return int
     */
    private function make_provider(array $config = [], array $actionconfig = []): int {
        global $DB;
        $config = array_merge(['baseurl' => 'https://ragflow.example.com', 'apikey' => 'k'], $config);
        return (int) $DB->insert_record('ai_providers', (object) [
            'name' => 'RAGflow test',
            'provider' => provider::class,
            'enabled' => 1,
            'config' => json_encode($config),
            'actionconfig' => json_encode($actionconfig),
        ]);
    }

    /**
     * sign_download() is a deterministic HMAC bound to every input.
     *
     * @return void
     */
    public function test_sign_download_is_deterministic_and_bound(): void {
        $this->resetAfterTest();
        $a = helper::sign_download(1, 'ds', 'doc', 2, 1000);
        $b = helper::sign_download(1, 'ds', 'doc', 2, 1000);
        $this->assertSame($a, $b, 'same inputs must produce the same signature');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a, 'sha256 hex digest expected');
        // Any changed field yields a different signature.
        $this->assertNotSame($a, helper::sign_download(1, 'ds', 'doc', 2, 1001));
        $this->assertNotSame($a, helper::sign_download(1, 'ds', 'doc', 3, 1000));
        $this->assertNotSame($a, helper::sign_download(1, 'ds', 'other', 2, 1000));
        $this->assertNotSame($a, helper::sign_download(9, 'ds', 'doc', 2, 1000));
    }

    /**
     * proxy_url() returns '' for invalid input and otherwise a signed download.php URL whose signature
     * matches sign_download() for the embedded expiry.
     *
     * @return void
     */
    public function test_proxy_url_structure_and_signature(): void {
        $this->resetAfterTest();
        $this->assertSame('', helper::proxy_url(0, 'ds', 'doc', 2));
        $this->assertSame('', helper::proxy_url(1, '', 'doc', 2));
        $this->assertSame('', helper::proxy_url(1, 'ds', '', 2));
        $this->assertSame('', helper::proxy_url(1, 'ds', 'doc', 0));

        // A non-existent provider id still builds a URL (token_ttl falls back to the default).
        $url = helper::proxy_url(424242, 'ds', 'doc', 2);
        $this->assertStringContainsString('/ai/provider/ragflow/download.php', $url);
        $this->assertStringContainsString('dataset=ds', $url);
        $this->assertStringContainsString('document=doc', $url);
        $this->assertStringContainsString('sig=', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertArrayHasKey('expiry', $q);
        $this->assertSame(
            helper::sign_download(424242, 'ds', 'doc', 2, (int) $q['expiry']),
            $q['sig'],
            'the URL signature must verify against sign_download()'
        );
    }

    /**
     * context_download_url() is token-less (no signature) and guards empty input.
     *
     * @return void
     */
    public function test_context_download_url_is_tokenless(): void {
        $this->assertSame('', helper::context_download_url(0, 'generate_text', 'ds', 'doc'));
        $this->assertSame('', helper::context_download_url(5, '', 'ds', 'doc'));
        $this->assertSame('', helper::context_download_url(5, 'generate_text', '', 'doc'));
        $this->assertSame('', helper::context_download_url(5, 'generate_text', 'ds', ''));

        $url = helper::context_download_url(49, 'generate_text', 'ds', 'doc');
        $this->assertStringContainsString('/ai/provider/ragflow/download.php', $url);
        $this->assertStringContainsString('contextid=49', $url);
        $this->assertStringContainsString('action=generate_text', $url);
        $this->assertStringContainsString('dataset=ds', $url);
        $this->assertStringContainsString('document=doc', $url);
        $this->assertStringNotContainsString('sig=', $url, 'context downloads carry no signed token');
    }

    /**
     * token_ttl() defaults, honours a configured value and floors it at the minimum.
     *
     * @return void
     */
    public function test_token_ttl_default_configured_and_floor(): void {
        $this->resetAfterTest();
        // Distinct ids so the per-request static cache in token_ttl() cannot collide.
        $default = $this->make_provider();
        $configured = $this->make_provider(['tokenttl' => 30]);
        $tooshort = $this->make_provider(['tokenttl' => 5]);

        $this->assertSame(helper::DOWNLOAD_TOKEN_DEFAULT_TTL, helper::token_ttl($default));
        $this->assertSame(30, helper::token_ttl($configured));
        $this->assertSame(helper::DOWNLOAD_TOKEN_MIN_TTL, helper::token_ttl($tooshort));
    }

    /**
     * action_download_context() rejects unknown actions and returns null when no provider is configured.
     *
     * @return void
     */
    public function test_action_download_context_guards(): void {
        $this->resetAfterTest();
        $this->assertNull(helper::action_download_context('bogus_action'));
        // A valid action but no enabled provider -> null (no RAGflow call is made).
        $this->assertNull(helper::action_download_context('generate_text'));
    }

    /**
     * metadata_link() builds a Moodle activity URL when the document carries full module metadata, and
     * otherwise falls back to the file URL, then the page URL, then ''. This is the pure source-link builder
     * used by every non-proxy citation.
     *
     * @return void
     */
    public function test_metadata_link(): void {
        // Full module metadata -> the mod view URL (trailing slash on the wwwroot is trimmed).
        $this->assertSame(
            'https://moodle.example/mod/page/view.php?id=42',
            helper::metadata_link((object) [
                'moodle_url' => 'https://moodle.example/',
                'module_type' => 'page',
                'module_id' => '42',
            ])
        );
        // Missing module fields -> file_url fallback.
        $this->assertSame(
            'https://files.example/doc.pdf',
            helper::metadata_link((object) [
                'moodle_url' => 'https://moodle.example',
                'file_url' => 'https://files.example/doc.pdf',
            ])
        );
        // No file_url -> page_url fallback.
        $this->assertSame('https://p.example/x', helper::metadata_link((object) ['page_url' => 'https://p.example/x']));
        // Nothing usable -> empty string.
        $this->assertSame('', helper::metadata_link((object) []));
    }
}
