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

/**
 * External service definitions for aiprovider_ragflow (shared chat engine).
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'aiprovider_ragflow_chat_generate' => [
        'classname' => 'aiprovider_ragflow\external\chat_generate',
        'methodname' => 'execute',
        'description' => 'Send a chat message to the RAGflow-backed generate_text action (shared by all placements).',
        'type' => 'write',
        'ajax' => true,
    ],
    'aiprovider_ragflow_download_url' => [
        'classname' => 'aiprovider_ragflow\external\download_url',
        'methodname' => 'execute',
        'description' => 'Mint a short-lived signed download URL for a source document at click time.',
        'type' => 'read',
        'ajax' => true,
    ],
    'aiprovider_ragflow_search' => [
        'classname' => 'aiprovider_ragflow\external\search',
        'methodname' => 'execute',
        'description' => 'Semantic search (retrieval, no LLM) over the RAGflow knowledge base.',
        'type' => 'read',
        'ajax' => true,
    ],
    'aiprovider_ragflow_search_datasets' => [
        'classname' => 'aiprovider_ragflow\external\search_datasets',
        'methodname' => 'execute',
        'description' => 'Autocomplete source: RAGflow datasets matching a name query (search-block config).',
        'type' => 'read',
        'ajax' => true,
    ],
    'aiprovider_ragflow_chat_reset' => [
        'classname' => 'aiprovider_ragflow\external\chat_reset',
        'methodname' => 'execute',
        'description' => 'Reset the current user conversation memory for a context (new RAGflow session).',
        'type' => 'write',
        'ajax' => true,
    ],
    'aiprovider_ragflow_chat_history' => [
        'classname' => 'aiprovider_ragflow\external\chat_history',
        'methodname' => 'execute',
        'description' => 'Return the stored conversation transcript (Helpdesk memory) to resume after a reload.',
        'type' => 'read',
        'ajax' => true,
    ],
    'aiprovider_ragflow_memory_forget' => [
        'classname' => 'aiprovider_ragflow\external\memory_forget',
        'methodname' => 'execute',
        'description' => 'Forget (delete) the current user long-term memory.',
        'type' => 'write',
        'ajax' => true,
    ],
    'aiprovider_ragflow_private_set' => [
        'classname' => 'aiprovider_ragflow\external\private_set',
        'methodname' => 'execute',
        'description' => 'Toggle the user private (incognito) mode for the Helpdesk chat.',
        'type' => 'write',
        'ajax' => true,
    ],
];
