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

/**
 * Shared, component-driven chat engine. Each RAGflow feature plugin (Helpdesk, Tutor) owns its own
 * configuration (stored under its component) and calls this engine, which talks to RAGflow directly via
 * {@see helper} using the provider instance's credentials. It handles the short-term RAGflow session,
 * long-term memory (recall + store), source formatting and a lightweight per-user rate-limit guard.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_engine {
    /** @var string Delimiter opening the injected long-term-memory block (stripped from the transcript). */
    const MEM_OPEN = '[[MEMORY]]';

    /** @var string Delimiter closing the injected long-term-memory block. */
    const MEM_CLOSE = '[[/MEMORY]]';

    /** @var int Max chat requests per user per minute (lightweight guard). */
    const RATE_PER_MINUTE = 20;

    /**
     * Resolve the effective configuration for a component (feature plugin) plus the provider credentials.
     * Returns null when the provider is not configured or the component has no chat assistant.
     *
     * @param string $component Calling component, e.g. 'aiplacement_ragflowhelpdesk'.
     * @return \stdClass|null {providerid, base, key, chatid, greeting, sessionmemory, memoryid, longterm,
     *                         includesources, serveviaproxy, systeminstruction}
     */
    public static function config(string $component): ?\stdClass {
        global $DB;
        $prov = $DB->get_record_select(
            'ai_providers',
            'provider = :p AND enabled = 1',
            ['p' => provider::class],
            '*',
            IGNORE_MULTIPLE
        );
        if (!$prov) {
            return null;
        }
        $conf = json_decode($prov->config, true) ?: [];
        $base = rtrim((string) ($conf['baseurl'] ?? ''), '/');
        $key = (string) ($conf['apikey'] ?? '');
        $chatid = trim((string) get_config($component, 'chatid'));
        if ($base === '' || $key === '' || $chatid === '') {
            return null;
        }
        $memoryid = trim((string) get_config($component, 'memoryid'));
        return (object) [
            'providerid' => (int) $prov->id,
            'component' => $component,
            'base' => $base,
            'key' => $key,
            'chatid' => $chatid,
            'greeting' => (string) get_config($component, 'greeting'),
            'sessionmemory' => (bool) get_config($component, 'sessionmemory'),
            'memoryid' => $memoryid,
            'longterm' => $memoryid !== '' && (bool) get_config($component, 'longterm'),
            'includesources' => (bool) get_config($component, 'includesources'),
            'serveviaproxy' => (bool) get_config($component, 'serveviaproxy'),
            'systeminstruction' => (string) get_config($component, 'systeminstruction'),
        ];
    }

    /**
     * Resolve the configuration of a RAGflow chat **block instance** (e.g. the Tutor block) plus the
     * provider credentials, from the block's own per-instance settings. Returns null when the provider
     * is not configured or the block has no chat assistant selected.
     *
     * @param int $blockinstanceid
     * @return \stdClass|null {providerid, base, key, chatid, kbid, greeting, systeminstruction,
     *                         datasource, coursemetadatafield, includesources, serveviaproxy, extraparams}
     */
    public static function config_from_block(int $blockinstanceid): ?\stdClass {
        global $DB;
        if ($blockinstanceid <= 0) {
            return null;
        }
        $prov = $DB->get_record_select(
            'ai_providers',
            'provider = :p AND enabled = 1',
            ['p' => provider::class],
            '*',
            IGNORE_MULTIPLE
        );
        if (!$prov) {
            return null;
        }
        $conf = json_decode($prov->config, true) ?: [];
        $base = rtrim((string) ($conf['baseurl'] ?? ''), '/');
        $key = (string) ($conf['apikey'] ?? '');
        if ($base === '' || $key === '') {
            return null;
        }
        $record = $DB->get_record('block_instances', ['id' => $blockinstanceid, 'blockname' => 'ragflowtutor']);
        if (!$record || $record->configdata === '') {
            return null;
        }
        // Block config is a Moodle-written stdClass (base64(serialize(...))); restrict unserialize to
        // stdClass so a tampered configdata cannot trigger PHP object-injection gadget chains.
        $c = unserialize(base64_decode($record->configdata), ['allowed_classes' => ['stdClass']]);
        if (!is_object($c)) {
            return null;
        }
        $chatid = trim((string) ($c->chatid ?? ''));
        if ($chatid === '') {
            return null;
        }
        return (object) [
            'providerid' => (int) $prov->id,
            'base' => $base,
            'key' => $key,
            'chatid' => $chatid,
            'kbid' => trim((string) ($c->kbid ?? '')),
            'greeting' => (string) ($c->greeting ?? ''),
            'systeminstruction' => (string) ($c->systeminstruction ?? ''),
            'datasource' => (string) ($c->datasource ?? 'thismoodle'),
            'coursemetadatafield' => trim((string) ($c->coursemetadatafield ?? 'course_id')) ?: 'course_id',
            'includesources' => !empty($c->includesources),
            'serveviaproxy' => !empty($c->serveviaproxy),
            'extraparams' => (string) ($c->extraparams ?? ''),
        ];
    }

    /**
     * The per-user memory scope key for a component (RAGflow overrides user_id with the tenant, so
     * per-user separation is done via the session_id).
     *
     * @param string $component
     * @param int $userid
     * @return string
     */
    protected static function scope_key(string $component, int $userid): string {
        return $component . '-' . $userid;
    }

    /**
     * Classify a raw failure cause into a coarse, stable error type for usage analytics (no localisation,
     * no personal data). Used by the usage events consumed by the RAGflow Dashboard.
     *
     * @param string $detail The technical cause (e.g. from openai_complete()'s out-param).
     * @return string One of: embedding_contextwindow, embedding, http_5xx, http_4xx, network, unexpected.
     */
    protected static function classify_error(string $detail): string {
        $d = \core_text::strtolower($detail);
        if (strpos($d, 'context') !== false && strpos($d, 'token') !== false) {
            return 'embedding_contextwindow';
        }
        if (strpos($d, 'embedding') !== false) {
            return 'embedding';
        }
        if (strpos($d, 'http 5') !== false) {
            return 'http_5xx';
        }
        if (strpos($d, 'http 4') !== false) {
            return 'http_4xx';
        }
        if (strpos($d, 'exception') !== false || strpos($d, 'timeout') !== false) {
            return 'network';
        }
        return 'unexpected';
    }

    /**
     * A directive asking the assistant to answer in the user's current Moodle language. RAGflow's chat
     * completions ignore a request system message (the assistant's own prompt governs), so callers fold
     * this into the user message. Uses current_language() – the effective language, i.e. the user's
     * profile language, or a course-forced language when a course forces one. English name + ISO code so
     * the model reliably identifies the target language.
     *
     * @return string
     */
    protected static function language_directive(): string {
        $code = current_language();
        $name = get_string('thislanguageint', 'langconfig');
        if (trim($name) === '' || strpos($name, '[[') !== false) {
            $name = $code;
        }
        return 'Please write your answer in ' . $name . ' (language code: ' . $code . ').';
    }

    /**
     * Lightweight per-user rate-limit guard (replaces the core AI subsystem's limits, which no longer
     * apply since the chat runs outside a core action). Returns true if the request is allowed.
     *
     * @param int $userid
     * @return bool
     */
    protected static function rate_ok(int $userid): bool {
        $cache = \cache::make('aiprovider_ragflow', 'chatrate');
        $now = time();
        $bucket = (array) ($cache->get($userid) ?: []);
        $bucket = array_values(array_filter($bucket, fn($t) => $t > $now - 60));
        if (count($bucket) >= self::RATE_PER_MINUTE) {
            return false;
        }
        $bucket[] = $now;
        $cache->set($userid, $bucket);
        return true;
    }

    /**
     * If the configured assistant no longer exists in RAGflow, the ready-to-return error array for a chat
     * request; otherwise null (usable, degraded or merely unverified — all of which are left to proceed).
     * The single {@see \aiprovider_ragflow\local\health\checker} decides; the message is capability-aware:
     * privileged viewers (aiprovider/ragflow:viewerrordetails) get the actionable id + fix, everyone else a
     * neutral "temporarily unavailable, ask your administrator" line with no server internals.
     *
     * @param string $chatid The configured assistant id.
     * @param int $userid The requesting user (for the capability check).
     * @return array|null The error array (success=false, …) to return, or null to proceed.
     */
    protected static function reference_missing_error(string $chatid, int $userid): ?array {
        if (trim($chatid) === '') {
            return null;
        }
        $status = \aiprovider_ragflow\local\health\checker::instance()->check_assistant($chatid);
        if ($status->state !== \aiprovider_ragflow\local\health\reference_status::MISSING) {
            return null;
        }
        if (has_capability('aiprovider/ragflow:viewerrordetails', \context_system::instance(), $userid)) {
            $short = \core_text::substr((string) $status->reference, 0, 8) . '…';
            $message = get_string('error:referencemissing_detail', 'aiprovider_ragflow', $short);
        } else {
            $message = get_string('error:referencemissing', 'aiprovider_ragflow');
        }
        return ['success' => false, 'generatedcontent' => '',
            'errormessage' => $message, 'errortype' => 'referencemissing', 'sources' => []];
    }

    /**
     * Generate a chat answer for a component + user + question.
     *
     * @param string $component Calling component.
     * @param int $userid
     * @param string $prompttext The user's message (may already fold client-side history for stateless mode).
     * @return array {success, generatedcontent, errormessage}
     */
    public static function generate(string $component, int $userid, string $prompttext): array {
        $cfg = self::config($component);
        if ($cfg === null) {
            return ['success' => false, 'generatedcontent' => '',
                'errormessage' => get_string('error:notconfigured', 'aiprovider_ragflow'),
                'errortype' => 'notconfigured', 'sources' => []];
        }
        if (!self::rate_ok($userid)) {
            return ['success' => false, 'generatedcontent' => '',
                'errormessage' => get_string('error:ratelimited', 'aiprovider_ragflow'),
                'errortype' => 'ratelimited', 'sources' => []];
        }
        // The configured assistant may have been deleted in RAGflow. Catch that here with a clear message
        // instead of letting the completion fail into a generic "unexpected response" (never on a mere
        // connection problem: `unverified` is left to proceed and may well succeed).
        $missing = self::reference_missing_error($cfg->chatid, $userid);
        if ($missing !== null) {
            return $missing;
        }
        $original = (string) $prompttext;

        // Long-term memory is disabled per-user in private (incognito) mode.
        $longterm = $cfg->longterm && !get_user_preferences('aiprovider_ragflow_privatemode', 0, $userid);
        $scope = self::scope_key($component, $userid);

        // Recall: inject the user's relevant remembered facts into the question on every turn.
        $question = $original;
        if ($longterm) {
            $facts = helper::memory_search($cfg->base, $cfg->key, $cfg->memoryid, $scope, $original);
            if (!empty($facts)) {
                $question = get_string('memorypreamble', 'aiprovider_ragflow') . "\n"
                    . self::MEM_OPEN . "\n- " . implode("\n- ", $facts) . "\n" . self::MEM_CLOSE . "\n\n" . $original;
            }
        }

        // Answer in the user's Moodle language (folded in – RAGflow ignores a request system message).
        $question = self::language_directive() . "\n\n" . $question;

        // Ask RAGflow. With session memory: the stateful session endpoint; otherwise stateless completion.
        if ($cfg->sessionmemory) {
            $errordetail = '';
            $sid = session_store::get_or_create(
                $userid,
                $cfg->providerid,
                $cfg->chatid,
                $scope,
                $cfg->base,
                $cfg->key,
                $errordetail
            );
            if ($sid === '') {
                return ['success' => false, 'generatedcontent' => '',
                    'errormessage' => get_string('error:unexpectedresponse', 'aiprovider_ragflow'),
                    'errordetails' => $errordetail,
                    'errortype' => ($errordetail !== '') ? self::classify_error($errordetail) : 'session',
                    'sources' => []];
            }
            $data = helper::session_complete($cfg->base, $cfg->key, $cfg->chatid, $sid, $question, $errordetail);
            if ($data === null) {
                return ['success' => false, 'generatedcontent' => '',
                    'errormessage' => get_string('error:unexpectedresponse', 'aiprovider_ragflow'),
                    'errordetails' => $errordetail,
                    'errortype' => ($errordetail !== '') ? self::classify_error($errordetail) : 'ragflow',
                    'sources' => []];
            }
            $answer = (string) ($data->answer ?? '');
            $chunks = self::reference_chunks($data->reference ?? null);
            $tokens = self::usage_tokens($data->usage ?? null);
        } else {
            $errordetail = '';
            $result = helper::openai_complete(
                $cfg->base,
                $cfg->key,
                $cfg->chatid,
                $question,
                $cfg->systeminstruction,
                $cfg->includesources,
                [],
                $errordetail
            );
            if ($result === null) {
                return ['success' => false, 'generatedcontent' => '',
                    'errormessage' => get_string('error:unexpectedresponse', 'aiprovider_ragflow'),
                    'errordetails' => $errordetail, 'errortype' => self::classify_error($errordetail),
                    'sources' => []];
            }
            $answer = (string) ($result->content ?? '');
            $chunks = self::reference_chunks($result->reference ?? null);
            $tokens = self::usage_tokens($result->usage ?? null);
        }

        // With sources on, turn RAGflow's [ID:n] citations into numbered footnotes [1], [2], … and build
        // the matching numbered source list; otherwise just drop the bare markers. A "no relevant content"
        // reply shows no sources even if it cited a chunk (some assistants decline yet still cite one).
        if ($cfg->includesources && !self::is_no_hit_answer($answer)) {
            [$answer, $sources] = self::cited_sources(
                $answer,
                $chunks,
                $cfg->providerid,
                $userid,
                $cfg->serveviaproxy,
                $cfg->base,
                $cfg->key
            );
        } else {
            $answer = self::strip_markers($answer);
            $sources = [];
        }

        // Store the user's message for long-term memory (empty agent_response avoids KB-answer noise).
        if ($longterm && trim($original) !== '') {
            helper::memory_add(
                $cfg->base,
                $cfg->key,
                $cfg->memoryid,
                session_store::MEMORY_AGENT_ID,
                $scope,
                $original,
                ''
            );
        }

        return [
            'success' => true,
            'generatedcontent' => $answer,
            'errormessage' => '',
            'errortype' => '',
            'sources' => $sources,
            'providerid' => $cfg->providerid,
            'tokensprompt' => $tokens[0],
            'tokenscompletion' => $tokens[1],
            'tokenstotal' => $tokens[2],
        ];
    }

    /**
     * Generate a chat answer for a **block instance** (e.g. the Tutor block) + user + question. Stateless:
     * each turn is independent (the browser keeps the transcript), scoped to the block's knowledge base
     * and, for "this Moodle" sources, to the current course via a document metadata condition.
     *
     * @param int $blockinstanceid
     * @param int $userid
     * @param string $prompttext
     * @param int $courseid The current course id (0 outside a course); used for course scoping.
     * @return array {success, generatedcontent, errormessage, sources}
     */
    public static function generate_block(
        int $blockinstanceid,
        int $userid,
        string $prompttext,
        int $courseid
    ): array {
        $cfg = self::config_from_block($blockinstanceid);
        if ($cfg === null) {
            return ['success' => false, 'generatedcontent' => '',
                'errormessage' => get_string('error:notconfigured', 'aiprovider_ragflow'),
                'errortype' => 'notconfigured', 'sources' => []];
        }
        if (!self::rate_ok($userid)) {
            return ['success' => false, 'generatedcontent' => '',
                'errormessage' => get_string('error:ratelimited', 'aiprovider_ragflow'),
                'errortype' => 'ratelimited', 'sources' => []];
        }
        // A deleted assistant surfaces as a clear message here rather than a generic completion failure.
        $missing = self::reference_missing_error($cfg->chatid, $userid);
        if ($missing !== null) {
            return $missing;
        }

        // Auto-created KBs start empty; bind the dataset to the assistant once it has parsed content.
        if ($cfg->kbid !== '') {
            helper::ensure_kb_bound($cfg->base, $cfg->key, $cfg->chatid, $cfg->kbid);
        }

        // Build the knowledge-base metadata filter from the block's document source.
        $extrabody = self::block_metadata_extrabody($cfg, $courseid);
        // Merge any admin-provided extra JSON params.
        if (trim($cfg->extraparams) !== '') {
            $decoded = json_decode($cfg->extraparams, true);
            if (is_array($decoded)) {
                $extrabody = array_merge($decoded, $extrabody);
            }
        }

        // RAGflow's chats_openai endpoint IGNORES the request's system message (the assistant uses its
        // own prompt), so fold the block's system instruction into the user message instead – that is the
        // only reliable way to steer the assistant per request.
        $message = (string) $prompttext;
        if (trim($cfg->systeminstruction) !== '') {
            $message = trim($cfg->systeminstruction) . "\n\n" . $message;
        }
        // Answer in the user's Moodle language (prepended – RAGflow ignores a request system message).
        $message = self::language_directive() . "\n\n" . $message;

        $errordetail = '';
        $result = helper::openai_complete(
            $cfg->base,
            $cfg->key,
            $cfg->chatid,
            $message,
            '',
            $cfg->includesources,
            $extrabody,
            $errordetail
        );
        if ($result === null) {
            return ['success' => false, 'generatedcontent' => '',
                'errormessage' => get_string('error:unexpectedresponse', 'aiprovider_ragflow'),
                'errordetails' => $errordetail, 'errortype' => self::classify_error($errordetail),
                'sources' => []];
        }
        // Drop any ID-enumeration table the assistant wrote into the answer (separate from the inline
        // [ID:n] citation markers, which are handled below).
        $answer = self::strip_source_enumeration((string) ($result->content ?? ''));
        $tokens = self::usage_tokens($result->usage ?? null);

        $sources = [];
        if ($cfg->includesources && !self::is_no_hit_answer($answer)) {
            // Sources are strictly citation-driven: turn the model's own [ID:n] markers into numbered
            // footnotes [1], [2], … and list only the documents the answer actually cited. When the model
            // cites nothing – including a "nothing relevant found" reply – the Sources panel stays empty
            // rather than showing blindly retrieved, weakly-related documents. (Same behaviour as the
            // stateful generate() path.) A reply that declares no hit shows no sources even if it cited a
            // chunk – some assistants (e.g. still on RAGflow's default prompt) decline yet still cite one.
            [$answer, $sources] = self::cited_sources(
                $answer,
                self::reference_chunks($result->reference ?? null),
                $cfg->providerid,
                $userid,
                $cfg->serveviaproxy,
                $cfg->base,
                $cfg->key
            );
        } else {
            $answer = self::strip_markers($answer);
        }

        return [
            'success' => true,
            'generatedcontent' => $answer,
            'errormessage' => '',
            'errortype' => '',
            'sources' => $sources,
            'providerid' => $cfg->providerid,
            'tokensprompt' => $tokens[0],
            'tokenscompletion' => $tokens[1],
            'tokenstotal' => $tokens[2],
        ];
    }

    /**
     * Build the extra_body metadata condition for a block's document source: "this Moodle" scopes to the
     * current course (via the course metadata field) + this site; "external" gates on external_sharing.
     * Returns [] when nothing applies (no filter sent).
     *
     * @param \stdClass $cfg Block config from {@see config_from_block()}.
     * @param int $courseid
     * @return array
     */
    protected static function block_metadata_extrabody(\stdClass $cfg, int $courseid): array {
        global $CFG;
        // No metadata filter for "Whole knowledge base" (wholekb) or "This course" (thiscourse): both search
        // the assistant's whole KB. thiscourse is a dedicated, Moodle-managed course KB (it IS the scope and
        // offers file management); wholekb is any KB not populated from Moodle. A filter is pointless there –
        // block-uploaded / non-Moodle documents carry no course_id, and RAGflow drops documents that lack a
        // filtered field, which would hide them all. "This Moodle" (thismoodle): a shared site KB, filtered
        // to the current course by metadata. "External Moodle" (external): external_sharing gate.
        $conditions = [];
        if ($cfg->datasource === 'external') {
            $conditions[] = [
                'name' => 'external_sharing',
                'comparison_operator' => 'in',
                'value' => ['1'],
            ];
        } else if ($cfg->datasource === 'thismoodle' && $courseid > SITEID) {
            $conditions[] = [
                'name' => $cfg->coursemetadatafield,
                'comparison_operator' => 'in',
                'value' => [(string) $courseid],
            ];
            $conditions[] = [
                'name' => 'moodle_url',
                'comparison_operator' => 'in',
                'value' => [rtrim($CFG->wwwroot, '/')],
            ];
        }
        if (empty($conditions)) {
            return [];
        }
        return ['metadata_condition' => ['logic' => 'and', 'conditions' => $conditions]];
    }

    /**
     * Extract [prompt, completion, total] token counts from a RAGflow/OpenAI usage block (0s if absent).
     * Chat only – search/retrieval has no LLM generation and returns no usage.
     *
     * @param mixed $usage
     * @return int[]
     */
    protected static function usage_tokens($usage): array {
        if (!is_object($usage)) {
            return [0, 0, 0];
        }
        return [
            (int) ($usage->prompt_tokens ?? 0),
            (int) ($usage->completion_tokens ?? 0),
            (int) ($usage->total_tokens ?? 0),
        ];
    }

    /**
     * Normalise a RAGflow reference field into a flat list of chunk objects.
     *
     * @param mixed $reference
     * @return array
     */
    protected static function reference_chunks($reference): array {
        if (is_object($reference) && isset($reference->chunks) && is_array($reference->chunks)) {
            return array_values($reference->chunks);
        }
        if (is_array($reference)) {
            return array_values($reference);
        }
        return [];
    }

    /**
     * Whether an answer is a RAGflow "no relevant content found" reply. Sources must be suppressed for such
     * a reply even if it cites a chunk: some assistants (notably ones still on RAGflow's default prompt)
     * decline to answer yet still cite a chunk they merely looked at, so a citation is not proof the answer
     * used anything – a not-found answer must never show a source. Matches RAGflow's stock no-hit phrasings
     * (case-insensitive); our own clean assistant prompt declines without citing, so it is unaffected.
     *
     * @param string $content The model's answer text.
     * @return bool
     */
    protected static function is_no_hit_answer(string $content): bool {
        $needles = [
            'not found in the dataset',
            'not found in the knowledge base',
            'no relevant content was found',
        ];
        $haystack = \core_text::strtolower($content);
        foreach ($needles as $needle) {
            if (strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove bare [ID:n] markers from an answer that has no source list.
     *
     * @param string $content
     * @return string
     */
    protected static function strip_markers(string $content): string {
        return trim(preg_replace('/\s*\[ID:\d+\]/', '', $content));
    }

    /**
     * Remove the augmentations we prepend to a user's turn before sending it to RAGflow, so a restored
     * transcript shows the original question: the injected memory-facts block (everything up to and
     * including the MEMORY delimiter) and the leading "answer in language X" directive. If the memory
     * block is present it already covers the directive (which sits before it); the directive strip then
     * handles the no-memory case.
     *
     * @param string $content The stored user message.
     * @return string The original question.
     */
    protected static function strip_prompt_augmentation(string $content): string {
        if (strpos($content, self::MEM_CLOSE) !== false) {
            $content = preg_replace('/^.*?' . preg_quote(self::MEM_CLOSE, '/') . '\s*/s', '', $content);
        }
        return trim(preg_replace(
            '/^Please write your answer in .+? \(language code: [^)]+\)\.\s*/s',
            '',
            (string) $content
        ));
    }

    /**
     * Remove the knowledge-base entry enumeration that the RAGflow assistant sometimes writes into the
     * answer text – either a markdown table with an "ID" column or a list of "ID 0 …", "ID 1 …" lines
     * (the same document appearing twice when two of its chunks are used). This is driven by the
     * assistant's own RAGflow prompt (which cannot be changed on an existing assistant via the API), so
     * it is stripped here; the sources are shown in the separate panel instead. Conservative: only
     * ID-tables, ID-enumeration lines and a heading/separator left dangling directly above such removed
     * content are dropped; if that would remove everything, the original text is kept.
     *
     * @param string $content
     * @return string
     */
    protected static function strip_source_enumeration(string $content): string {
        if (trim($content) === '') {
            return $content;
        }
        $lines = preg_split('/\R/', $content);
        $count = count($lines);
        $remove = array_fill(0, $count, false);

        // Pass 1: ID-column markdown tables and "ID N …" enumeration lines.
        for ($i = 0; $i < $count; $i++) {
            if (self::is_table_row($lines[$i])) {
                $j = $i;
                while ($j < $count && self::is_table_row($lines[$j])) {
                    $j++;
                }
                $isidtable = false;
                for ($k = $i; $k < min($i + 2, $j); $k++) {
                    foreach (explode('|', trim($lines[$k], " \t|")) as $cell) {
                        if (strcasecmp(trim($cell), 'ID') === 0) {
                            $isidtable = true;
                            break 2;
                        }
                    }
                }
                if ($isidtable) {
                    for ($k = $i; $k < $j; $k++) {
                        $remove[$k] = true;
                    }
                }
                $i = $j - 1;
                continue;
            }
            if (preg_match('/^\s*(?:[-*]\s*)?ID[ :]?\d+\b/i', $lines[$i])) {
                $remove[$i] = true;
            }
        }

        // Pass 2 (bottom-up so removals propagate upward): a markdown heading, a bold-only line or a
        // "---" separator immediately above removed content is a dangling label for the removed list.
        for ($i = $count - 1; $i >= 0; $i--) {
            if ($remove[$i]) {
                continue;
            }
            $trim = trim($lines[$i]);
            $isheading = ($trim !== '' && (preg_match('/^#{1,6}\s/', $trim) || preg_match('/^\*\*.+\*\*$/', $trim)))
                || preg_match('/^-{3,}$/', $trim);
            if (!$isheading) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && trim($lines[$j]) === '') {
                $j++;
            }
            if ($j < $count && $remove[$j]) {
                $remove[$i] = true;
            }
        }

        $kept = [];
        for ($i = 0; $i < $count; $i++) {
            if (!$remove[$i]) {
                $kept[] = $lines[$i];
            }
        }
        $result = preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept));
        // Drop separator lines ("---") left stranded at the very top or bottom, then collapse blanks.
        $result = preg_replace('/\A(?:\s*-{3,}\s*(?:\n|$))+/', '', $result);
        $result = preg_replace('/(?:(?:\n|^)\s*-{3,}\s*)+\z/', '', $result);
        $result = trim(preg_replace("/\n{3,}/", "\n\n", $result));
        // Never strip the whole answer away.
        return $result === '' ? trim($content) : $result;
    }

    /**
     * True if a line is a markdown table row (starts and ends with a pipe).
     *
     * @param string $line
     * @return bool
     */
    protected static function is_table_row(string $line): bool {
        return (bool) preg_match('/^\s*\|.*\|\s*$/', $line);
    }

    /**
     * Resolve a single RAGflow chunk to a source entry, or null if it should be dropped (below the
     * similarity threshold, or without a usable name/link). The returned array carries an internal
     * 'dedupkey' the caller uses to deduplicate + number – strip it before returning to the client.
     *
     * @param mixed $ch A RAGflow reference/retrieval chunk.
     * @param int $providerid
     * @param int $userid
     * @param bool $proxy
     * @param string $base
     * @param string $key
     * @param float $minsim Minimum chunk similarity to include.
     * @param array $caches Reference: lazy caches ['dsurls' => [dsid => [docid => url]], 'dsnames' => map].
     * @return array|null ['kb','name','url','dataset','document','dedupkey'] or null.
     */
    protected static function chunk_to_source(
        $ch,
        int $providerid,
        int $userid,
        bool $proxy,
        string $base,
        string $key,
        float $minsim,
        array &$caches
    ): ?array {
        if (!is_object($ch)) {
            return null;
        }
        $sim = isset($ch->similarity) ? (float) $ch->similarity : null;
        if ($sim !== null && $sim < $minsim) {
            return null;
        }
        $name = (string) ($ch->document_name ?? ($ch->docnm_kwd ?? ($ch->document_keyword ?? '')));
        $docid = (string) ($ch->document_id ?? '');
        $dsid = (string) ($ch->dataset_id ?? ($ch->kb_id ?? ''));
        $url = '';
        // Proxy documents are downloaded through the signed proxy – the URL is NOT pre-signed here but
        // minted on click (aiprovider_ragflow_download_url), so the token lives only seconds. Non-proxy
        // sources are a direct Moodle activity / external link.
        $isproxydoc = ($proxy && $dsid !== '' && $docid !== '');
        if ($proxy) {
            $url = '';
        } else if (isset($ch->document_metadata) && is_object($ch->document_metadata)) {
            // Chat-reference chunks carry the document metadata inline.
            $url = helper::metadata_link($ch->document_metadata);
        } else if ($dsid !== '' && $docid !== '') {
            // Retrieval chunks have no inline metadata: resolve the link from the dataset's documents.
            if (!isset($caches['dsurls'][$dsid])) {
                $caches['dsurls'][$dsid] = helper::dataset_document_urls($providerid, $base, $key, $dsid);
            }
            $url = $caches['dsurls'][$dsid][$docid] ?? '';
        }
        // Source URLs from RAGflow document metadata are untrusted; strip anything that is not a safe
        // http(s)/relative URL (clean_param(PARAM_URL) returns '' for e.g. a javascript: scheme).
        $url = clean_param($url, PARAM_URL);
        if ($name === '' && $url === '' && !$isproxydoc) {
            return null;
        }
        // Show the knowledge-base name + the document's file name (not its full RAGflow path).
        if ($caches['dsnames'] === null) {
            $caches['dsnames'] = helper::datasets_cached($providerid, $base, $key);
        }
        $parts = explode('/', $name);
        $filename = trim((string) end($parts));
        return [
            'kb' => (string) ($caches['dsnames'][$dsid] ?? ''),
            'name' => ($filename !== '') ? $filename : ($url !== '' ? $url : $docid),
            'url' => $url,
            'dataset' => $isproxydoc ? $dsid : '',
            'document' => $isproxydoc ? $docid : '',
            'dedupkey' => ($docid !== '') ? $docid : ($name . '|' . $url),
        ];
    }

    /**
     * Build the numbered source list from the chunks the answer actually cites (RAGflow's [ID:n] markers).
     * The inline markers are removed from the text; instead the cited sources are collected and a single
     * reference line – a localised "Sources:" label followed by sentinels [[1]] [[2]] … (first-cited order)
     * – is appended at the end of the answer (the client renders the sentinels per answer as [1.1] [1.2] …).
     * Only cited, resolvable chunks become sources.
     * Used wherever RAGflow returns reliable inline citations (the Helpdesk/placement and the Tutor block
     * when the model cites).
     *
     * @param string $answer The answer text with [ID:n] markers (n = index into $chunks).
     * @param array $chunks reference_chunks() output.
     * @param int $providerid
     * @param int $userid
     * @param bool $proxy
     * @param string $base
     * @param string $key
     * @return array [string $rewrittenanswer, array $sources] – each source carries a 1-based 'number'.
     */
    protected static function cited_sources(
        string $answer,
        array $chunks,
        int $providerid,
        int $userid,
        bool $proxy,
        string $base,
        string $key
    ): array {
        $number = [];
        $sources = [];
        $caches = ['dsurls' => [], 'dsnames' => null];
        $rewritten = preg_replace_callback('/(\s*)\[ID:(\d+)\]/', function ($m) use (
            $chunks,
            $providerid,
            $userid,
            $proxy,
            $base,
            $key,
            &$number,
            &$sources,
            &$caches
        ) {
            // The inline [ID:n] marker itself is always removed from the text (with its leading
            // whitespace) – the citation reference is shown once, on its own line at the end (see below),
            // not scattered through the answer. The callback's job here is only to resolve + number the
            // cited chunks into the sources list.
            $ch = $chunks[(int) $m[2]] ?? null;
            if ($ch === null) {
                return '';
            }
            // No similarity floor for cited chunks: the model's own citation IS the relevance signal, so a
            // cited chunk always becomes a source. A similarity floor would wrongly drop legitimately-cited
            // but low-text-similarity documents – images especially (e.g. a photo cited for "what does X
            // look like?" embeds with low text similarity yet is exactly the right source).
            $src = self::chunk_to_source(
                $ch,
                $providerid,
                $userid,
                $proxy,
                $base,
                $key,
                0.0,
                $caches
            );
            if ($src === null) {
                return '';
            }
            $dedupkey = $src['dedupkey'];
            if (!isset($number[$dedupkey])) {
                $number[$dedupkey] = count($sources) + 1;
                unset($src['dedupkey']);
                $src['number'] = $number[$dedupkey];
                $sources[] = $src;
            }
            return '';
        }, $answer);
        $rewritten = trim((string) $rewritten);
        if (!empty($sources)) {
            // Append the citation reference once, on its own line at the end of the answer. Each cited
            // source is a distinctive [[n]] sentinel (not a bare [n], which the model itself may write in
            // lists); the client rewrites them per answer to [<answer>.<n>] (e.g. [1.1] [1.2]) so the
            // references stay unambiguous across the stacked, multi-answer Sources panel.
            $markers = [];
            foreach ($sources as $s) {
                $markers[] = '[[' . $s['number'] . ']]';
            }
            // Prefix the reference line with the localised "Sources:" label (reused from the panel heading).
            $reference = get_string('sourcesheading', 'aiprovider_ragflow') . ' ' . implode(' ', $markers);
            $rewritten = ($rewritten !== '' ? $rewritten . "\n\n" : '') . $reference;
        }
        return [$rewritten, $sources];
    }

    /**
     * Reset the short-term conversation for a component + user (new RAGflow session next turn).
     *
     * @param string $component
     * @param int $userid
     * @return void
     */
    public static function reset(string $component, int $userid): void {
        $cfg = self::config($component);
        if ($cfg === null || !$cfg->sessionmemory) {
            return;
        }
        session_store::reset(
            $userid,
            $cfg->providerid,
            $cfg->chatid,
            self::scope_key($component, $userid),
            $cfg->base,
            $cfg->key
        );
    }

    /**
     * Restore the stored transcript of the current conversation (for resume on reload).
     *
     * @param string $component
     * @param int $userid
     * @return array List of {role, content}.
     */
    public static function history(string $component, int $userid): array {
        $cfg = self::config($component);
        if ($cfg === null || !$cfg->sessionmemory) {
            return [];
        }
        $scope = self::scope_key($component, $userid);
        $sid = session_store::existing_session_id($userid, $cfg->providerid, $cfg->chatid, $scope);
        if ($sid === '') {
            return [];
        }
        $raw = helper::get_session_messages($cfg->base, $cfg->key, $cfg->chatid, $sid);
        $messages = [];
        $first = true;
        foreach ($raw as $m) {
            $role = (string) ($m->role ?? '');
            $ct = (string) ($m->content ?? '');
            if ($first && $role === 'assistant') {
                $first = false;
                continue;
            }
            $first = false;
            if (($role !== 'user' && $role !== 'assistant') || $ct === '') {
                continue;
            }
            if ($role === 'assistant') {
                // Sanitise the stored model answer before it is replayed into the chat drawer's HTML —
                // the same server-side clean_text the live path applies (see external\chat_generate), so
                // the history-restore path can never render unsanitised RAGflow output.
                $ct = clean_text(trim(preg_replace('/\s*\[ID:\d+\]/', '', $ct)), FORMAT_HTML);
            }
            if ($role === 'user') {
                $ct = self::strip_prompt_augmentation($ct);
            }
            if ($ct !== '') {
                $messages[] = ['role' => $role, 'content' => $ct];
            }
        }
        return $messages;
    }

    /**
     * Forget the user's long-term memory for a component and end the current conversation.
     *
     * @param string $component
     * @param int $userid
     * @return bool
     */
    public static function forget(string $component, int $userid): bool {
        $cfg = self::config($component);
        if ($cfg === null || !$cfg->longterm) {
            return false;
        }
        $scope = self::scope_key($component, $userid);
        helper::memory_forget_session($cfg->base, $cfg->key, $cfg->memoryid, $scope);
        session_store::reset($userid, $cfg->providerid, $cfg->chatid, $scope, $cfg->base, $cfg->key);
        return true;
    }
}
