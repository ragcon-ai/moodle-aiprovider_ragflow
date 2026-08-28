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

namespace aiprovider_ragflow\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * AJAX: mint a short-lived signed download URL for a source document, at click time. Authorises live by the
 * caller's :use capability on the placement/block and by the document's dataset being one the component's
 * assistant can access – so the signed token only needs a very short lifetime.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class download_url extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'The context id of the page.'),
            'component' => new external_value(PARAM_COMPONENT, 'Calling placement component (placement path).', VALUE_DEFAULT, ''),
            'blockinstanceid' => new external_value(PARAM_INT, 'The block instance id (block path).', VALUE_DEFAULT, 0),
            'dataset' => new external_value(PARAM_ALPHANUMEXT, 'RAGflow dataset id.'),
            'document' => new external_value(PARAM_ALPHANUMEXT, 'RAGflow document id.'),
        ]);
    }

    /**
     * Return a freshly signed proxy download URL, or '' if not authorised.
     *
     * @param int $contextid
     * @param string $component
     * @param int $blockinstanceid
     * @param string $dataset
     * @param string $document
     * @return array
     */
    public static function execute(
        int $contextid,
        string $component,
        int $blockinstanceid,
        string $dataset,
        string $document
    ): array {
        global $USER, $DB;
        [
            'contextid' => $contextid,
            'component' => $component,
            'blockinstanceid' => $blockinstanceid,
            'dataset' => $dataset,
            'document' => $document,
        ] = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'component' => $component,
            'blockinstanceid' => $blockinstanceid,
            'dataset' => $dataset,
            'document' => $document,
        ]);

        $context = \context::instance_by_id($contextid);
        self::validate_context($context);
        require_login(null, false);

        if ($dataset === '' || $document === '') {
            return ['url' => ''];
        }

        // Resolve provider credentials + the set of datasets the caller is allowed to download from. The
        // download token is minted only if the requested dataset is in that authorised set, so a signed URL
        // never grants access to an unrelated knowledge base.
        $providerid = 0;
        $base = '';
        $key = '';
        $alloweddatasets = [];

        if ($blockinstanceid > 0) {
            $record = $DB->get_record('block_instances', ['id' => $blockinstanceid], 'id, blockname');
            if (!$record) {
                throw new \invalid_parameter_exception('unknown block instance.');
            }
            if ($record->blockname === 'ragflowsearch') {
                // Search block: it has no per-use capability (visible to anyone who can access the block's
                // context, like its search web service), so authorise the same way – access to the context
                // plus the requested dataset being one the block instance is configured to search.
                [$providerid, $base, $key] = self::provider_credentials();
                $alloweddatasets = self::search_block_datasets($blockinstanceid);
            } else {
                // Chat block (Tutor): gated by the block's own :use capability; authorised datasets = the
                // block assistant's datasets.
                require_capability('block/' . $record->blockname . ':use', $context);
                $cfg = \aiprovider_ragflow\chat_engine::config_from_block($blockinstanceid);
                if ($cfg !== null) {
                    $providerid = (int) $cfg->providerid;
                    $base = $cfg->base;
                    $key = $cfg->key;
                    $alloweddatasets = \aiprovider_ragflow\helper::get_chat_datasets($cfg->base, $cfg->key, $cfg->chatid);
                }
            }
        } else {
            if (strpos($component, 'aiplacement_') !== 0) {
                throw new \invalid_parameter_exception('component must be an aiplacement plugin.');
            }
            require_capability('aiplacement/' . substr($component, strlen('aiplacement_')) . ':use', $context);
            $cfg = \aiprovider_ragflow\chat_engine::config($component);
            if ($cfg !== null) {
                $providerid = (int) $cfg->providerid;
                $base = $cfg->base;
                $key = $cfg->key;
                $alloweddatasets = \aiprovider_ragflow\helper::get_chat_datasets($cfg->base, $cfg->key, $cfg->chatid);
            }
        }

        $url = '';
        if ($providerid > 0 && in_array($dataset, $alloweddatasets, true)) {
            $url = \aiprovider_ragflow\helper::proxy_url($providerid, $dataset, $document, (int) $USER->id);
        }
        return ['url' => $url];
    }

    /**
     * Credentials of the enabled RAGflow provider instance.
     *
     * @return array [providerid, baseurl, apikey]
     */
    private static function provider_credentials(): array {
        global $DB;
        $record = $DB->get_record_select(
            'ai_providers',
            'provider = :p AND enabled = 1',
            ['p' => \aiprovider_ragflow\provider::class],
            '*',
            IGNORE_MULTIPLE
        );
        if (!$record) {
            return [0, '', ''];
        }
        $conf = json_decode($record->config, true) ?: [];
        return [(int) $record->id, rtrim((string) ($conf['baseurl'] ?? ''), '/'), (string) ($conf['apikey'] ?? '')];
    }

    /**
     * The knowledge-base (dataset) ids a RAGflow search block instance is configured to search.
     *
     * @param int $blockinstanceid
     * @return string[]
     */
    private static function search_block_datasets(int $blockinstanceid): array {
        global $DB;
        $record = $DB->get_record('block_instances', ['id' => $blockinstanceid, 'blockname' => 'ragflowsearch']);
        if (!$record || $record->configdata === '') {
            return [];
        }
        // Block config is a Moodle-written stdClass; restrict unserialize to stdClass so a tampered
        // configdata cannot trigger PHP object-injection gadget chains.
        $config = unserialize(base64_decode($record->configdata), ['allowed_classes' => ['stdClass']]);
        if (!is_object($config)) {
            return [];
        }
        $datasets = [];
        foreach ((array) ($config->datasets ?? []) as $dsid) {
            $dsid = trim((string) $dsid);
            if ($dsid !== '') {
                $datasets[] = $dsid;
            }
        }
        return array_values(array_unique($datasets));
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'url' => new external_value(PARAM_RAW, 'Signed download URL, or empty if not authorised.'),
        ]);
    }
}
