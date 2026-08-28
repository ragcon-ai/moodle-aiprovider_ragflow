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
 * Autocomplete source for the search block's knowledge-base picker: returns the RAGflow datasets whose
 * name matches the typed query. Site-admin only (the block's KB is admin-configured); server-side
 * filtered over a briefly cached dataset list.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_datasets extends external_api {
    /** @var int Maximum suggestions returned per query. */
    const LIMIT = 50;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(
                PARAM_TEXT,
                'The (partial) knowledge-base name to search for.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Return the datasets whose name matches the query.
     *
     * @param string $query
     * @return array
     */
    public static function execute(string $query = ''): array {
        global $DB;

        ['query' => $query] = self::validate_parameters(self::execute_parameters(), ['query' => $query]);

        // Choosing a knowledge base is a site-administration task.
        $context = \context_system::instance();
        self::validate_context($context);
        require_login(null, false);
        if (!is_siteadmin()) {
            return ['datasets' => []];
        }

        $record = $DB->get_record_select(
            'ai_providers',
            'provider = :p AND enabled = 1',
            ['p' => \aiprovider_ragflow\provider::class],
            '*',
            IGNORE_MULTIPLE
        );
        if (!$record) {
            return ['datasets' => []];
        }
        $conf = json_decode($record->config, true) ?: [];
        $all = \aiprovider_ragflow\helper::datasets_cached(
            (int) $record->id,
            (string) ($conf['baseurl'] ?? ''),
            (string) ($conf['apikey'] ?? '')
        );

        $needle = \core_text::strtolower(trim($query));
        $out = [];
        foreach ($all as $id => $name) {
            if ($needle === '' || strpos(\core_text::strtolower((string) $name), $needle) !== false) {
                $out[] = ['id' => (string) $id, 'name' => (string) $name];
            }
            if (count($out) >= self::LIMIT) {
                break;
            }
        }
        return ['datasets' => $out];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'datasets' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_TEXT, 'RAGflow dataset id.'),
                'name' => new external_value(PARAM_TEXT, 'RAGflow dataset name.'),
            ])),
        ]);
    }
}
