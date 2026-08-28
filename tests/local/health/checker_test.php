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

namespace aiprovider_ragflow\local\health;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the reference checker's pure state classifiers — in particular that a reference which is
 * absent from a successfully loaded list (`missing`) is never confused with one that could not be verified
 * because the API was unreachable (`unverified`).
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(checker::class)]
final class checker_test extends \advanced_testcase {
    /**
     * classify_assistant(): ok / degraded (no KB) / missing / not_configured, and — crucially — unverified
     * (with the right api_* reason) whenever the list could not be loaded, instead of missing.
     *
     * @return void
     */
    public function test_classify_assistant(): void {
        $items = [
            'abc' => (object) ['name' => 'Onboarding', 'kb' => 2],
            'def' => (object) ['name' => 'NoKB', 'kb' => 0],
        ];

        $ok = checker::classify_assistant($items, '', '', 'abc');
        $this->assertSame(reference_status::OK, $ok->state);
        $this->assertSame('Onboarding', $ok->label);

        $degraded = checker::classify_assistant($items, '', '', 'def');
        $this->assertSame(reference_status::DEGRADED, $degraded->state);
        $this->assertSame('kb_not_bound', $degraded->reason);

        $missing = checker::classify_assistant($items, '', '', 'zzz');
        $this->assertSame(reference_status::MISSING, $missing->state);
        $this->assertSame('assistant_not_found', $missing->reason);
        $this->assertSame('zzz', $missing->reference);

        $this->assertSame(
            reference_status::NOT_CONFIGURED,
            checker::classify_assistant($items, '', '', '')->state
        );

        // The central rule: an empty list because of a fetch error is UNVERIFIED, not MISSING.
        $timeout = checker::classify_assistant([], 'timeout', 'HTTP timeout after 10s', 'abc');
        $this->assertSame(reference_status::UNVERIFIED, $timeout->state);
        $this->assertSame('api_timeout', $timeout->reason);
        $this->assertSame('HTTP timeout after 10s', $timeout->detail);
        $this->assertSame('abc', $timeout->reference);

        $this->assertSame('api_unauthorized', checker::classify_assistant([], 'unauthorized', '', 'abc')->reason);
        $this->assertSame('api_unreachable', checker::classify_assistant([], 'unreachable', '', 'abc')->reason);
        $this->assertSame('api_unreachable', checker::classify_assistant([], 'http', '', 'abc')->reason);
    }

    /**
     * classify_kb(): ok / degraded (kb_empty when no documents, kb_not_parsed when unparsed) / missing /
     * unverified.
     *
     * @return void
     */
    public function test_classify_kb(): void {
        $items = [
            'k1' => (object) ['name' => 'KB', 'chunk_count' => 10, 'document_count' => 3],
            'k2' => (object) ['name' => 'Empty', 'chunk_count' => 0, 'document_count' => 0],
            'k3' => (object) ['name' => 'Unparsed', 'chunk_count' => 0, 'document_count' => 5],
        ];
        $this->assertSame(reference_status::OK, checker::classify_kb($items, '', '', 'k1')->state);
        $this->assertSame('kb_empty', checker::classify_kb($items, '', '', 'k2')->reason);
        $this->assertSame('kb_not_parsed', checker::classify_kb($items, '', '', 'k3')->reason);
        $this->assertSame(reference_status::MISSING, checker::classify_kb($items, '', '', 'nope')->state);
        $this->assertSame(reference_status::UNVERIFIED, checker::classify_kb([], 'unreachable', '', 'k1')->state);
    }

    /**
     * classify_memory(): ok / missing / unverified / not_configured (memories have no degraded state).
     *
     * @return void
     */
    public function test_classify_memory(): void {
        $items = ['m1' => 'My memory'];
        $this->assertSame(reference_status::OK, checker::classify_memory($items, '', '', 'm1')->state);
        $this->assertSame(reference_status::MISSING, checker::classify_memory($items, '', '', 'nope')->state);
        $this->assertSame(reference_status::UNVERIFIED, checker::classify_memory([], 'timeout', '', 'm1')->state);
        $this->assertSame(reference_status::NOT_CONFIGURED, checker::classify_memory($items, '', '', '')->state);
    }

    /**
     * stale_option_label(): a missing reference reads "no longer in RAGflow", an unverified one "could not
     * be verified" — never conflated — and both abbreviate the id to 8 chars + ellipsis.
     *
     * @return void
     */
    public function test_stale_option_label(): void {
        $missing = new reference_status(reference_status::MISSING, 'assistant_not_found', '2839995cabcdef00');
        $unverified = new reference_status(reference_status::UNVERIFIED, 'api_unreachable', '2839995cabcdef00');

        $missinglabel = checker::stale_option_label($missing);
        $unverifiedlabel = checker::stale_option_label($unverified);

        $this->assertSame(get_string('reference:option_missing', 'aiprovider_ragflow', '2839995c…'), $missinglabel);
        $this->assertSame(get_string('reference:option_unverified', 'aiprovider_ragflow', '2839995c…'), $unverifiedlabel);
        $this->assertNotSame($missinglabel, $unverifiedlabel, 'missing and unverified must read differently');
    }
}
