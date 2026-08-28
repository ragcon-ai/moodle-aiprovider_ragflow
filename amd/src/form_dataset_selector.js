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

/**
 * Autocomplete (AJAX) source for the RAGflow search block's knowledge-base picker. Each keystroke asks
 * the server for datasets whose name matches the typed query.
 *
 * @module     aiprovider_ragflow/form_dataset_selector
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/**
 * Source of the results: query the web service for matching datasets.
 *
 * @param {String} selector The selector id (unused).
 * @param {String} query The current search term.
 * @param {Function} success Callback given the raw results.
 * @param {Function} failure Callback given an error.
 */
export const transport = (selector, query, success, failure) => {
    Ajax.call([{
        methodname: 'aiprovider_ragflow_search_datasets',
        args: {query: query},
    }])[0].then((response) => {
        success(response.datasets);
        return true;
    }).catch(failure);
};

/**
 * Map the web-service results into the {value, label} shape the autocomplete expects.
 *
 * @param {String} selector The selector id (unused).
 * @param {Array} results The datasets returned by transport().
 * @return {Array}
 */
export const processResults = (selector, results) => results.map((d) => ({value: d.id, label: d.name}));
