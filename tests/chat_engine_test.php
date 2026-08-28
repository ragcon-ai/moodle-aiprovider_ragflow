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
 * Unit tests for the shared chat engine's pure helpers: the per-source metadata filter and the error
 * classifier.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(chat_engine::class)]
final class chat_engine_test extends \advanced_testcase {
    /**
     * Invoke a protected static method of chat_engine via reflection.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private static function call(string $method, array $args) {
        $m = new \ReflectionMethod(chat_engine::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }

    /**
     * A config object for block_metadata_extrabody().
     *
     * @param string $datasource
     * @param string $field
     * @return \stdClass
     */
    private static function cfg(string $datasource, string $field = 'course_id'): \stdClass {
        return (object) ['datasource' => $datasource, 'coursemetadatafield' => $field];
    }

    /**
     * "Whole knowledge base" and "This course" apply NO metadata filter.
     *
     * @return void
     */
    public function test_metadata_no_filter_sources(): void {
        $this->assertSame([], self::call('block_metadata_extrabody', [self::cfg('wholekb'), 5]));
        $this->assertSame([], self::call('block_metadata_extrabody', [self::cfg('thiscourse'), 5]));
    }

    /**
     * "External Moodle" gates on external_sharing = 1 (regardless of course).
     *
     * @return void
     */
    public function test_metadata_external_sharing_filter(): void {
        $out = self::call('block_metadata_extrabody', [self::cfg('external'), 5]);
        $this->assertArrayHasKey('metadata_condition', $out);
        $this->assertSame('and', $out['metadata_condition']['logic']);
        $conditions = $out['metadata_condition']['conditions'];
        $this->assertCount(1, $conditions);
        $this->assertSame('external_sharing', $conditions[0]['name']);
        $this->assertSame('in', $conditions[0]['comparison_operator']);
        $this->assertSame(['1'], $conditions[0]['value']);
    }

    /**
     * "This Moodle" scopes to the current course by the course-metadata field + the site URL.
     *
     * @return void
     */
    public function test_metadata_thismoodle_course_scope(): void {
        global $CFG;
        $out = self::call('block_metadata_extrabody', [self::cfg('thismoodle', 'my_course'), 5]);
        $conditions = $out['metadata_condition']['conditions'];
        $this->assertCount(2, $conditions);
        $this->assertSame('my_course', $conditions[0]['name'], 'uses the configured course-metadata field');
        $this->assertSame(['5'], $conditions[0]['value']);
        $this->assertSame('moodle_url', $conditions[1]['name']);
        $this->assertSame([rtrim($CFG->wwwroot, '/')], $conditions[1]['value']);
    }

    /**
     * "This Moodle" outside a real course (site context) applies no filter.
     *
     * @return void
     */
    public function test_metadata_thismoodle_without_course(): void {
        $this->assertSame([], self::call('block_metadata_extrabody', [self::cfg('thismoodle'), SITEID]));
    }

    /**
     * classify_error() maps raw failure text to a stable, coarse error type.
     *
     * @return void
     */
    public function test_classify_error(): void {
        $this->assertSame('embedding_contextwindow', self::call('classify_error', ['context window exceeded: too many tokens']));
        $this->assertSame('embedding', self::call('classify_error', ['embedding model failed']));
        $this->assertSame('http_5xx', self::call('classify_error', ['HTTP 500 from backend']));
        $this->assertSame('http_4xx', self::call('classify_error', ['HTTP 404 not found']));
        $this->assertSame('network', self::call('classify_error', ['Connection timeout']));
        $this->assertSame('network', self::call('classify_error', ['Guzzle exception thrown']));
        $this->assertSame('unexpected', self::call('classify_error', ['something odd happened']));
        // The exact detail strings the session/stateless helpers now emit must classify as intended, so the
        // technical cause surfaced in the drawer/dashboard carries the right coarse type for analytics.
        $this->assertSame('http_5xx', self::call('classify_error', ['HTTP 502']));
        $this->assertSame('network', self::call('classify_error', ['request exception: Could not resolve host']));
        $this->assertSame('embedding', self::call('classify_error', ['RAGflow code 102: embedding model unavailable']));
    }

