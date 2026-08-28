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
 * Shared chat endpoint: send one turn to the core generate_text action (RAGflow), used by every
 * RAGflow placement (Tutor, Helpdesk). The core action is stateless, so multi-turn context is
 * supplied by the client inside prompttext; the page context id scopes the knowledge base and
 * selects the Tutor/Helpdesk assistant inside the provider.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_generate extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'The context id of the page.'),
            'prompttext' => new external_value(PARAM_RAW, 'The user message, optionally prefixed with prior turns.'),
            'component' => new external_value(
                PARAM_COMPONENT,
                'The calling placement component (placement path), e.g. aiplacement_ragflowhelpdesk.',
                VALUE_DEFAULT,
                ''
            ),
            'blockinstanceid' => new external_value(
                PARAM_INT,
                'The RAGflow chat block instance (block path) whose config drives the chat.',
                VALUE_DEFAULT,
                0
            ),
            'question' => new external_value(
                PARAM_RAW,
                'The raw latest user question (without folded history), used for source retrieval.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Run the request and return the answer.
     *
     * @param int $contextid
     * @param string $prompttext
     * @param string $component
     * @param int $blockinstanceid
     * @param string $question
     * @return array
     */
    public static function execute(
        int $contextid,
        string $prompttext,
        string $component = '',
        int $blockinstanceid = 0,
        string $question = ''
    ): array {
        global $USER, $DB;

        [
            'contextid' => $contextid,
            'prompttext' => $prompttext,
            'component' => $component,
            'blockinstanceid' => $blockinstanceid,
            'question' => $question,
        ] = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'prompttext' => $prompttext,
            'component' => $component,
            'blockinstanceid' => $blockinstanceid,
            'question' => $question,
        ]);

        $context = \context::instance_by_id($contextid);
        self::validate_context($context);
        require_login(null, false);

        $starttime = microtime(true);
        if ($blockinstanceid > 0) {
            // Block path: enforce the block's own :use capability (derived from its blockname), then run
            // the stateless block chat scoped to the current course.
            $record = $DB->get_record('block_instances', ['id' => $blockinstanceid], 'id, blockname');
            if (!$record) {
                throw new \invalid_parameter_exception('unknown block instance.');
            }
            require_capability('block/' . $record->blockname . ':use', $context);
            $eventcomponent = 'block_' . $record->blockname;
            $coursecontext = $context->get_course_context(false);
            $courseid = $coursecontext ? (int) $coursecontext->instanceid : 0;
            $result = \aiprovider_ragflow\chat_engine::generate_block(
                $blockinstanceid,
                (int) $USER->id,
                $prompttext,
                $courseid
            );
        } else {
            // Placement path: enforce the calling placement's capability (aiplacement/<name>:use).
            if (strpos($component, 'aiplacement_') !== 0) {
                throw new \invalid_parameter_exception('component must be an aiplacement plugin.');
            }
            require_capability('aiplacement/' . substr($component, strlen('aiplacement_')) . ':use', $context);
            $eventcomponent = $component;
            // The chat runs on the component's own config via the shared engine (RAGflow directly).
            $result = \aiprovider_ragflow\chat_engine::generate($component, (int) $USER->id, $prompttext);
        }

        // Emit a usage event (metrics only – no personal data) for the RAGflow Dashboard / logs.
        self::log_usage($context, (int) $USER->id, $eventcomponent, $result, $starttime);

        // Optional per-feature debug capture (content) – only when the dashboard is installed AND this
        // component's debug mode is enabled there. Content never travels via events (it would leak into the
        // standard log store), so it is pushed directly to the dashboard-owned, admin-only debug table.
        if (class_exists('\local_ragflowdashboard\api')) {
            try {
                $success = !empty($result['success']);
                $debugresponse = $success
                    ? (string) ($result['generatedcontent'] ?? '')
                    : (string) ($result['errordetails'] ?? ($result['errormessage'] ?? ''));
                \local_ragflowdashboard\api::debug_capture(
                    $eventcomponent,
                    'chat',
                    $success,
                    (int) $USER->id,
                    $context,
                    ($question !== '') ? $question : $prompttext,
                    $debugresponse
                );
            } catch (\Throwable $e) {
                debugging('aiprovider_ragflow: debug capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        if (empty($result['success'])) {
            // The technical failure cause (e.g. a RAGflow internal/embedding error) can reveal server-side
            // internals, so it is returned only to holders of aiprovider/ragflow:viewerrordetails (site
            // admins always qualify) – enforced here, server-side, so a user without the capability never
            // receives it in the response (not merely hidden in the UI, so it cannot be read via the
            // browser's network tools).
            $mayseedetails = is_siteadmin()
                || has_capability('aiprovider/ragflow:viewerrordetails', $context);
            $errordetails = $mayseedetails ? (string) ($result['errordetails'] ?? '') : '';
            return ['success' => false, 'generatedcontent' => '', 'errormessage' => (string) $result['errormessage'],
                'errordetails' => $errordetails, 'sources' => []];
        }
        // The source documents behind the answer (shown in the drawer's persistent Sources panel).
        $sources = [];
        foreach (($result['sources'] ?? []) as $s) {
            $sources[] = [
                'number' => (int) ($s['number'] ?? 0),
                'kb' => (string) ($s['kb'] ?? ''),
                'name' => (string) ($s['name'] ?? ''),
                'url' => (string) ($s['url'] ?? ''),
                'dataset' => (string) ($s['dataset'] ?? ''),
                'document' => (string) ($s['document'] ?? ''),
            ];
        }
        // Sanitise the model output (strip scripts/dangerous attributes; keep safe source anchors).
        return [
            'success' => true,
            'generatedcontent' => clean_text((string) $result['generatedcontent'], FORMAT_HTML),
            'errormessage' => '',
            'errordetails' => '',
            'sources' => $sources,
        ];
    }

    /**
     * Emit a RAGflow chat usage event (metrics only – no personal data). Consumed by the optional RAGflow
     * Dashboard for stats/logs; a failure here must never break the chat, so it is best-effort.
     *
     * @param \context $context
     * @param int $userid
     * @param string $component The calling component (e.g. block_ragflowtutor / aiplacement_ragflowhelpdesk).
     * @param array $result The chat_engine result.
     * @param float $starttime microtime(true) captured before the request.
     * @return void
     */
    protected static function log_usage(\context $context, int $userid, string $component, array $result, float $starttime): void {
        $success = !empty($result['success']);
        \aiprovider_ragflow\usage_logger::record(
            $context,
            $userid,
            $component,
            'chat',
            $success,
            (string) ($result['errortype'] ?? ($success ? '' : 'unexpected')),
            (int) round((microtime(true) - $starttime) * 1000),
            count($result['sources'] ?? []),
            (string) ($result['errordetails'] ?? ''),
            (int) ($result['providerid'] ?? 0),
            (int) ($result['tokensprompt'] ?? 0),
            (int) ($result['tokenscompletion'] ?? 0),
            (int) ($result['tokenstotal'] ?? 0)
        );
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the request succeeded.'),
            'generatedcontent' => new external_value(PARAM_RAW, 'The generated answer (sanitised HTML).'),
            'errormessage' => new external_value(PARAM_TEXT, 'Error message when success is false.'),
            'errordetails' => new external_value(
                PARAM_RAW,
                'Technical failure cause – site admins only, empty for everyone else.',
                VALUE_DEFAULT,
                ''
            ),
            'sources' => new external_multiple_structure(new external_single_structure([
                'number' => new external_value(PARAM_INT, 'Footnote/source number (1-based).', VALUE_DEFAULT, 0),
                'kb' => new external_value(PARAM_RAW, 'Knowledge-base (dataset) name the document belongs to.'),
                'name' => new external_value(PARAM_RAW, 'Document file name.'),
                'url' => new external_value(PARAM_RAW, 'Direct link to the document (empty for proxy documents).'),
                'dataset' => new external_value(PARAM_RAW, 'RAGflow dataset id (on-click proxy download).', VALUE_DEFAULT, ''),
                'document' => new external_value(PARAM_RAW, 'RAGflow document id (on-click proxy download).', VALUE_DEFAULT, ''),
            ]), 'The source documents behind the answer.', VALUE_DEFAULT, []),
        ]);
    }
}
