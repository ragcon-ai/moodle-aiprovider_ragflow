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

namespace aiprovider_ragflow\event;

/**
 * Fired when a RAGflow chat request completed successfully.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_completed extends ragflow_base {
    /**
     * The event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:chatcompleted', 'aiprovider_ragflow');
    }

    /**
     * A human-readable description.
     *
     * @return string
     */
    public function get_description() {
        $component = s($this->other['component'] ?? '');
        return "The user with id '{$this->userid}' completed a RAGflow chat request via '{$component}'.";
    }
}
