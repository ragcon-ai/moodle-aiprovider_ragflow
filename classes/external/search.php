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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Semantic search over the RAGflow knowledge base (retrieval only, no LLM). Used by the RAGflow
 * search block. The knowledge base(s) and the scope come from the calling **block instance's** own
 * admin configuration: a set of RAGflow datasets, searched either whole or scoped to the current
 * course via a document metadata condition (course id).
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search extends external_api {
    /** @var float Minimum relevance for a normal (text) document to be shown. */
    const MIN_SIMILARITY = 0.35;
    /** @var float Lower relevance floor for image/media documents – they embed with low text similarity. */
    const MEDIA_MIN_SIMILARITY = 0.15;
    /** @var int Maximum text documents shown. */
    const MAX_RESULTS = 5;
    /** @var int Maximum media documents shown (separate group). */
    const MAX_MEDIA = 3;
    /** @var float Keep a text result only while its score stays within this fraction of the top score. */
    const CLIFF_RATIO = 0.6;
    /** @var float Default hybrid semantic (vector) weight. Higher than RAGflow's keyword-heavy 0.3 default
     * so questions asked in sentence form match by meaning, not just literal keywords. */
    const VECTOR_WEIGHT = 0.7;
    /** @var int Candidate documents requested from RAGflow before the floor/cliff/cap are applied. */
    const CANDIDATES = 20;
    /** @var int Vector-search candidate pool when a rerank model reorders the results (needs headroom). */
    const RERANK_TOPK = 256;
    /** @var string Image/media file extensions that get the lower floor and a separate result group. */
    const MEDIA_EXTENSIONS = 'jpg,jpeg,png,gif,webp,bmp,svg,tif,tiff,heic,heif,mp4,mov,avi,webm,mp3,wav,ogg';

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'The context id of the page.'),
            'query' => new external_value(PARAM_TEXT, 'The search query.'),
            'blockinstanceid' => new external_value(
                PARAM_INT,
                'The RAGflow search block instance whose configuration (knowledge base + scope) drives the search.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Run the retrieval and return ranked results.
     *
     * @param int $contextid
     * @param string $query
     * @param int $blockinstanceid
     * @return array
     */
    public static function execute(int $contextid, string $query, int $blockinstanceid = 0): array {
        global $USER;

        [
            'contextid' => $contextid,
            'query' => $query,
            'blockinstanceid' => $blockinstanceid,
        ] = self::validate_parameters(
            self::execute_parameters(),
            ['contextid' => $contextid, 'query' => $query, 'blockinstanceid' => $blockinstanceid]
        );

        $context = \context::instance_by_id($contextid);
        // Authorise against the SEARCH BLOCK's own context, not the client-supplied contextid: otherwise a
        // user could pass an accessible contextid together with another block's id and have this function
        // search that block's knowledge base (possibly scoped to a course they cannot access). The block
        // context also drives the course scoping below, so a legitimate call behaves exactly as before.
        if ($blockinstanceid > 0) {
            $blockcontext = \context_block::instance($blockinstanceid, IGNORE_MISSING);
            if (!$blockcontext) {
                return ['results' => []];
            }
            $context = $blockcontext;
        }
        self::validate_context($context);
        require_login(null, false);

        $query = trim($query);
        if ($query === '') {
            return ['results' => []];
        }
        $starttime = microtime(true);

        [$providerid, $base, $key] = self::provider_credentials();
        if ($base === '' || $key === '') {
            return ['results' => []];
        }

        // The knowledge base + scope come from the block instance's own admin configuration.
        $bconf = self::block_config($blockinstanceid);
        if (empty($bconf['datasets'])) {
            // The block requires an explicit knowledge base; nothing configured -> no search.
            return ['results' => []];
        }
        $datasets = $bconf['datasets'];

        // Optional course scoping via document metadata (only meaningful inside a real course; on
        // site/dashboard pages there is no current course, so the whole KB is searched).
        $metacondition = null;
        if ($bconf['scope'] === 'course') {
            $coursecontext = $context->get_course_context(false);
            $courseid = $coursecontext ? (int) $coursecontext->instanceid : 0;
            if ($courseid > SITEID) {
                $metacondition = [
                    'logic' => 'and',
                    'conditions' => [[
                        'name' => $bconf['coursefield'],
                        'comparison_operator' => 'in',
                        'value' => [(string) $courseid],
                    ]],
                ];
            }
        }

        // Fewer but better results: request a candidate pool, then apply a relevance floor (a lower one
        // for images/media, which embed with low text similarity), a relevance "cliff" and a cap. An
        // optional rerank model reorders the candidates by a cross-encoder for much better precision.
        $minsim = $bconf['minsimilarity'];
        $maxresults = $bconf['maxresults'];
        $cliffratio = $bconf['cliffratio'];
        // Server-side floor = the lower of the text/media floors, so images (low text similarity) are not
        // pre-dropped by RAGflow; the per-type floor is applied in PHP below.
        $opts = ['similarity_threshold' => min(self::MEDIA_MIN_SIMILARITY, $minsim)];
        // Hybrid semantic/keyword balance: a higher vector weight than RAGflow's keyword-heavy default so
        // sentence-form questions match by meaning (see the block's "Semantic weight" setting).
        $opts['vector_similarity_weight'] = $bconf['vectorweight'];
        if ($bconf['rerankmodel'] !== '') {
            $opts['rerank_id'] = $bconf['rerankmodel'];
            $opts['top_k'] = self::RERANK_TOPK;
        }
        // Candidate pool large enough to survive dedup + filtering for the configured result cap.
        $pagesize = max(self::CANDIDATES, ($maxresults + self::MAX_MEDIA) * 3);
        // RAGflow refuses a single retrieval across datasets that use different embedding models
        // ("Datasets use different embedding models."), so query each dataset separately and merge by
        // relevance. A single dataset is the common case and stays one call.
        if (count($datasets) === 1) {
            $chunks = \aiprovider_ragflow\helper::retrieve(
                $base,
                $key,
                $query,
                $datasets,
                $pagesize,
                $metacondition,
                $opts
            );
        } else {
            $chunks = [];
            foreach ($datasets as $dsid) {
                $hits = \aiprovider_ragflow\helper::retrieve(
                    $base,
                    $key,
                    $query,
                    [$dsid],
                    $pagesize,
                    $metacondition,
                    $opts
                );
                foreach ($hits as $c) {
                    $chunks[] = $c;
                }
            }
        }
        // Rank, dedup by document, apply the per-type floor + cliff + cap (pure, unit-tested).
        $results = self::rank_and_group($chunks, $minsim, $maxresults, $cliffratio);

        // An empty result may just mean "no match", but it also masks a knowledge base that was deleted in
        // RAGflow (retrieval on a gone dataset yields nothing). Only when the result is empty do we ask the
        // shared checker whether that is the reason, so a match-having search pays nothing for this.
        $notice = empty($results) ? self::empty_result_notice($datasets) : '';

        self::log_usage($context, (int) $USER->id, count($results), $starttime);

        // Optional per-feature debug capture (content) – only when the dashboard is installed and this
        // component's debug mode is on there. Pushed directly (never via events, to avoid log-store leak).
        if (class_exists('\local_ragflowdashboard\api')) {
            try {
                $names = [];
                foreach ($results as $r) {
                    $names[] = $r['name'];
                }
                \local_ragflowdashboard\api::debug_capture(
                    'block_ragflowsearch',
                    'search',
                    true,
                    (int) $USER->id,
                    $context,
                    $query,
                    implode('; ', $names)
                );
            } catch (\Throwable $e) {
                debugging('aiprovider_ragflow: debug capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return ['results' => $results, 'notice' => $notice];
    }

    /**
     * When a search returns nothing, the message explaining an empty result if (and only if) the cause is a
     * knowledge base that no longer exists in RAGflow; '' otherwise (a genuine "no match", or a mere
     * connection problem, which must not be reported as a deleted KB). Capability-aware: privileged viewers
     * (aiprovider/ragflow:viewerrordetails) get the actionable id, everyone else a neutral line.
     *
     * @param array $datasets The configured dataset ids.
     * @return string
     */
    protected static function empty_result_notice(array $datasets): string {
        global $USER;
        $checker = \aiprovider_ragflow\local\health\checker::instance();
        $priv = has_capability('aiprovider/ragflow:viewerrordetails', \context_system::instance(), (int) $USER->id);
        foreach ($datasets as $dsid) {
            $status = $checker->check_knowledge_base((string) $dsid);
            if ($status->state === \aiprovider_ragflow\local\health\reference_status::MISSING) {
                if ($priv) {
                    $short = \core_text::substr((string) $status->reference, 0, 8) . '…';
                    return get_string('error:kbmissing_detail', 'aiprovider_ragflow', $short);
                }
                return get_string('error:kbmissing', 'aiprovider_ragflow');
            }
        }
        return '';
    }

    /**
     * Emit a RAGflow search usage event (metrics only – no personal data). Consumed by the optional
     * RAGflow Dashboard; best-effort, must never break the search.
     *
     * @param \context $context
     * @param int $userid
     * @param int $resultcount Number of documents returned.
     * @param float $starttime microtime(true) captured before the retrieval.
     * @return void
     */
    private static function log_usage(\context $context, int $userid, int $resultcount, float $starttime): void {
        \aiprovider_ragflow\usage_logger::record(
            $context,
            $userid,
            'block_ragflowsearch',
            'search',
            true,
            '',
            (int) round((microtime(true) - $starttime) * 1000),
            $resultcount
        );
    }

    /**
     * Credentials of the enabled RAGflow provider instance.
     *
     * @return array [providerid, baseurl, apikey]
     */
    private static function provider_credentials(): array {
        global $DB;
        $record = $DB->get_record_select(
            'ai_providers',
            'provider = :p AND enabled = 1',
            ['p' => \aiprovider_ragflow\provider::class],
            '*',
            IGNORE_MULTIPLE
        );
        if (!$record) {
            return [0, '', ''];
        }
        $conf = json_decode($record->config, true) ?: [];
        return [(int) $record->id, rtrim((string) ($conf['baseurl'] ?? ''), '/'), (string) ($conf['apikey'] ?? '')];
    }

    /**
     * Read a RAGflow search block instance's configuration (the admin's knowledge-base + scope choice).
     *
     * @param int $blockinstanceid
     * @return array {datasets: string[], scope: string, coursefield: string}
     */
    private static function block_config(int $blockinstanceid): array {
        global $DB;
        $default = [
            'datasets' => [],
            'scope' => 'all',
            'coursefield' => 'course_id',
            'rerankmodel' => '',
            'minsimilarity' => self::MIN_SIMILARITY,
            'maxresults' => self::MAX_RESULTS,
            'cliffratio' => self::CLIFF_RATIO,
            'vectorweight' => self::VECTOR_WEIGHT,
        ];
        if ($blockinstanceid <= 0) {
            return $default;
        }
        $record = $DB->get_record(
            'block_instances',
            ['id' => $blockinstanceid, 'blockname' => 'ragflowsearch']
        );
        if (!$record || $record->configdata === '') {
            return $default;
        }
        // Block config is a Moodle-written stdClass; restrict unserialize to stdClass so a tampered
        // configdata cannot trigger PHP object-injection gadget chains.
        $config = unserialize(base64_decode($record->configdata), ['allowed_classes' => ['stdClass']]);
        if (!is_object($config)) {
            return $default;
        }
        $datasets = [];
        foreach ((array) ($config->datasets ?? []) as $dsid) {
            $dsid = trim((string) $dsid);
            if ($dsid !== '') {
                $datasets[] = $dsid;
            }
        }
        $scope = ((string) ($config->scope ?? 'all') === 'course') ? 'course' : 'all';
        $coursefield = trim((string) ($config->coursefield ?? 'course_id'));
        // Quality knobs are admin-configurable per block; clamp to sane ranges and fall back to defaults
        // for empty / non-numeric values.
        $minsim = isset($config->minsimilarity) && is_numeric($config->minsimilarity)
            ? min(1.0, max(0.0, (float) $config->minsimilarity)) : self::MIN_SIMILARITY;
        $maxresults = isset($config->maxresults) && (int) $config->maxresults > 0
            ? min(50, (int) $config->maxresults) : self::MAX_RESULTS;
        $cliff = isset($config->cliffratio) && is_numeric($config->cliffratio)
            ? min(1.0, max(0.0, (float) $config->cliffratio)) : self::CLIFF_RATIO;
        $vectorweight = isset($config->vectorweight) && is_numeric($config->vectorweight)
            ? min(1.0, max(0.0, (float) $config->vectorweight)) : self::VECTOR_WEIGHT;
        return [
            'datasets' => array_values(array_unique($datasets)),
            'scope' => $scope,
            'coursefield' => ($coursefield !== '') ? $coursefield : 'course_id',
            'rerankmodel' => trim((string) ($config->rerankmodel ?? '')),
            'minsimilarity' => $minsim,
            'maxresults' => $maxresults,
            'cliffratio' => $cliff,
            'vectorweight' => $vectorweight,
        ];
    }

    /**
     * Whether a document is an image/media file (by extension) – these get the lower relevance floor and
     * a separate result group, since they embed with low text similarity.
     *
     * @param string $name Document file name.
     * @return bool
     */
    private static function is_media(string $name): bool {
        $ext = \core_text::strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $ext !== '' && in_array($ext, explode(',', self::MEDIA_EXTENSIONS), true);
    }

    /**
     * Turn the retrieved candidate chunks into the final result list: rank by relevance, keep one entry
     * per document, apply the per-type relevance floor (a lower one for images/media), then the relevance
     * "cliff" + result cap on the text results, and append the capped image/media group. Pure – no RAGflow
     * call – so it is unit-testable.
     *
     * @param array $chunks RAGflow retrieval chunks (objects with similarity, document ids and content).
     * @param float $minsim Minimum relevance for a text document to be kept.
     * @param int $maxresults Maximum text documents shown.
     * @param float $cliffratio Keep text only while its score stays within this fraction of the top (0 = off).
     * @return array Result rows: name, snippet, similarity, url, dataset, document, ismedia.
     */
    protected static function rank_and_group(array $chunks, float $minsim, int $maxresults, float $cliffratio): array {
        usort($chunks, function ($a, $b) {
            return ((float) ($b->similarity ?? 0)) <=> ((float) ($a->similarity ?? 0));
        });
        $seen = [];
        $text = [];
        $media = [];
        foreach ($chunks as $ch) {
            $docid = (string) ($ch->document_id ?? '');
            if ($docid !== '' && isset($seen[$docid])) {
                continue;
            }
            $seen[$docid] = true;
            $name = (string) ($ch->document_keyword ?? ($ch->docnm_kwd ?? ''));
            $sim = (float) ($ch->similarity ?? 0);
            $ismedia = self::is_media($name);
            // Per-type relevance floor: an image/media document survives a lower score than text.
            if ($sim < ($ismedia ? self::MEDIA_MIN_SIMILARITY : $minsim)) {
                continue;
            }
            $snippet = trim(preg_replace('/\s+/', ' ', (string) ($ch->content ?? ($ch->content_with_weight ?? ''))));
            if (\core_text::strlen($snippet) > 220) {
                $snippet = \core_text::substr($snippet, 0, 220) . '…';
            }
            $entry = [
                'name' => ($name !== '') ? $name : $docid,
                'snippet' => $snippet,
                'similarity' => round($sim, 2),
                // The download URL is minted on click (aiprovider_ragflow_download_url) so the signed token
                // lives only seconds; the page carries just the dataset+document ids.
                'url' => '',
                'dataset' => (string) ($ch->dataset_id ?? ($ch->kb_id ?? '')),
                'document' => $docid,
                'ismedia' => $ismedia,
            ];
            if ($ismedia) {
                $media[] = $entry;
            } else {
                $text[] = $entry;
            }
        }
        // Relevance "cliff": keep text results only while they stay reasonably close to the best hit, and
        // cap the count – so a query with two strong matches returns two, not a padded list of weak ones.
        $topsim = $text ? $text[0]['similarity'] : 0.0;
        $results = [];
        foreach ($text as $r) {
            $belowcliff = $cliffratio > 0 && $topsim > 0 && $r['similarity'] < $topsim * $cliffratio;
            if (count($results) >= $maxresults || $belowcliff) {
                break;
            }
            $results[] = $r;
        }
        // Image/media documents form their own capped group after the text results.
        foreach (array_slice($media, 0, self::MAX_MEDIA) as $r) {
            $results[] = $r;
        }
        return $results;
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            // Name/snippet are raw document content (may contain markup/special chars); PARAM_TEXT would
            // make the return-value validator reject any value clean_param() alters ("Invalid response
            // value detected"). They are HTML-escaped at render (Mustache {{ }}), so PARAM_RAW is safe.
            'results' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_RAW, 'Document name.'),
                'snippet' => new external_value(PARAM_RAW, 'Matching text snippet.'),
                'similarity' => new external_value(PARAM_FLOAT, 'Relevance score (0..1).'),
                'url' => new external_value(PARAM_RAW, 'Direct link to the document (empty for proxy documents).'),
                'dataset' => new external_value(PARAM_RAW, 'RAGflow dataset id for on-click proxy download.', VALUE_DEFAULT, ''),
                'document' => new external_value(PARAM_RAW, 'RAGflow document id for on-click proxy download.', VALUE_DEFAULT, ''),
                'ismedia' => new external_value(PARAM_BOOL, 'Image/media document.', VALUE_DEFAULT, false),
            ])),
            'notice' => new external_value(
                PARAM_TEXT,
                'A short reason shown instead of "no results" when the empty result is explained (e.g. the '
                    . 'configured knowledge base no longer exists in RAGflow). Empty otherwise.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }
}
