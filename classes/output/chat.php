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

namespace aiprovider_ragflow\output;

/**
 * Shared renderer for the RAGflow chat drawer. Every RAGflow placement (Tutor, Helpdesk) injects the
 * same drawer via this helper – only the trigger, label and greeting differ.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat {
    /**
     * Render the chat drawer HTML (button + drawer + bootstrap JS).
     *
     * @param array $opts contextid, userid, component (calling placement), label, greeting,
     *                     page (bool: true = open in-page panel without a floating button)
     * @return string
     */
    public static function render_drawer(array $opts): string {
        global $OUTPUT, $PAGE;
        $contextid = (int) ($opts['contextid'] ?? 0);
        $component = (string) ($opts['component'] ?? '');
        // Memory/long-term availability comes from the calling component's own configuration: session
        // memory enables the server-side transcript (resume + New conversation); long-term enables the
        // per-user facts (private conversation + delete memories).
        $cfg = $component !== '' ? \aiprovider_ragflow\chat_engine::config($component) : null;
        $memory = $cfg !== null && !empty($cfg->sessionmemory);
        $longterm = $cfg !== null && !empty($cfg->longterm);
        $params = [
            'userid' => (int) ($opts['userid'] ?? 0),
            'contextid' => $contextid,
            'component' => (string) ($opts['component'] ?? ''),
            'blockinstanceid' => (int) ($opts['blockinstanceid'] ?? 0),
            'label' => (string) ($opts['label'] ?? ''),
            'greeting' => trim((string) ($opts['greeting'] ?? '')),
            'page' => !empty($opts['page']),
            'memory' => $memory,
            'longterm' => $longterm,
        ];
        // Standard Moodle help icons (click-popover) for the conversation buttons.
        if ($memory) {
            $params['newconvhelp'] = $OUTPUT->help_icon('chatnewconversation', 'aiprovider_ragflow');
        }
        if ($longterm) {
            $params['newprivatehelp'] = $OUTPUT->help_icon('chatnewprivate', 'aiprovider_ragflow');
            $params['forgethelp'] = $OUTPUT->help_icon('chatforgetmemory', 'aiprovider_ragflow');
        }
        // Initialise the drawer JS from PHP (not the template's {{#js}}), so it also works when the drawer
        // is rendered inside a block's get_content(), where inline js_amd_inline is not reliably emitted.
        $PAGE->requires->js_call_amd('aiprovider_ragflow/chat', 'init', [
            $contextid,
            (int) ($opts['userid'] ?? 0),
            $component,
            $memory,
            $longterm,
            (int) ($opts['blockinstanceid'] ?? 0),
        ]);
        return $OUTPUT->render_from_template('aiprovider_ragflow/drawer', $params);
    }
}
