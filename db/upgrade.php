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
 * Upgrade steps for aiprovider_ragflow.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_aiprovider_ragflow_upgrade($oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026080701) {
        // Conversation memory: table mapping a Moodle user + conversation scope to a RAGflow session.
        $table = new xmldb_table('aiprovider_ragflow_session');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('providerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('chatid', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
        $table->add_field('scopekey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
        $table->add_field('sessionid', XMLDB_TYPE_CHAR, '128', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('userprovchatscope', XMLDB_INDEX_UNIQUE, ['userid', 'providerid', 'chatid', 'scopekey']);
        $table->add_index('timemodified', XMLDB_INDEX_NOTUNIQUE, ['timemodified']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026080701, 'aiprovider', 'ragflow');
    }

    if ($oldversion < 2026080703) {
        // The 'ragflow' document source (documents from this Moodle, served via the proxy) was split into
        // the 'thismoodle' source + a standalone 'serveviaproxy' flag. Migrate any stored value so the
        // removed option cannot linger.
        $providers = $DB->get_records_select(
            'ai_providers',
            $DB->sql_like('provider', ':p'),
            ['p' => '%aiprovider_ragflow%']
        );
        foreach ($providers as $prov) {
            $actionconfig = json_decode($prov->actionconfig, true);
            if (!is_array($actionconfig)) {
                continue;
            }
            $changed = false;
            foreach ($actionconfig as $actionclass => $conf) {
                if (!isset($conf['settings']) || !is_array($conf['settings'])) {
                    continue;
                }
                if (($conf['settings']['datasource'] ?? '') === 'ragflow') {
                    $actionconfig[$actionclass]['settings']['datasource'] = 'thismoodle';
                    $actionconfig[$actionclass]['settings']['serveviaproxy'] = 1;
                    $changed = true;
                }
            }
            if ($changed) {
                $prov->actionconfig = json_encode($actionconfig);
                $DB->update_record('ai_providers', $prov);
            }
        }

        upgrade_plugin_savepoint(true, 2026080703, 'aiprovider', 'ragflow');
    }

    if ($oldversion < 2026080704) {
        // Long-term memory: per-user profile of durable facts carried across sessions (Helpdesk).
        $table = new xmldb_table('aiprovider_ragflow_memory');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('providerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('scopekey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
        $table->add_field('profile', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('userprovscope', XMLDB_INDEX_UNIQUE, ['userid', 'providerid', 'scopekey']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026080704, 'aiprovider', 'ragflow');
    }

    if ($oldversion < 2026080705) {
        // Long-term memory moved to RAGflow's native Memory API; the local profile table is obsolete.
        $table = new xmldb_table('aiprovider_ragflow_memory');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        upgrade_plugin_savepoint(true, 2026080705, 'aiprovider', 'ragflow');
    }

    if ($oldversion < 2026080708) {
        // Chat/Helpdesk config moved from the provider's per-action settings to each feature plugin, so
        // every feature owns its own configuration. Migrate the existing generate_text values once.
        $rec = $DB->get_record_select(
            'ai_providers',
            'provider = :p',
            ['p' => 'aiprovider_ragflow\\provider'],
            '*',
            IGNORE_MULTIPLE
        );
        $s = $rec ? (json_decode($rec->actionconfig, true)['core_ai\\aiactions\\generate_text']['settings'] ?? []) : [];
        if ($s) {
            // Helpdesk placement.
            $hd = 'aiplacement_ragflowhelpdesk';
            if (!empty($s['helpdeskchatid']) && get_config($hd, 'chatid') === false) {
                set_config('chatid', $s['helpdeskchatid'], $hd);
                set_config('sessionmemory', !empty($s['helpdeskmemory']) ? 1 : 0, $hd);
                set_config('longterm', !empty($s['helpdesklongtermmemory']) ? 1 : 0, $hd);
                set_config('memoryid', (string) ($s['helpdeskmemoryid'] ?? ''), $hd);
                set_config('includesources', !empty($s['includesources']) ? 1 : 0, $hd);
                set_config('serveviaproxy', !empty($s['serveviaproxy']) ? 1 : 0, $hd);
            }
            // Tutor placement (course chat).
            $tut = 'aiplacement_ragflowchat';
            if (!empty($s['chatid']) && get_config($tut, 'chatid') === false) {
                set_config('chatid', $s['chatid'], $tut);
                set_config('includesources', !empty($s['includesources']) ? 1 : 0, $tut);
                set_config('serveviaproxy', !empty($s['serveviaproxy']) ? 1 : 0, $tut);
            }
        }

        upgrade_plugin_savepoint(true, 2026080708, 'aiprovider', 'ragflow');
    }

    if ($oldversion < 2026081900) {
        // The invalid empty-string DEFAULT on the NOT NULL char column scopekey was removed in
        // install.xml. No DDL is applied here: Moodle already strips such a default at install time
        // (so existing columns carry none), and scopekey is part of a unique index, which makes
        // change_field_default() raise a ddldependencyerror. Fresh installs use the corrected
        // install.xml; nothing needs to change on an existing database.
        upgrade_plugin_savepoint(true, 2026081900, 'aiprovider', 'ragflow');
    }

    return true;
}
