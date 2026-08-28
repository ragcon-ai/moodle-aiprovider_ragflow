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
 * Shared renderer for the RAGflow semantic-search widget (used by the search block).
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search {
    /**
     * Render the search box + results container (+ bootstrap JS).
     *
     * @param array $opts contextid, blockinstanceid
     * @return string
     */
    public static function render_search(array $opts): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('aiprovider_ragflow/search', [
            'uid' => \html_writer::random_id('rfsearch_'),
            'contextid' => (int) ($opts['contextid'] ?? 0),
            'blockinstanceid' => (int) ($opts['blockinstanceid'] ?? 0),
        ]);
    }
}
