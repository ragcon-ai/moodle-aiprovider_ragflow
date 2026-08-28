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

namespace aiprovider_ragflow\external;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the search endpoint's pure result-shaping helpers: media detection and the
 * rank/dedup/floor/cliff/cap grouping that turns retrieval chunks into the final result list.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(search::class)]
final class search_test extends \advanced_testcase {
    /**
     * Invoke a non-public static method of the search endpoint via reflection.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private static function call(string $method, array $args) {
        $m = new \ReflectionMethod(search::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }

    /**
     * Build a retrieval chunk.
     *
     * @param float $sim
     * @param string $name
     * @param string $docid
     * @return \stdClass
     */
    private static function chunk(float $sim, string $name, string $docid): \stdClass {
        return (object) [
            'similarity' => $sim,
            'document_keyword' => $name,
            'document_id' => $docid,
            'dataset_id' => 'ds1',
            'content' => 'snippet for ' . $name,
        ];
    }

    /**
     * is_media(): recognises image/media file extensions (case-insensitive); text files are not media.
     *
     * @return void
     */
    public function test_is_media(): void {
        $this->assertTrue(self::call('is_media', ['photo.JPG']));
        $this->assertTrue(self::call('is_media', ['IMG_3027.jpeg']));
        $this->assertTrue(self::call('is_media', ['clip.mp4']));
        $this->assertFalse(self::call('is_media', ['notes.pdf']));
        $this->assertFalse(self::call('is_media', ['data.txt']));
        $this->assertFalse(self::call('is_media', ['readme']));
    }

    /**
     * block_config(): id 0 (or a missing/blank instance) yields the defaults; a real block instance's config
     * is read, the quality knobs are clamped to sane ranges (non-numeric → default), datasets are trimmed +
     * de-duplicated, and an empty course field falls back. This is the admin-config contract for search.
     *
     * @return void
     */
    public function test_block_config_defaults_and_clamps(): void {
        global $DB;
        $this->resetAfterTest();

        // No instance -> defaults.
        $default = self::call('block_config', [0]);
        $this->assertSame([], $default['datasets']);
        $this->assertSame('all', $default['scope']);
        $this->assertSame('course_id', $default['coursefield']);

        // A real instance with out-of-range / messy config: minsimilarity > 1 (clamped to 1.0), maxresults
        // above the hard cap (-> 50), cliffratio < 0 (clamped to 0.0), non-numeric vectorweight (-> default).
        $cfg = (object) [
            'datasets' => ['a', '', 'b', 'a'],
            'scope' => 'course',
            'coursefield' => '',
            'rerankmodel' => ' r1 ',
            'minsimilarity' => 5,
            'maxresults' => 999,
            'cliffratio' => -1,
            'vectorweight' => 'abc',
        ];
        $id = $DB->insert_record('block_instances', (object) [
            'blockname' => 'ragflowsearch',
            'parentcontextid' => \context_system::instance()->id,
            'showinsubcontexts' => 0,
            'requiredbytheme' => 0,
            'pagetypepattern' => 'site-index',
            'defaultregion' => 'side-pre',
            'defaultweight' => 0,
            'configdata' => base64_encode(serialize($cfg)),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $out = self::call('block_config', [(int) $id]);
        $this->assertSame(['a', 'b'], $out['datasets'], 'trimmed + de-duplicated');
        $this->assertSame('course', $out['scope']);
        $this->assertSame('course_id', $out['coursefield'], 'empty course field falls back');
        $this->assertSame('r1', $out['rerankmodel']);
        $this->assertSame(1.0, $out['minsimilarity']);
        $this->assertSame(50, $out['maxresults']);
        $this->assertSame(0.0, $out['cliffratio']);
        $this->assertEqualsWithDelta(0.7, $out['vectorweight'], 0.0001, 'non-numeric weight falls back to default');
    }

    /**
     * rank_and_group(): ranks by relevance, keeps one entry per document, and drops text below the floor.
     *
     * @return void
     */
    public function test_rank_and_group_floor_and_dedup(): void {
        $chunks = [
            self::chunk(0.80, 'a.pdf', 'a'),
            self::chunk(0.60, 'b.pdf', 'b'),
            self::chunk(0.20, 'c.pdf', 'c'), // Below the 0.35 floor -> dropped.
            self::chunk(0.75, 'a.pdf', 'a'), // Duplicate document -> deduped.
        ];
        $out = self::call('rank_and_group', [$chunks, 0.35, 5, 0.0]); // Cliff off.
        $this->assertCount(2, $out);
        $this->assertSame('a.pdf', $out[0]['name']); // Ranked by similarity.
        $this->assertSame('b.pdf', $out[1]['name']);
        $this->assertFalse($out[0]['ismedia']);
    }

    /**
     * rank_and_group(): the relevance cliff cuts a weak tail, and the cap bounds the count.
     *
     * @return void
     */
    public function test_rank_and_group_cliff_and_cap(): void {
        $chunks = [
            self::chunk(1.00, 'top.pdf', 't'),
            self::chunk(0.55, 'mid.pdf', 'm'), // 0.55 >= 1.0 * 0.5 -> kept.
            self::chunk(0.40, 'low.pdf', 'l'), // 0.40 < 1.0 * 0.5 -> cut by the cliff.
        ];
        $this->assertCount(2, self::call('rank_and_group', [$chunks, 0.35, 5, 0.5]));
        // Cap wins even with the cliff off.
        $this->assertCount(1, self::call('rank_and_group', [$chunks, 0.0, 1, 0.0]));
    }

    /**
     * rank_and_group(): an image survives the lower media floor and is placed in its own group after the
     * text results.
     *
     * @return void
     */
    public function test_rank_and_group_media_group(): void {
        $chunks = [
            self::chunk(0.70, 'doc.pdf', 'd'),
            self::chunk(0.20, 'pic.jpeg', 'p'), // Below the text floor (0.35) but above the media floor (0.15).
        ];
        $out = self::call('rank_and_group', [$chunks, 0.35, 5, 0.0]);
        $this->assertCount(2, $out);
        $this->assertFalse($out[0]['ismedia']);
        $this->assertSame('doc.pdf', $out[0]['name']);
        $this->assertTrue($out[1]['ismedia']); // Media group comes after the text results.
        $this->assertSame('pic.jpeg', $out[1]['name']);
    }
}
