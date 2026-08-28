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
 * Cache definitions for aiprovider_ragflow.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Per-user sliding-window request timestamps for the lightweight chat rate-limit guard.
    'chatrate' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'ttl' => 120,
    ],
    // Short-lived cache of a provider's dataset list [id => name], so the search-block config
    // autocomplete can filter server-side without hitting RAGflow on every keystroke.
    'datasets' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'ttl' => 60,
    ],
    // Marks a "<chatid>|<kbid>" pair as bound, so the lazy KB-binding (for auto-created tutor KBs) does
    // not re-check RAGflow once the dataset has been attached to the assistant.
    'kbbound' => [
        'mode' => cache_store::MODE_APPLICATION,
        'ttl' => 3600,
    ],
    // Per-dataset "<providerid>|<datasetid>" map of document id -> source Moodle link, so sources can be
    // linked (proxy-off case) without fetching each document's metadata on every answer.
    'docurls' => [
        'mode' => cache_store::MODE_APPLICATION,
        'ttl' => 300,
    ],
    // Shared reference-status verdicts (assistant/kb/memory) keyed by "<type>_<id>", so the config forms,
    // the runtime and the dashboard all read the same "is this reference usable?" result and checkedat.
    // Short TTL; a dashboard refresh invalidates the key. Unverified results are not stored (see checker).
    'refstatus' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'ttl' => 60,
    ],
];
