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

namespace aiprovider_ragflow\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider. The plugin persists RAGflow chat-session references (Helpdesk conversation memory)
 * per user locally, transmits prompts to the external RAGflow service, and – with long-term memory on –
 * stores per-user memory in RAGflow's native Memory (external, scoped by a per-user session key).
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_user_preference(
            'aiprovider_ragflow_privatemode',
            'privacy:metadata:preference:privatemode'
        );

        $collection->add_database_table('aiprovider_ragflow_session', [
            'userid' => 'privacy:metadata:aiprovider_ragflow_session:userid',
            'chatid' => 'privacy:metadata:aiprovider_ragflow_session:chatid',
            'sessionid' => 'privacy:metadata:aiprovider_ragflow_session:sessionid',
            'timecreated' => 'privacy:metadata:aiprovider_ragflow_session:timecreated',
        ], 'privacy:metadata:aiprovider_ragflow_session');

        $collection->add_external_location_link('ragflow', [
            'prompt' => 'privacy:metadata:ragflow:prompt',
            'memory' => 'privacy:metadata:ragflow:memory',
        ], 'privacy:metadata:ragflow');

        return $collection;
    }

    #[\Override]
    public static function export_user_preferences(int $userid): void {
        $value = get_user_preferences('aiprovider_ragflow_privatemode', null, $userid);
        if ($value !== null) {
            writer::export_user_preference(
                'aiprovider_ragflow',
                'aiprovider_ragflow_privatemode',
                $value,
                get_string('privacy:metadata:preference:privatemode', 'aiprovider_ragflow')
            );
        }
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        // Session references are local, user-level data; associate them with the user's own context.
        $sql = "SELECT ctx.id
                  FROM {aiprovider_ragflow_session} s
                  JOIN {context} ctx ON ctx.contextlevel = :ctxlevel AND ctx.instanceid = s.userid
                 WHERE s.userid = :userid";
        $contextlist->add_from_sql($sql, ['ctxlevel' => CONTEXT_USER, 'userid' => $userid]);
        // Long-term memory lives in RAGflow (no local row), so include the user's own context whenever any
        // provider has it enabled, ensuring deletion reaches the external memory too.
        if (self::longterm_enabled_anywhere()) {
            $usercontext = \context_user::instance($userid, IGNORE_MISSING);
            if ($usercontext) {
                $contextlist->add_context($usercontext);
            }
        }
        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {aiprovider_ragflow_session} WHERE userid = :userid",
            ['userid' => $context->instanceid]
        );
        if (self::longterm_enabled_anywhere()) {
            $userlist->add_user((int) $context->instanceid);
        }
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_USER || $context->instanceid != $userid) {
                continue;
            }
            $records = $DB->get_records('aiprovider_ragflow_session', ['userid' => $userid]);
            $data = [];
            foreach ($records as $r) {
                $data[] = (object) [
                    'chatid' => $r->chatid,
                    'scopekey' => $r->scopekey,
                    'sessionid' => $r->sessionid,
                    'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime($r->timemodified),
                ];
            }
            if ($data) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'aiprovider_ragflow')],
                    (object) ['sessions' => $data]
                );
            }
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context->contextlevel == CONTEXT_USER) {
            $userid = (int) $context->instanceid;
            \aiprovider_ragflow\session_store::delete_for_user($userid);
            self::forget_ragflow_memory($userid);
        }
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_USER && $context->instanceid == $userid) {
                \aiprovider_ragflow\session_store::delete_for_user($userid);
                self::forget_ragflow_memory($userid);
                return;
            }
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            if ((int) $userid == (int) $context->instanceid) {
                \aiprovider_ragflow\session_store::delete_for_user((int) $userid);
                self::forget_ragflow_memory((int) $userid);
            }
        }
    }

    /** @var string[] RAGflow chat feature components whose long-term memory is per-user. */
    const CHAT_COMPONENTS = ['aiplacement_ragflowhelpdesk'];

    /**
     * Whether any RAGflow chat feature has long-term memory configured.
     *
     * @return bool
     */
    private static function longterm_enabled_anywhere(): bool {
        foreach (self::CHAT_COMPONENTS as $component) {
            $cfg = \aiprovider_ragflow\chat_engine::config($component);
            if ($cfg !== null && !empty($cfg->longterm)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Forget a user's RAGflow long-term memory across all chat features and reset their sessions.
     *
     * @param int $userid
     * @return void
     */
    private static function forget_ragflow_memory(int $userid): void {
        foreach (self::CHAT_COMPONENTS as $component) {
            \aiprovider_ragflow\chat_engine::forget($component, $userid);
        }
    }
}
