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

/**
 * Scheduled task: prune stale RAGflow conversation sessions (Helpdesk memory retention).
 *
 * Sessions unused for longer than the retention period (config 'sessionretentiondays', default 30)
 * are deleted both from RAGflow and locally, so conversations are not kept indefinitely.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prune_sessions extends \core\task\scheduled_task {
    /**
     * Task name shown in the admin task list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:prunesessions', 'aiprovider_ragflow');
    }

    /**
     * Delete sessions older than the retention period.
     *
     * @return void
     */
    public function execute(): void {
        $days = (int) get_config('aiprovider_ragflow', 'sessionretentiondays');
        if ($days <= 0) {
            $days = 30;
        }
        $cutoff = time() - ($days * DAYSECS);
        // Only short-term chat sessions are pruned. Long-term memory lives in RAGflow (its own FIFO /
        // memory_size policy) and is removed per user via the privacy provider / user deletion.
        $pruned = \aiprovider_ragflow\session_store::prune($cutoff);
        mtrace("aiprovider_ragflow: pruned {$pruned} conversation session(s) older than {$days} day(s).");
    }
}
