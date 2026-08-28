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
 * The result of a single "is this RAGflow reference (assistant / knowledge base / memory) usable?" check —
 * the one value object every surface (forms, runtime, dashboard) renders. Its central promise is that
 * {@see reference_status::MISSING} (list loaded, reference not in it) and {@see reference_status::UNVERIFIED}
 * (list could not be loaded — connection/auth problem) are never conflated.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reference_status {
    /** @var string Reference resolved, exists, and (for an assistant) has a bound, parsed knowledge base. */
    const OK = 'ok';
    /** @var string Exists, but its knowledge base is missing / not yet parsed / has no documents. */
    const DEGRADED = 'degraded';
    /** @var string The list loaded successfully and the reference is not in it (it no longer exists). */
    const MISSING = 'missing';
    /** @var string The list could not be loaded (timeout/5xx/401/…); the saved value is probably fine. */
    const UNVERIFIED = 'unverified';
    /** @var string No reference is configured at all. */
    const NOT_CONFIGURED = 'not_configured';

    /** @var string One of the state constants above. */
    public string $state;
    /** @var string Machine-readable reason code (assistant_not_found, api_timeout, …). */
    public string $reason;
    /** @var string|null The stored reference id ('' / null when not configured). */
    public ?string $reference;
    /** @var string|null The resolved display name, when known. */
    public ?string $label;
    /** @var string|null Technical cause (HTTP status, RAGflow message) — only for users with viewerrordetails. */
    public ?string $detail;
    /** @var int Unix timestamp of when this result was produced. */
    public int $checkedat;
    /** @var bool Whether this result came from the shared cache. */
    public bool $fromcache;

    /**
     * Build a reference-status value object.
     *
     * @param string $state One of the state constants.
     * @param string $reason Machine-readable reason code.
     * @param string|null $reference The stored reference id.
     * @param string|null $label Resolved display name, if known.
     * @param string|null $detail Technical cause (privileged view only).
     * @param int $checkedat Unix timestamp (0 = now).
     * @param bool $fromcache Whether this came from the cache.
     */
    public function __construct(
        string $state,
        string $reason,
        ?string $reference = null,
        ?string $label = null,
        ?string $detail = null,
        int $checkedat = 0,
        bool $fromcache = false
    ) {
        $this->state = $state;
        $this->reason = $reason;
        $this->reference = $reference;
        $this->label = $label;
        $this->detail = $detail;
        $this->checkedat = $checkedat ?: time();
        $this->fromcache = $fromcache;
    }

    /**
     * Whether the reference is fully usable (resolved, exists, grounded).
     *
     * @return bool
     */
    public function is_ok(): bool {
        return $this->state === self::OK;
    }

    /**
     * Whether a request should be allowed to proceed at runtime. `missing` and `not_configured` block;
     * `unverified` is allowed through (the saved config is probably fine — the API is just unreachable),
     * `degraded` answers (just without a grounded knowledge base).
     *
     * @return bool
     */
    public function is_usable(): bool {
        return in_array($this->state, [self::OK, self::DEGRADED, self::UNVERIFIED], true);
    }
}
