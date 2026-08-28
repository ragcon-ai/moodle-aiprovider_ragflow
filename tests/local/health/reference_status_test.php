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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests the runtime allow/deny gate on the reference-status value object. `is_usable()` is the boolean
 * that {@see \aiprovider_ragflow\chat_engine::reference_missing_error()} and the search notice consult, so
 * a regression here would silently block a good assistant or let a deleted one through.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(reference_status::class)]
final class reference_status_test extends \advanced_testcase {
    /**
     * Only OK is "ok"; every other state is not.
     *
     * @return void
     */
    public function test_is_ok(): void {
        $this->assertTrue((new reference_status(reference_status::OK, 'ok', 'id'))->is_ok());
        $notok = [
            reference_status::DEGRADED,
            reference_status::MISSING,
            reference_status::UNVERIFIED,
            reference_status::NOT_CONFIGURED,
        ];
        foreach ($notok as $state) {
            $this->assertFalse((new reference_status($state, 'r', 'id'))->is_ok(), "state {$state} is not ok");
        }
    }

    /**
     * A request proceeds for ok / degraded / unverified, and is blocked for missing / not-configured. This
     * is the central "missing != unverified" rule at the runtime boundary: an unreachable API (unverified)
     * must not block, a deleted reference (missing) must.
     *
     * @return void
     */
    public function test_is_usable(): void {
        $this->assertTrue((new reference_status(reference_status::OK, 'ok', 'id'))->is_usable());
        $this->assertTrue((new reference_status(reference_status::DEGRADED, 'kb_not_bound', 'id'))->is_usable());
        $this->assertTrue((new reference_status(reference_status::UNVERIFIED, 'api_unreachable', 'id'))->is_usable());
        $this->assertFalse((new reference_status(reference_status::MISSING, 'assistant_not_found', 'id'))->is_usable());
        $this->assertFalse((new reference_status(reference_status::NOT_CONFIGURED, 'not_configured', ''))->is_usable());
    }

    /**
     * The constructor keeps the given fields and defaults checkedat to "now" when not supplied.
     *
     * @return void
     */
    public function test_constructor_defaults(): void {
        $before = time();
        $status = new reference_status(reference_status::OK, 'ok', 'abc', 'Name', 'detail');
        $this->assertSame(reference_status::OK, $status->state);
        $this->assertSame('ok', $status->reason);
        $this->assertSame('abc', $status->reference);
        $this->assertSame('Name', $status->label);
        $this->assertSame('detail', $status->detail);
        $this->assertGreaterThanOrEqual($before, $status->checkedat);
        $this->assertFalse($status->fromcache);
    }
}
