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
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Toggle the user's private (incognito) mode for the Helpdesk chat: when on, nothing is stored to or
 * recalled from long-term memory. Persisted as a user preference and enforced server-side.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class private_set extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'The context id of the page.'),
            'enabled' => new external_value(PARAM_INT, '1 to enable private mode, 0 to disable.'),
        ]);
    }

    /**
     * Set the private-mode preference.
     *
     * @param int $contextid
     * @param int $enabled
     * @return array
     */
    public static function execute(int $contextid, int $enabled): array {
        ['contextid' => $contextid, 'enabled' => $enabled] = self::validate_parameters(
            self::execute_parameters(),
            ['contextid' => $contextid, 'enabled' => $enabled]
        );
        $context = \context::instance_by_id($contextid);
        self::validate_context($context);
        require_login(null, false);

        set_user_preference('aiprovider_ragflow_privatemode', $enabled ? 1 : 0);
        return ['success' => true];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the preference was saved.'),
        ]);
    }
}
