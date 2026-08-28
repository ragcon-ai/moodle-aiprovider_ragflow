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

namespace aiprovider_ragflow\local\health;

/**
 * The single question "is this RAGflow reference usable?" — implemented once ({@see checker}) and consumed
 * by every surface (config forms, runtime, the dashboard). No other component runs its own validity logic.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface reference_checker {
    /**
     * Is the given chat-assistant id usable?
     *
     * @param string $id The stored assistant id ('' = not configured).
     * @return reference_status
     */
    public function check_assistant(string $id): reference_status;

    /**
     * Is the given knowledge-base (dataset) id usable?
     *
     * @param string $id The stored dataset id ('' = not configured).
     * @return reference_status
     */
    public function check_knowledge_base(string $id): reference_status;

    /**
     * Is the given memory id usable?
     *
     * @param string $id The stored memory id ('' = not configured).
     * @return reference_status
     */
    public function check_memory(string $id): reference_status;
}
