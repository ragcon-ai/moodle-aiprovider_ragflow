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

use aiprovider_ragflow\helper;
use aiprovider_ragflow\provider;

/**
 * The single implementation of "is this RAGflow reference usable?". It fetches the relevant list once
 * (distinguishing "not in the list" from "list could not be loaded"), caches the verdict per reference id
 * in a shared MUC bin so every surface sees the same result and {@see checkedat}, and never conflates
 * {@see reference_status::MISSING} with {@see reference_status::UNVERIFIED}.
 *
 * The state logic is exposed as pure static classifiers ({@see classify_assistant()} …) so it can be unit
 * tested without touching the network.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class checker implements reference_checker {
    /** @var string RAGflow base URL. */
    protected string $baseurl;
    /** @var string RAGflow API key. */
    protected string $apikey;
    /** @var int Provider instance id (0 = none). */
    protected int $providerid;

    /**
     * Build a checker bound to a specific RAGflow provider configuration.
     *
     * @param string $baseurl RAGflow base URL.
     * @param string $apikey RAGflow API key.
     * @param int $providerid Provider instance id.
     */
    public function __construct(string $baseurl, string $apikey, int $providerid = 0) {
        $this->baseurl = $baseurl;
        $this->apikey = $apikey;
        $this->providerid = $providerid;
    }

    /**
     * Build a checker for the enabled RAGflow provider (the single-provider assumption used throughout).
     *
     * @return self
     */
    public static function instance(): self {
        global $DB;
        $rec = $DB->get_record_select(
            'ai_providers',
            'provider = :p AND enabled = 1',
            ['p' => provider::class],
            '*',
            IGNORE_MULTIPLE
        );
        if (!$rec) {
            return new self('', '', 0);
        }
        $config = json_decode($rec->config, true) ?: [];
        return new self((string) ($config['baseurl'] ?? ''), (string) ($config['apikey'] ?? ''), (int) $rec->id);
    }

    /**
     * Is the given chat-assistant id usable? Cached; distinguishes missing from unverified.
     *
     * @param string $id The stored assistant id.
     * @return reference_status
     */
    public function check_assistant(string $id): reference_status {
        return $this->resolve('assistant', $id, function () use ($id): reference_status {
            $r = helper::chats_result($this->baseurl, $this->apikey);
            return self::classify_assistant((array) $r->items, (string) $r->errorkind, (string) $r->detail, $id);
        });
    }

    /**
     * Is the given knowledge-base (dataset) id usable? Cached; distinguishes missing from unverified.
     *
     * @param string $id The stored dataset id.
     * @return reference_status
     */
    public function check_knowledge_base(string $id): reference_status {
        return $this->resolve('kb', $id, function () use ($id): reference_status {
            $r = helper::datasets_result($this->baseurl, $this->apikey);
            return self::classify_kb((array) $r->items, (string) $r->errorkind, (string) $r->detail, $id);
        });
    }

    /**
     * Is the given memory id usable? Cached; distinguishes missing from unverified.
     *
     * @param string $id The stored memory id.
     * @return reference_status
     */
    public function check_memory(string $id): reference_status {
        return $this->resolve('memory', $id, function () use ($id): reference_status {
            $r = helper::memories_result($this->baseurl, $this->apikey);
            return self::classify_memory((array) $r->items, (string) $r->errorkind, (string) $r->detail, $id);
        });
    }

    /**
     * Shared cache lookup + store around a fetch-and-classify callback. An `unverified` verdict is NOT
     * cached, so a transient connection problem is retried on the next view rather than stuck.
     *
     * @param string $type Reference type (assistant|kb|memory) – the cache-key prefix.
     * @param string $id The reference id.
     * @param callable $compute Returns the freshly computed reference_status.
     * @return reference_status
     */
    protected function resolve(string $type, string $id, callable $compute): reference_status {
        if ($id === '') {
            return new reference_status(reference_status::NOT_CONFIGURED, 'not_configured', '');
        }
        $cache = \cache::make('aiprovider_ragflow', 'refstatus');
        $key = $type . '_' . $id;
        $hit = $cache->get($key);
        if ($hit instanceof reference_status) {
            $hit->fromcache = true;
            return $hit;
        }
        $status = $compute();
        if ($status->state !== reference_status::UNVERIFIED) {
            $cache->set($key, $status);
        }
        return $status;
    }

    /**
     * Drop a single cached reference verdict (e.g. on a dashboard "refresh").
     *
     * @param string $type assistant|kb|memory
     * @param string $id The reference id.
     * @return void
     */
    public function invalidate(string $type, string $id): void {
        \cache::make('aiprovider_ragflow', 'refstatus')->delete($type . '_' . $id);
    }

    /**
     * Drop all cached reference verdicts (e.g. a full dashboard "refresh").
     *
     * @return void
     */
    public static function purge(): void {
        \cache::make('aiprovider_ragflow', 'refstatus')->purge();
    }

    /**
     * A human label for a stored reference that is no longer in the live list, distinguishing "no longer
     * exists" (missing) from "could not be verified" (unverified) — so a config form never shows a bare
     * 32-character hash and never mislabels an unreachable reference as deleted.
     *
     * @param reference_status $status
     * @return string
     */
    public static function stale_option_label(reference_status $status): string {
        $ref = (string) $status->reference;
        $short = $ref === '' ? '' : \core_text::substr($ref, 0, 8) . '…';
        return $status->state === reference_status::UNVERIFIED
            ? get_string('reference:option_unverified', 'aiprovider_ragflow', $short)
            : get_string('reference:option_missing', 'aiprovider_ragflow', $short);
    }

    /**
     * Map an errorkind from a failed list fetch to an `api_*` reason code.
     *
     * @param string $errorkind timeout|unauthorized|unreachable|http|malformed|not_configured
     * @return string
     */
    protected static function reason_for_errorkind(string $errorkind): string {
        return match ($errorkind) {
            'timeout' => 'api_timeout',
            'unauthorized' => 'api_unauthorized',
            default => 'api_unreachable',
        };
    }

    /**
     * Classify an assistant id against the (possibly empty) list of assistants and the fetch error kind.
     * Pure – no network, no cache.
     *
     * @param array $items [id => {name, kb}] as returned by helper::chats_result()->items.
     * @param string $errorkind '' on a successful load, otherwise the fetch error kind.
     * @param string $detail Technical detail (privileged view only).
     * @param string $id The stored assistant id.
     * @return reference_status
     */
    public static function classify_assistant(array $items, string $errorkind, string $detail, string $id): reference_status {
        if ($id === '') {
            return new reference_status(reference_status::NOT_CONFIGURED, 'not_configured', '');
        }
        if ($errorkind !== '') {
            return new reference_status(reference_status::UNVERIFIED, self::reason_for_errorkind($errorkind), $id, null, $detail);
        }
        $chat = $items[$id] ?? null;
        if ($chat === null) {
            return new reference_status(reference_status::MISSING, 'assistant_not_found', $id);
        }
        $name = (string) ($chat->name ?? $id);
        if ((int) ($chat->kb ?? 0) === 0) {
            return new reference_status(reference_status::DEGRADED, 'kb_not_bound', $id, $name);
        }
        return new reference_status(reference_status::OK, 'ok', $id, $name);
    }

    /**
     * Classify a knowledge-base (dataset) id. Pure.
     *
     * @param array $items [id => {name, chunk_count, document_count}] from helper::datasets_result()->items.
     * @param string $errorkind '' on success, otherwise the fetch error kind.
     * @param string $detail Technical detail (privileged view only).
     * @param string $id The stored dataset id.
     * @return reference_status
     */
    public static function classify_kb(array $items, string $errorkind, string $detail, string $id): reference_status {
        if ($id === '') {
            return new reference_status(reference_status::NOT_CONFIGURED, 'not_configured', '');
        }
        if ($errorkind !== '') {
            return new reference_status(reference_status::UNVERIFIED, self::reason_for_errorkind($errorkind), $id, null, $detail);
        }
        $ds = $items[$id] ?? null;
        if ($ds === null) {
            return new reference_status(reference_status::MISSING, 'kb_not_found', $id);
        }
        $name = (string) ($ds->name ?? $id);
        if ((int) ($ds->document_count ?? 0) === 0) {
            return new reference_status(reference_status::DEGRADED, 'kb_empty', $id, $name);
        }
        if ((int) ($ds->chunk_count ?? 0) === 0) {
            return new reference_status(reference_status::DEGRADED, 'kb_not_parsed', $id, $name);
        }
        return new reference_status(reference_status::OK, 'ok', $id, $name);
    }

    /**
     * Classify a memory id. Pure. Memories have no degraded state.
     *
     * @param array $items [id => name] from helper::memories_result()->items.
     * @param string $errorkind '' on success, otherwise the fetch error kind.
     * @param string $detail Technical detail (privileged view only).
     * @param string $id The stored memory id.
     * @return reference_status
     */
    public static function classify_memory(array $items, string $errorkind, string $detail, string $id): reference_status {
        if ($id === '') {
            return new reference_status(reference_status::NOT_CONFIGURED, 'not_configured', '');
        }
        if ($errorkind !== '') {
            return new reference_status(reference_status::UNVERIFIED, self::reason_for_errorkind($errorkind), $id, null, $detail);
        }
        if (!isset($items[$id])) {
            return new reference_status(reference_status::MISSING, 'memory_not_found', $id);
        }
        return new reference_status(reference_status::OK, 'ok', $id, (string) $items[$id]);
    }
}
