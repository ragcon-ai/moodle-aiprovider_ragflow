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
 * Return the stored conversation transcript for the current user (Helpdesk memory), so the drawer can
 * resume the previous conversation after a page reload.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_history extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'The context id of the page.'),
            'component' => new external_value(PARAM_COMPONENT, 'The calling placement component.'),
        ]);
    }

    /**
     * Return the prior turns of the user's conversation (empty when there is none / memory is off).
     *
     * @param int $contextid
     * @param string $component
     * @return array
     */
    public static function execute(int $contextid, string $component): array {
        global $USER;
        ['contextid' => $contextid, 'component' => $component] = self::validate_parameters(
            self::execute_parameters(),
            ['contextid' => $contextid, 'component' => $component]
        );
        $context = \context::instance_by_id($contextid);
        self::validate_context($context);
        require_login(null, false);

        return ['messages' => \aiprovider_ragflow\chat_engine::history($component, (int) $USER->id)];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'messages' => new external_multiple_structure(
                new external_single_structure([
                    'role' => new external_value(PARAM_ALPHA, 'user or assistant'),
                    'content' => new external_value(PARAM_RAW, 'The message text.'),
                ])
            ),
        ]);
    }
}