    /**
     * strip_markers(): removes inline [ID:n] citation markers and trims the result.
     *
     * @return void
     */
    public function test_strip_markers(): void {
        $this->assertSame('Hello world', self::call('strip_markers', ['Hello [ID:1] world [ID:23]']));
        $this->assertSame('No markers here', self::call('strip_markers', ['No markers here']));
        $this->assertSame('', self::call('strip_markers', ['   ']));
    }

    /**
     * scope_key(): the per-user memory scope key is "<component>-<userid>".
     *
     * @return void
     */
    public function test_scope_key(): void {
        $this->assertSame('aiplacement_ragflowhelpdesk-5', self::call('scope_key', ['aiplacement_ragflowhelpdesk', 5]));
        $this->assertSame('block_ragflowtutor-0', self::call('scope_key', ['block_ragflowtutor', 0]));
    }

    /**
     * language_directive(): asks the assistant to answer in the current Moodle language, naming the ISO code.
     *
     * @return void
     */
    public function test_language_directive(): void {
        $directive = self::call('language_directive', []);
        $this->assertStringStartsWith('Please write your answer in ', $directive);
        $this->assertStringContainsString('language code: ' . current_language(), $directive);
    }

    /**
     * strip_source_enumeration(): removes an inline "ID n …" source list (and its dangling label), leaves a
     * plain answer untouched, and never strips the whole answer away.
     *
     * @return void
     */
    public function test_strip_source_enumeration(): void {
        // A plain answer is unchanged.
        $this->assertSame('This is the answer.', self::call('strip_source_enumeration', ['This is the answer.']));
        // An "ID n" enumeration and its dangling bold label are removed.
        $in = "The answer is 42.\n\n**Sources**\nID 1 doc.pdf\nID 2 other.pdf";
        $this->assertSame('The answer is 42.', self::call('strip_source_enumeration', [$in]));
        // Safety: an answer that is only an enumeration is not stripped away to nothing.
        $this->assertNotSame('', trim((string) self::call('strip_source_enumeration', ['ID 1 doc.pdf'])));
    }

    /**
     * strip_prompt_augmentation(): a restored user turn is reduced to the original question – the leading
     * "answer in language X" directive and the injected memory-facts block are removed.
     *
     * @return void
     */
    public function test_strip_prompt_augmentation(): void {
        // Language directive only (no memory block).
        $withdirective = "Please write your answer in English (language code: en).\n\nHallo";
        $this->assertSame('Hallo', self::call('strip_prompt_augmentation', [$withdirective]));
        // Directive + memory-facts block: everything up to and including the MEMORY delimiter is dropped.
        $withmemory = "Please write your answer in Deutsch (language code: de).\n\nRelevant facts:\n"
            . chat_engine::MEM_OPEN . "\n- likes coffee\n" . chat_engine::MEM_CLOSE . "\n\nWie geht es dir?";
        $this->assertSame('Wie geht es dir?', self::call('strip_prompt_augmentation', [$withmemory]));
        // A plain question (no augmentation) is unchanged.
        $this->assertSame('Just a question', self::call('strip_prompt_augmentation', ['Just a question']));
    }

