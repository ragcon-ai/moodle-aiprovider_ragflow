# Tests – aiprovider_ragflow

**Plugin version:** `2026082403` (release `0.6.11`) — update this line whenever the tests or the plugin
version change.

PHPUnit tests for this plugin. They run automatically in the bundled **moodle-plugin-ci** GitHub Actions
workflow; to run them locally, use `vendor/bin/phpunit` from a configured Moodle root (see the
[Moodle PHPUnit docs](https://moodledev.io/general/development/tools/phpunit)).

This file records **what the tests verify**, in **execution order** (PHPUnit runs the methods top-to-bottom
as defined in each class). Keep it in sync when tests are added, reordered or changed.

## Coverage

### `helper_test.php` — download token / URL helpers (`\aiprovider_ragflow\helper`)

| # | Test | Verifies |
|---|---|---|
| 1 | `test_sign_download_is_deterministic_and_bound` | `sign_download()` is a deterministic sha256 HMAC bound to provider + dataset + document + user + expiry — changing any field changes the signature. |
| 2 | `test_proxy_url_structure_and_signature` | `proxy_url()` returns `''` for invalid input, otherwise builds a signed `download.php` URL whose `sig` verifies against `sign_download()` for the embedded `expiry`. |
| 3 | `test_context_download_url_is_tokenless` | `context_download_url()` carries `contextid`/`action`/`dataset`/`document` and **no** signature (the durable, per-click-authorised link), and guards empty ids. |
| 4 | `test_token_ttl_default_configured_and_floor` | `token_ttl()` returns the default (60 s), honours a configured value (30 s), and floors it at the minimum (15 s). |
| 5 | `test_action_download_context_guards` | rejects unknown actions and returns `null` when no provider is configured (no RAGflow call is made). |
| 6 | `test_metadata_link` | `metadata_link()` builds a Moodle `mod/<type>/view.php?id=<id>` URL from full module metadata (trailing wwwroot slash trimmed), else falls back to `file_url`, then `page_url`, then `''`. |

### `chat_engine_test.php` — metadata filter & error classifier (`\aiprovider_ragflow\chat_engine`)

| # | Test | Verifies |
|---|---|---|
| 1 | `test_metadata_no_filter_sources` | data source **Whole knowledge base** and **This course** apply **no** metadata filter. |
| 2 | `test_metadata_external_sharing_filter` | **External Moodle** gates on `external_sharing = 1`. |
| 3 | `test_metadata_thismoodle_course_scope` | **This Moodle** scopes to the current course via the configured course-metadata field + the site URL (`moodle_url`). |
| 4 | `test_metadata_thismoodle_without_course` | **This Moodle** outside a real course (site context) applies no filter. |
| 5 | `test_classify_error` | maps raw failure text to a stable, coarse error type (`embedding_contextwindow` / `embedding` / `http_5xx` / `http_4xx` / `network` / `unexpected`), **including the exact detail strings the session/stateless helpers now emit** (`HTTP 502`, `request exception: …`, `RAGflow code 102: embedding …`). |
| 6 | `test_strip_markers` | `strip_markers()` removes inline `[ID:n]` citation markers and trims. |
| 7 | `test_scope_key` | the per-user memory scope key is `<component>-<userid>`. |
| 8 | `test_language_directive` | `language_directive()` asks the assistant to answer in the current Moodle language, naming the ISO code. |
| 9 | `test_strip_source_enumeration` | removes an inline "ID n …" source list (and its dangling label), leaves a plain answer untouched, and never strips the whole answer away. |
| 10 | `test_strip_prompt_augmentation` | a restored user turn is reduced to the original question — the leading "answer in language X" directive and the injected memory-facts block (up to the MEMORY delimiter) are removed; a plain question is unchanged. |
| 11 | `test_cited_sources` | builds the numbered source list from the answer's `[ID:n]` citations and appends one `Sources:` line of `[[n]]` sentinels, in first-cited order, deduped; a cited chunk is kept regardless of similarity (**no floor** — e.g. an image), and a citation to a missing chunk loses its marker. |
| 12 | `test_cited_sources_none_without_citations` | a reply that cites nothing (no `[ID:n]` markers — e.g. a *"no answer found"* reply) returns **no sources at all** even when candidate chunks are available, and appends no `Sources:` line — the guarantee that a not-found answer never shows a source. |
| 13 | `test_is_no_hit_answer` | recognises RAGflow's stock "no relevant content" replies (case-insensitive) so their sources are suppressed even when the assistant declined yet still cited a chunk; a real answer (even one that cites a source) is never treated as a no-hit reply. |

### `external/search_test.php` — search result shaping (`\aiprovider_ragflow\external\search`)

| # | Test | Verifies |
|---|---|---|
| 1 | `test_is_media` | `is_media()` recognises image/media file extensions (case-insensitive); text files (`.pdf`, `.txt`, no extension) are not media. |
| 2 | `test_rank_and_group_floor_and_dedup` | `rank_and_group()` ranks by relevance, keeps one entry per document, and drops text below the minimum-relevance floor. |
| 3 | `test_rank_and_group_cliff_and_cap` | the relevance "cliff" cuts a weak tail (score below `cliff × top`), and the result cap bounds the count. |
| 4 | `test_rank_and_group_media_group` | an image survives the **lower media floor** and is placed in its own group **after** the text results. |
| 5 | `test_block_config_defaults_and_clamps` | `block_config()` returns defaults for id 0 / a missing instance; for a real block instance it reads the config, **clamps** the quality knobs to sane ranges (out-of-range → bound, non-numeric → default), **trims + de-duplicates** dataset ids, and falls back an empty course field to `course_id`. |

### `local/health/checker_test.php` — reference classifiers (`\aiprovider_ragflow\local\health\checker`)

| # | Test | Verifies |
|---|---|---|
| 1 | `test_classify_assistant` | ok (bound) / degraded (`kb_not_bound`) / missing (`assistant_not_found`) / not-configured / **unverified** (per error kind: `api_timeout` / `api_unauthorized` / `api_unreachable`) — **missing is never conflated with unverified**. |
| 2 | `test_classify_kb` | ok / `kb_empty` (0 docs) / `kb_not_parsed` (0 chunks) / missing / unverified. |
| 3 | `test_classify_memory` | ok / missing / unverified / not-configured (memories have no degraded state). |
| 4 | `test_stale_option_label` | a missing reference reads "no longer in RAGflow", an unverified one "could not be verified" — never the same — both abbreviating the id to 8 chars + ellipsis. |

### `local/health/reference_status_test.php` — runtime allow/deny gate (`\aiprovider_ragflow\local\health\reference_status`)

| # | Test | Verifies |
|---|---|---|
| 1 | `test_is_ok` | `is_ok()` is true only for the OK state. |
| 2 | `test_is_usable` | `is_usable()` allows ok / degraded / **unverified** and blocks missing / not-configured — the runtime side of "missing ≠ unverified". |
| 3 | `test_constructor_defaults` | the constructor keeps the given fields and defaults `checkedat` to now, `fromcache` to false. |

## No Behat feature (by design)

The provider has **no end-user UI of its own** — its user-facing surfaces are the chat / search widgets
rendered by the consuming plugins (`block_ragflowtutor`, `block_ragflowsearch`, `aiplacement_ragflowhelpdesk`),
whose acceptance behaviour is smoke-tested in those plugins' `tests/behat`. The provider's own logic is
covered by the unit tests above, so a separate Behat feature here would only duplicate a version-specific
admin-navigation path.

## Deliberately not covered here (needs integration)

- Live RAGflow API paths (retrieval, chat completions, dataset/document listing) — require a running
  RAGflow tenant, so they are not exercised in unit tests. This includes the **error-cause propagation**
  through `post()` / `session_complete()` / `create_session()` (HTTP status, transport exception, RAGflow
  `{code, message}`); the string→`errortype` contract is unit-tested via `test_classify_error`, and the
  live path is verified manually (e.g. an unreachable RAGflow surfaces `HTTP 502`).
- The server-side capability gate (`aiprovider/ragflow:viewerrordetails`) in `chat_generate` — an
  integration surface (needs a role-assigned non-admin user + context), better suited to Behat.
- `download.php` end-to-end authorisation (login + context access + dataset-in-assistant) — better suited
  to a Behat/integration test than a unit test.
