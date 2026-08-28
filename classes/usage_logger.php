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
 * Central usage/error recorder shared by every RAGflow surface. It feeds two **independent** sinks from
 * one call site, so the plugins work with or without the optional dashboard:
 *
 *  1. **Moodle standard log** – a concise usage/error event (metrics only, no content), emitted **only**
 *     when the calling plugin's `logtomoodle` setting is enabled; a short technical detail is added when
 *     site-wide developer debugging is on. This is the slim, dashboard-independent record.
 *  2. **RAGflow Dashboard** – rich analysis, captured directly via `\local_ragflowdashboard\api` when that
 *     optional plugin is installed. Independent of the `logtomoodle` setting.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage_logger {
    /**
     * Record one usage/error occurrence to both sinks (best-effort; never breaks the request).
     *
     * @param \context $context The action's context.
     * @param int $userid The acting user.
     * @param string $component The calling component (e.g. block_ragflowtutor / aiplacement_ragflowhelpdesk).
     * @param string $action Short action name ('chat' | 'search').
     * @param bool $success Whether the action succeeded.
     * @param string $errortype Coarse error type ('' on success).
     * @param int $latencyms Duration in milliseconds.
     * @param int $itemcount Item count (e.g. sources / results).
     * @param string $errordetail Optional technical detail (added to the log only under developer debugging).
     * @param int $providerid RAGflow provider instance id (0 if unknown), for the dashboard's token breakdown.
     * @param int $tokensprompt Prompt tokens (chat only; 0 for search).
     * @param int $tokenscompletion Completion tokens (chat only).
     * @param int $tokenstotal Total tokens (chat only).
     * @return void
     */
    public static function record(
        \context $context,
        int $userid,
        string $component,
        string $action,
        bool $success,
        string $errortype,
        int $latencyms,
        int $itemcount,
        string $errordetail = '',
        int $providerid = 0,
        int $tokensprompt = 0,
        int $tokenscompletion = 0,
        int $tokenstotal = 0
    ): void {
        // Sink 1 – slim Moodle standard log (opt-in per plugin).
        if (get_config($component, 'logtomoodle')) {
            self::emit_event($context, $userid, $component, $action, $success, $errortype, $latencyms, $itemcount, $errordetail);
        }
        // Sink 2 – optional rich dashboard (independent of the setting above).
        if (class_exists('\local_ragflowdashboard\api')) {
            try {
                \local_ragflowdashboard\api::capture_usage(
                    $context,
                    $userid,
                    $component,
                    $action,
                    $success,
                    $errortype,
                    $latencyms,
                    $itemcount,
                    $providerid,
                    $tokensprompt,
                    $tokenscompletion,
                    $tokenstotal
                );
            } catch (\Throwable $e) {
                debugging('aiprovider_ragflow: dashboard usage capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Trigger the matching Moodle usage event (stored by logstore_standard). Metrics only – never content.
     *
     * @param \context $context
     * @param int $userid
     * @param string $component
     * @param string $action
     * @param bool $success
     * @param string $errortype
     * @param int $latencyms
     * @param int $itemcount
     * @param string $errordetail
     * @return void
     */
    private static function emit_event(
        \context $context,
        int $userid,
        string $component,
        string $action,
        bool $success,
        string $errortype,
        int $latencyms,
        int $itemcount,
        string $errordetail
    ): void {
        $other = [
            'component' => $component,
            'action' => $action,
            'success' => $success ? 1 : 0,
            'errortype' => $errortype,
            'latencyms' => $latencyms,
            'itemcount' => $itemcount,
        ];
        // Under developer debugging, add a short technical cause (no user content) for troubleshooting.
        if ($errordetail !== '' && debugging('', DEBUG_DEVELOPER)) {
            $other['errordetail'] = \core_text::substr($errordetail, 0, 200);
        }
        if ($action === 'search') {
            $class = \aiprovider_ragflow\event\search_performed::class;
        } else {
            $class = $success
                ? \aiprovider_ragflow\event\chat_completed::class
                : \aiprovider_ragflow\event\chat_failed::class;
        }
        try {
            $class::create(['context' => $context, 'userid' => $userid, 'other' => $other])->trigger();
        } catch (\Throwable $e) {
            debugging('aiprovider_ragflow: failed to emit usage event: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
