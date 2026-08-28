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

namespace aiprovider_ragflow;

/**
 * Persistent RAGflow chat sessions (server-side conversation memory). Currently used by the Helpdesk.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_store {
    /** @var string Conversation scope key for the site-wide Helpdesk. */
    const SCOPE_HELPDESK = 'helpdesk';

    /** @var string Fixed agent id tag for the Memory API (any non-empty string; need not be a real agent). */
    const MEMORY_AGENT_ID = 'aiprovider_ragflow';

    /**
     * Get the RAGflow session id for a user+scope, creating (and storing) one if needed.
     *
     * @param int $userid
     * @param int $providerid
     * @param string $chatid
     * @param string $scopekey
     * @param string $base
     * @param string $key
     * @param string $errordetail Out: the technical failure cause on '' return (admin-only). Empty on success.
     * @return string The session id, or '' on failure.
     */
    public static function get_or_create(
        int $userid,
        int $providerid,
        string $chatid,
        string $scopekey,
        string $base,
        string $key,
        string &$errordetail = ''
    ): string {
        global $DB;
        $errordetail = '';
        $params = ['userid' => $userid, 'providerid' => $providerid, 'chatid' => $chatid, 'scopekey' => $scopekey];
        $rec = $DB->get_record('aiprovider_ragflow_session', $params);
        if ($rec) {
            $DB->set_field('aiprovider_ragflow_session', 'timemodified', time(), ['id' => $rec->id]);
            return $rec->sessionid;
        }
        $sid = helper::create_session($base, $key, $chatid, 'moodle-' . $userid . '-' . $scopekey, $errordetail);
        if ($sid === '') {
            return '';
        }
        $now = time();
        $DB->insert_record(
            'aiprovider_ragflow_session',
            (object) ($params + ['sessionid' => $sid, 'timecreated' => $now, 'timemodified' => $now])
        );
        return $sid;
    }

    /**
     * The stored RAGflow session id for a user+scope, or '' if none exists yet (does NOT create one).
     *
     * @param int $userid
     * @param int $providerid
     * @param string $chatid
     * @param string $scopekey
     * @return string
     */
    public static function existing_session_id(int $userid, int $providerid, string $chatid, string $scopekey): string {
        global $DB;
        $rec = $DB->get_record(
            'aiprovider_ragflow_session',
            ['userid' => $userid, 'providerid' => $providerid, 'chatid' => $chatid, 'scopekey' => $scopekey]
        );
        return $rec ? $rec->sessionid : '';
    }

    /**
     * Reset a conversation: delete the RAGflow session and its row (a new one is created on next use).
     *
     * @param int $userid
     * @param int $providerid
     * @param string $chatid
     * @param string $scopekey
     * @param string $base
     * @param string $key
     * @return void
     */
    public static function reset(
        int $userid,
        int $providerid,
        string $chatid,
        string $scopekey,
        string $base,
        string $key
    ): void {
        global $DB;
        $params = ['userid' => $userid, 'providerid' => $providerid, 'chatid' => $chatid, 'scopekey' => $scopekey];
        $rec = $DB->get_record('aiprovider_ragflow_session', $params);
        if ($rec) {
            helper::delete_sessions($base, $key, $chatid, [$rec->sessionid]);
            $DB->delete_records('aiprovider_ragflow_session', ['id' => $rec->id]);
        }
    }

    /**
     * Delete all sessions of a user (privacy / user deletion).
     *
     * @param int $userid
     * @return void
     */
    public static function delete_for_user(int $userid): void {
        global $DB;
        foreach ($DB->get_records('aiprovider_ragflow_session', ['userid' => $userid]) as $r) {
            [$base, $key] = self::provider_credentials((int) $r->providerid);
            if ($base !== '') {
                helper::delete_sessions($base, $key, $r->chatid, [$r->sessionid]);
            }
        }
        $DB->delete_records('aiprovider_ragflow_session', ['userid' => $userid]);
    }

    /**
     * Delete sessions not used since $olderthan (retention task).
     *
     * @param int $olderthan Unix time cut-off.
     * @return int Number of sessions pruned.
     */
    public static function prune(int $olderthan): int {
        global $DB;
        $count = 0;
        foreach ($DB->get_records_select('aiprovider_ragflow_session', 'timemodified < :t', ['t' => $olderthan]) as $r) {
            [$base, $key] = self::provider_credentials((int) $r->providerid);
            if ($base !== '') {
                helper::delete_sessions($base, $key, $r->chatid, [$r->sessionid]);
            }
            $DB->delete_records('aiprovider_ragflow_session', ['id' => $r->id]);
            $count++;
        }
        return $count;
    }

    /**
     * Read base URL + API key of a provider instance.
     *
     * @param int $providerid
     * @return array [baseurl, apikey]
     */
    private static function provider_credentials(int $providerid): array {
        global $DB;
        $rec = $DB->get_record('ai_providers', ['id' => $providerid]);
        if (!$rec) {
            return ['', ''];
        }
        $conf = json_decode($rec->config, true) ?: [];
        return [(string) ($conf['baseurl'] ?? ''), (string) ($conf['apikey'] ?? '')];
    }
}
