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

namespace aiprovider_ragflow\task;

use aiprovider_ragflow\helper;
use aiprovider_ragflow\provider;

/**
 * Ad-hoc task that finishes linking a freshly seeded knowledge base to its assistant: once the seed
 * document has parsed, it binds the assistant to the KB and clears the seed's chunks. Enqueued only when
 * the synchronous wait at creation time did not complete in the budget; it retries (with the scheduler's
 * back-off) until the seed is parsed.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class finish_kb_binding extends \core\task\adhoc_task {
    /**
     * Run one completion attempt; throw to be retried if the seed is not parsed yet.
     *
     * @return void
     */
    public function execute() {
        global $DB;
        $data = (array) $this->get_custom_data();
        $providerid = (int) ($data['providerid'] ?? 0);
        $datasetid = (string) ($data['datasetid'] ?? '');
        $chatid = (string) ($data['chatid'] ?? '');
        $docid = (string) ($data['docid'] ?? '');
        if ($providerid <= 0 || $datasetid === '' || $chatid === '') {
            mtrace('aiprovider_ragflow: finish_kb_binding missing data – nothing to do.');
            return;
        }
        $prov = $DB->get_record('ai_providers', ['id' => $providerid, 'provider' => provider::class]);
        if (!$prov) {
            mtrace('aiprovider_ragflow: finish_kb_binding provider gone – nothing to do.');
            return;
        }
        $conf = json_decode($prov->config, true) ?: [];
        $base = rtrim((string) ($conf['baseurl'] ?? ''), '/');
        $key = (string) ($conf['apikey'] ?? '');

        $status = helper::try_finish_seed($base, $key, $datasetid, $chatid, $docid);
        if ($status === 'linked') {
            mtrace('aiprovider_ragflow: knowledge base linked to assistant.');
            return;
        }
        if ($status === 'error') {
            mtrace('aiprovider_ragflow: finish_kb_binding gave up (error).');
            return;
        }
        // Still parsing – throw so the scheduler retries later with back-off.
        throw new \moodle_exception('error:seedpending', 'aiprovider_ragflow');
    }
}