    /**
     * cited_sources(): builds the numbered source list from the answer's [ID:n] citations and appends a
     * single "Sources:" reference line of [[n]] sentinels, in first-cited order, deduped. A cited chunk is
     * kept regardless of its similarity (no floor – e.g. an image), and a citation to a missing chunk
     * loses its marker.
     *
     * @return void
     */
    public function test_cited_sources(): void {
        $this->resetAfterTest(true);
        $chunk = function (float $sim, string $name, string $docid): \stdClass {
            return (object) [
                'similarity' => $sim,
                'document_name' => $name,
                'document_id' => $docid,
                'dataset_id' => 'ds1',
            ];
        };
        $chunks = [
            $chunk(0.90, 'Doc A.pdf', 'docA'),
            $chunk(0.05, 'Image.jpeg', 'docB'), // Very low similarity image – must still be kept (no floor).
        ];
        // With proxy = true no dataset-document URL lookup (network) is needed; base/key empty.
        $answer = 'Alpha [ID:0]. Beta [ID:1]. Gamma [ID:0]. Delta [ID:5].';
        [$rewritten, $sources] = self::call('cited_sources', [$answer, $chunks, 0, 1, true, '', '']);

        // Two distinct sources, numbered in first-cited order; the low-similarity image is kept.
        $this->assertCount(2, $sources);
        $this->assertSame(1, $sources[0]['number']);
        $this->assertSame('Doc A.pdf', $sources[0]['name']);
        $this->assertSame('docA', $sources[0]['document']);
        $this->assertSame(2, $sources[1]['number']);
        $this->assertSame('Image.jpeg', $sources[1]['name']);

        // Inline [ID:n] markers are gone; the reference line carries the deduped [[1]] [[2]] sentinels.
        $this->assertStringNotContainsString('[ID:', $rewritten);
        $this->assertStringContainsString('[[1]]', $rewritten);
        $this->assertStringContainsString('[[2]]', $rewritten);
        $this->assertSame(1, substr_count($rewritten, '[[1]]')); // Deduped: docA cited twice -> one sentinel.
        $this->assertStringContainsString(
            get_string('sourcesheading', 'aiprovider_ragflow') . ' [[1]] [[2]]',
            $rewritten
        );
    }

    /**
     * cited_sources(): a reply that cites nothing (no [ID:n] markers – e.g. a "no answer found" reply)
     * yields no sources at all, even when candidate chunks are available, and appends no "Sources:" line.
     * This is the guarantee that a not-found answer never shows a source: both chat paths build the source
     * list solely from the model's own citations (no blind fallback retrieval).
     *
     * @return void
     */
    public function test_cited_sources_none_without_citations(): void {
        $this->resetAfterTest(true);
        $chunks = [
            (object) [
                'similarity' => 0.95,
                'document_name' => 'Doc A.pdf',
                'document_id' => 'docA',
                'dataset_id' => 'ds1',
            ],
        ];
        $answer = 'The answer you are looking for is not found in the dataset!';
        [$rewritten, $sources] = self::call('cited_sources', [$answer, $chunks, 0, 1, true, '', '']);

        $this->assertSame([], $sources);
        $this->assertStringNotContainsString(get_string('sourcesheading', 'aiprovider_ragflow'), $rewritten);
        $this->assertSame($answer, $rewritten);
    }

    /**
     * is_no_hit_answer(): recognises RAGflow's stock "no relevant content" replies (case-insensitive) so
     * their sources are suppressed even when the assistant declined yet still cited a chunk; a real answer
     * (even one that cites a source) is never treated as a no-hit reply.
     *
     * @return void
     */
    public function test_is_no_hit_answer(): void {
        $this->assertTrue(self::call('is_no_hit_answer', ['The answer you are looking for is not found in the dataset!']));
        $this->assertTrue(self::call('is_no_hit_answer', ['It is not found in the knowledge base!']));
        $this->assertTrue(self::call('is_no_hit_answer', ['Sorry! No relevant content was found in the knowledge base!']));
        $this->assertTrue(self::call('is_no_hit_answer', ['NOT FOUND IN THE DATASET']));

        // A real answer is not a no-hit reply, even when it cites a source.
        $this->assertFalse(self::call('is_no_hit_answer', ['You log in on the login page with your account. [ID:0]']));
        $this->assertFalse(self::call('is_no_hit_answer', ['']));
    }
}
