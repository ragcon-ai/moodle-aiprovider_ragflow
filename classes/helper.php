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

use core\http_client;
use GuzzleHttp\RequestOptions;

/**
 * Helper for the RAGflow provider: talks to the RAGflow HTTP API.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /** @var int Default lifetime (seconds) of a signed proxy-download token (minted on click, used at once). */
    const DOWNLOAD_TOKEN_DEFAULT_TTL = 60;

    /** @var int Lower bound for the download-token lifetime, so a link stays clickable after it renders. */
    const DOWNLOAD_TOKEN_MIN_TTL = 15;

    /**
     * Clean assistant prompt template applied to assistants this plugin creates ({@see create_chat()}).
     *
     * RAGflow's default assistant prompt labels the retrieved chunks with 0-based ids ("ID: 0", "ID: 1")
     * and instructs the model to enumerate/cite them, so the answer text ends up containing a
     * "Sources / ID 0 / ID 1" list (the same document appearing twice when two of its chunks are used).
     * We show sources in a separate panel instead, so this template forbids that enumeration. It must
     * keep the {knowledge} placeholder – RAGflow injects the retrieved knowledge base there. (Existing
     * assistants cannot be changed via the API – RAGflow ignores prompt updates on PUT – so their output
     * is cleaned defensively in {@see \aiprovider_ragflow\chat_engine::strip_source_enumeration()}.)
     */
    const DEFAULT_CHAT_PROMPT =
        "You are a helpful assistant. Answer the question using only the information in the knowledge "
        . "base below, and reply in the same language as the question.\n\n"
        . "Do not list, enumerate or restate the knowledge-base entries, and do not output reference "
        . "identifiers (for example \"ID 0\", \"ID: 1\" or \"[ID:1]\") or a \"Sources\"/\"Documents\" "
        . "section – the application shows the sources separately. Just give the answer.\n\n"
        . "If the knowledge base contains nothing relevant to the question, say so briefly.\n\n"
        . "Knowledge base:\n{knowledge}";

    /**
     * GET a RAGflow API path and return the decoded body, or null on any error.
     *
     * @param string $baseurl RAGflow base URL.
     * @param string $apikey RAGflow API key.
     * @param string $path API path (starting with /).
     * @return \stdClass|null
     */
    protected static function get(string $baseurl, string $apikey, string $path): ?\stdClass {
        return self::get_result($baseurl, $apikey, $path)->data;
    }

    /**
     * GET a RAGflow API path and return a structured result that distinguishes the error KIND, so a caller
     * can tell "the reference genuinely does not exist" (a successfully loaded list that does not contain
     * it) from "the API could not be reached" (timeout / connection / auth / server error). {@see get()} is
     * the thin body-or-null wrapper for callers that do not need the distinction.
     *
     * @param string $baseurl RAGflow base URL.
     * @param string $apikey RAGflow API key.
     * @param string $path API path (starting with /).
     * @return \stdClass {data: \stdClass|null, errorkind: string, status: int, detail: string} – errorkind is
     *   '' on success, otherwise one of not_configured|timeout|unreachable|unauthorized|http|malformed.
     */
    protected static function get_result(string $baseurl, string $apikey, string $path): \stdClass {
        $fail = function (string $kind, int $status, string $detail): \stdClass {
            return (object) ['data' => null, 'errorkind' => $kind, 'status' => $status, 'detail' => $detail];
        };
        $baseurl = rtrim($baseurl, '/');
        if ($baseurl === '' || $apikey === '') {
            return $fail('not_configured', 0, '');
        }
        $url = $baseurl . $path;
        $start = microtime(true);
        try {
            $client = \core\di::get(http_client::class);
            $response = $client->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $apikey],
                'timeout' => 10,
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $kind = (stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false)
                ? 'timeout' : 'unreachable';
            self::log_apicall($url, [], 0, '', false, $start, 'request exception: ' . $msg, 'GET');
            return $fail($kind, 0, 'request exception: ' . $msg);
        }
        $status = $response->getStatusCode();
        $raw = $response->getBody()->getContents();
        if ($status !== 200) {
            $kind = ($status === 401 || $status === 403) ? 'unauthorized' : (($status >= 500) ? 'unreachable' : 'http');
            self::log_apicall($url, [], $status, $raw, false, $start, 'HTTP ' . $status, 'GET');
            return $fail($kind, $status, 'HTTP ' . $status);
        }
        $body = json_decode($raw);
        if (!($body instanceof \stdClass)) {
            self::log_apicall($url, [], $status, $raw, false, $start, 'malformed body (not a JSON object)', 'GET');
            return $fail('malformed', $status, 'malformed body (not a JSON object)');
        }
        self::log_apicall($url, [], $status, $raw, true, $start, '', 'GET');
        return (object) ['data' => $body, 'errorkind' => '', 'status' => $status, 'detail' => ''];
    }

    /**
     * The tenant's chat assistants with load status, for the reference checker and health checks. Returns
     * the parsed items keyed by id AND the error kind, so callers can distinguish "not in the list" from
     * "list could not be loaded".
     *
     * @param string $baseurl RAGflow base URL.
     * @param string $apikey RAGflow API key.
     * @return \stdClass {items: array [id => {name, kb}], errorkind: string, detail: string}
     */
    public static function chats_result(string $baseurl, string $apikey): \stdClass {
        $r = self::get_result($baseurl, $apikey, '/api/v1/chats?page=1&page_size=100');
        if ($r->data === null) {
            return (object) ['items' => [], 'errorkind' => $r->errorkind, 'detail' => $r->detail];
        }
        $data = $r->data->data ?? null;
        $chats = (is_object($data) && isset($data->chats)) ? $data->chats : $data;
        $items = [];
        if (is_array($chats)) {
            foreach ($chats as $chat) {
                if (!empty($chat->id)) {
                    $items[$chat->id] = (object) [
                        'name' => $chat->name ?? $chat->id,
                        'kb' => is_array($chat->dataset_ids ?? null) ? count($chat->dataset_ids) : 0,
                    ];
                }
            }
        }
        return (object) ['items' => $items, 'errorkind' => '', 'detail' => ''];
    }

    /**
     * The tenant's datasets with parse status AND the error kind (see {@see chats_result()}).
     *
     * @param string $baseurl RAGflow base URL.
     * @param string $apikey RAGflow API key.
     * @return \stdClass {items: array [id => {name, chunk_count, document_count}], errorkind: string, detail: string}
     */
    public static function datasets_result(string $baseurl, string $apikey): \stdClass {
        $r = self::get_result($baseurl, $apikey, '/api/v1/datasets?page=1&page_size=100');
        if ($r->data === null) {
            return (object) ['items' => [], 'errorkind' => $r->errorkind, 'detail' => $r->detail];
        }
        $data = $r->data->data ?? null;
        $list = (is_object($data) && isset($data->datasets)) ? $data->datasets : $data;
        $items = [];
        if (is_array($list)) {
            foreach ($list as $dataset) {
                if (!empty($dataset->id)) {
                    $items[$dataset->id] = (object) [
                        'name' => $dataset->name ?? $dataset->id,
                        'chunk_count' => (int) ($dataset->chunk_count ?? 0),
                        'document_count' => (int) ($dataset->document_count ?? 0),
                    ];
                }
            }
        }
        return (object) ['items' => $items, 'errorkind' => '', 'detail' => ''];
    }

    /**
     * The tenant's memories AND the error kind (see {@see chats_result()}).
     *
     * @param string $baseurl RAGflow base URL.
     * @param string $apikey RAGflow API key.
     * @return \stdClass {items: array [id => name], errorkind: string, detail: string}
     */
    public static function memories_result(string $baseurl, string $apikey): \stdClass {
        $r = self::get_result($baseurl, $apikey, '/api/v1/memories?page=1&page_size=100');
        if ($r->data === null) {
            return (object) ['items' => [], 'errorkind' => $r->errorkind, 'detail' => $r->detail];
        }
        $data = $r->data->data ?? null;
        $list = (is_object($data) && isset($data->memory_list)) ? $data->memory_list : $data;
        $items = [];
        if (is_array($list)) {
            foreach ($list as $memory) {
                if (!empty($memory->id)) {
                    $items[$memory->id] = $memory->name ?? $memory->id;
                }
            }
        }
        return (object) ['items' => $items, 'errorkind' => '', 'detail' => ''];
    }

    /**
     * List the tenant's chat assistants for a dropdown.
     *
     * @param string $baseurl RAGflow base URL.
     * @param string $apikey RAGflow API key.
     * @return array [chat id => assistant name]
     */
    public static function get_chats(string $baseurl, string $apikey): array {
        $out = [];
        foreach (self::get_chats_detailed($baseurl, $apikey) as $id => $chat) {
            $out[$id] = $chat->name;
        }
        return $out;
    }

    /**
     * List the tenant's chat assistants with the number of knowledge bases each is bound to, so a
     * dropdown can show which assistants are pure LLM proxies (0 = no KB) vs RAG-grounded.
     *
     * @param string $baseurl RAGflow base URL.
     * @param string $apikey RAGflow API key.
     * @return array [chat id => \stdClass {name, kb}] sorted by name
     */
    public static function get_chats_detailed(string $baseurl, string $apikey): array {
        $out = self::chats_result($baseurl, $apikey)->items;
        uasort($out, function ($a, $b) {
            return strcasecmp($a->name, $b->name);
        });
        return $out;
    }

    /**
     * List the tenant's RAGflow memories for a dropdown.
     *
     * @param string $baseurl RAGflow base URL.
     * @param string $apikey RAGflow API key.
     * @return array [memory id => memory name]
     */
    public static function get_memories(string $baseurl, string $apikey): array {
        $out = self::memories_result($baseurl, $apikey)->items;
        asort($out);
        return $out;
    }

    /**
     * List the tenant's RAGflow datasets (knowledge bases) for a dropdown.
     *
     * @param string $baseurl RAGflow base URL.
     * @param string $apikey RAGflow API key.
     * @return array [dataset id => dataset name]
     */
    public static function get_datasets(string $baseurl, string $apikey): array {
        // RAGflow caps page_size at 100 (a larger value returns an app-level error, not a 200 list).
        $body = self::get($baseurl, $apikey, '/api/v1/datasets?page=1&page_size=100');
        if ($body === null) {
            return [];
        }
        // The data may be a bare list or {datasets: [...]} depending on the version.
        $data = $body->data ?? null;
        $list = (is_object($data) && isset($data->datasets)) ? $data->datasets : $data;
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $dataset) {
            if (!empty($dataset->id)) {
                $out[$dataset->id] = $dataset->name ?? $dataset->id;
            }
        }
        asort($out);
        return $out;
    }

    /**
     * Whether RAGflow is reachable and the API key is accepted (a lightweight probe – true when the
     * datasets endpoint answers with a usable body).
     *
     * @param string $baseurl
     * @param string $apikey
     * @return bool
     */
    public static function ping(string $baseurl, string $apikey): bool {
        return self::get($baseurl, $apikey, '/api/v1/datasets?page=1&page_size=1') !== null;
    }

    /**
     * The tenant's datasets with parse status, for the dashboard's health checks. One call.
     *
     * @param string $baseurl
     * @param string $apikey
     * @return array [dataset id => \stdClass {name, chunk_count, document_count}]
     */
    public static function get_datasets_detailed(string $baseurl, string $apikey): array {
        return self::datasets_result($baseurl, $apikey)->items;
    }

    /**
     * Dataset list [id => name] for a provider, cached briefly (so the search-block config autocomplete
     * can filter server-side per keystroke without hitting RAGflow every time).
     *
     * @param int $providerid Provider instance id (cache key).
     * @param string $baseurl RAGflow base URL.
     * @param string $apikey RAGflow API key.
     * @return array [dataset id => dataset name]
     */
    public static function datasets_cached(int $providerid, string $baseurl, string $apikey): array {
        $cache = \cache::make('aiprovider_ragflow', 'datasets');
        $cached = $cache->get($providerid);
        if (is_array($cached)) {
            return $cached;
        }
        $list = self::get_datasets($baseurl, $apikey);
        // Only cache a non-empty result, so a transient API hiccup is retried rather than stuck empty.
        if (!empty($list)) {
            $cache->set($providerid, $list);
        }
        return $list;
    }

    /**
     * PUT a RAGflow API path with a JSON body; returns the decoded body or null on transport error.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $path
     * @param array $payload
     * @return \stdClass|null
     */
    protected static function put(string $baseurl, string $apikey, string $path, array $payload): ?\stdClass {
        $baseurl = rtrim($baseurl, '/');
        if ($baseurl === '' || $apikey === '') {
            return null;
        }
        try {
            $client = \core\di::get(http_client::class);
            $response = $client->request('PUT', $baseurl . $path, [
                'headers' => ['Authorization' => 'Bearer ' . $apikey, 'Content-Type' => 'application/json'],
                'body' => json_encode($payload),
                'timeout' => 20,
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (\Throwable $e) {
            return null;
        }
        if ($response->getStatusCode() !== 200) {
            return null;
        }
        $body = json_decode($response->getBody()->getContents());
        return ($body instanceof \stdClass) ? $body : null;
    }

    /**
     * True if a dataset with exactly this name already exists (RAGflow does NOT enforce unique names,
     * so uniqueness is checked here). Case-insensitive.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $name
     * @return bool
     */
    public static function dataset_name_exists(string $baseurl, string $apikey, string $name): bool {
        $name = trim($name);
        foreach (self::get_datasets($baseurl, $apikey) as $dsname) {
            if (strcasecmp(trim((string) $dsname), $name) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * True if a chat assistant with exactly this name already exists (case-insensitive).
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $name
     * @return bool
     */
    public static function chat_name_exists(string $baseurl, string $apikey, string $name): bool {
        $name = trim($name);
        foreach (self::get_chats($baseurl, $apikey) as $cname) {
            if (strcasecmp(trim((string) $cname), $name) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create a RAGflow dataset (knowledge base).
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $name
     * @param string $embeddingmodel Optional embedding model id (empty = RAGflow tenant default).
     * @return array {id: string, error: string} – id set on success, else error holds the RAGflow message.
     */
    public static function create_dataset(string $baseurl, string $apikey, string $name, string $embeddingmodel = ''): array {
        $payload = ['name' => trim($name)];
        if (trim($embeddingmodel) !== '') {
            $payload['embedding_model'] = trim($embeddingmodel);
        }
        $body = self::post($baseurl, $apikey, '/api/v1/datasets', $payload);
        if ($body === null) {
            return ['id' => '', 'error' => 'request'];
        }
        if ((int) ($body->code ?? -1) !== 0) {
            return ['id' => '', 'error' => (string) ($body->message ?? 'error')];
        }
        return ['id' => (string) ($body->data->id ?? ''), 'error' => ''];
    }

    /**
     * Create a RAGflow chat assistant. dataset_ids may be empty (a pure-LLM assistant); a dataset can be
     * bound later via {@see ensure_kb_bound()} once it has parsed documents.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $name
     * @param string[] $datasetids
     * @param string $llm Optional model name (empty = RAGflow tenant default).
     * @return array {id: string, error: string}
     */
    public static function create_chat(
        string $baseurl,
        string $apikey,
        string $name,
        array $datasetids = [],
        string $llm = ''
    ): array {
        $payload = [
            'name' => trim($name),
            // Suppress RAGflow's default "ID 0 / ID 1 / Sources" enumeration in the answer text; we show
            // sources in a separate panel.
            'prompt' => [
                'prompt' => self::DEFAULT_CHAT_PROMPT,
                'show_quote' => false,
                'variables' => [['key' => 'knowledge', 'optional' => false]],
            ],
        ];
        if (!empty($datasetids)) {
            $payload['dataset_ids'] = array_values($datasetids);
        }
        if (trim($llm) !== '') {
            $payload['llm'] = ['model_name' => trim($llm)];
        }
        $body = self::post($baseurl, $apikey, '/api/v1/chats', $payload);
        if ($body === null) {
            return ['id' => '', 'error' => 'request'];
        }
        if ((int) ($body->code ?? -1) !== 0) {
            return ['id' => '', 'error' => (string) ($body->message ?? 'error')];
        }
        return ['id' => (string) ($body->data->id ?? ''), 'error' => ''];
    }

    /**
     * Bind a dataset to a chat assistant once the dataset has parsed content. Idempotent and cached once
     * bound: RAGflow refuses the binding (code 102) while the dataset has no parsed document, so this is
     * retried on later calls until it succeeds.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $chatid
     * @param string $kbid Dataset id to bind.
     * @return bool True if the chat is (now) bound to the dataset.
     */
    public static function ensure_kb_bound(string $baseurl, string $apikey, string $chatid, string $kbid): bool {
        $chatid = trim($chatid);
        $kbid = trim($kbid);
        if ($chatid === '' || $kbid === '') {
            return false;
        }
        $cache = \cache::make('aiprovider_ragflow', 'kbbound');
        $ckey = $chatid . '|' . $kbid;
        if ($cache->get($ckey)) {
            return true;
        }
        $current = self::get_chat_datasets($baseurl, $apikey, $chatid);
        if (in_array($kbid, $current, true)) {
            $cache->set($ckey, 1);
            return true;
        }
        $body = self::put(
            $baseurl,
            $apikey,
            '/api/v1/chats/' . urlencode($chatid),
            ['dataset_ids' => array_values(array_unique(array_merge($current, [$kbid])))]
        );
        if ($body !== null && (int) ($body->code ?? -1) === 0) {
            $cache->set($ckey, 1);
            return true;
        }
        return false;
    }

    /**
     * Upload a small in-memory text document to a dataset. Returns the new document id, or '' on failure.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $datasetid
     * @param string $filename
     * @param string $content
     * @return string
     */
    public static function upload_text_document(
        string $baseurl,
        string $apikey,
        string $datasetid,
        string $filename,
        string $content
    ): string {
        $baseurl = rtrim($baseurl, '/');
        $datasetid = trim($datasetid);
        if ($baseurl === '' || $apikey === '' || $datasetid === '') {
            return '';
        }
        try {
            $client = \core\di::get(http_client::class);
            $response = $client->request(
                'POST',
                $baseurl . '/api/v1/datasets/' . urlencode($datasetid) . '/documents',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $apikey],
                    'multipart' => [['name' => 'file', 'contents' => $content, 'filename' => $filename]],
                    'timeout' => 60,
                    RequestOptions::HTTP_ERRORS => false,
                ]
            );
        } catch (\Throwable $e) {
            return '';
        }
        if ($response->getStatusCode() !== 200) {
            return '';
        }
        $body = json_decode($response->getBody()->getContents());
        $data = $body->data ?? null;
        if (is_array($data) && isset($data[0]->id)) {
            return (string) $data[0]->id;
        }
        if (is_object($data) && isset($data->id)) {
            return (string) $data->id;
        }
        return '';
    }

    /**
     * Trigger asynchronous parsing of documents in a dataset.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $datasetid
     * @param string[] $docids
     * @return bool True if RAGflow accepted the parse request.
     */
    public static function parse_documents(string $baseurl, string $apikey, string $datasetid, array $docids): bool {
        if (empty($docids)) {
            return false;
        }
        $body = self::post(
            $baseurl,
            $apikey,
            '/api/v1/datasets/' . urlencode(trim($datasetid)) . '/chunks',
            ['document_ids' => array_values($docids)]
        );
        return $body !== null && (int) ($body->code ?? -1) === 0;
    }

    /**
     * Number of parsed chunks a document has (>0 means it is parsed and usable). -1 on error / not found.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $datasetid
     * @param string $docid
     * @return int
     */
    public static function document_chunk_count(string $baseurl, string $apikey, string $datasetid, string $docid): int {
        $body = self::get($baseurl, $apikey, '/api/v1/datasets/' . urlencode(trim($datasetid)) . '/documents');
        if ($body === null) {
            return -1;
        }
        $docs = $body->data->docs ?? null;
        if (!is_array($docs)) {
            return -1;
        }
        foreach ($docs as $doc) {
            if ((string) ($doc->id ?? '') === trim($docid)) {
                return (int) ($doc->chunk_count ?? ($doc->chunk_num ?? 0));
            }
        }
        return -1;
    }

    /**
     * Delete all chunks of a document while keeping the document listed (so a provenance file stays visible
     * in RAGflow but never contributes to retrieval / answers).
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $datasetid
     * @param string $docid
     * @return bool
     */
    public static function clear_document_chunks(string $baseurl, string $apikey, string $datasetid, string $docid): bool {
        $path = '/api/v1/datasets/' . urlencode(trim($datasetid)) . '/documents/' . urlencode(trim($docid)) . '/chunks';
        $body = self::get($baseurl, $apikey, $path);
        if ($body === null) {
            return false;
        }
        $chunks = $body->data->chunks ?? ($body->data ?? null);
        $ids = [];
        if (is_array($chunks)) {
            foreach ($chunks as $chunk) {
                $id = $chunk->id ?? ($chunk->chunk_id ?? null);
                if ($id) {
                    $ids[] = $id;
                }
            }
        }
        if (empty($ids)) {
            return true;
        }
        return self::delete($baseurl, $apikey, $path, ['chunk_ids' => array_values($ids)]);
    }

    /**
     * Complete the seed-and-link once, without waiting: if the seed document is parsed, bind the assistant
     * to the KB and clear the seed's chunks (so it stops contributing to retrieval). Idempotent.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $datasetid
     * @param string $chatid
     * @param string $docid Seed document id.
     * @return string 'linked' (done), 'pending' (seed not parsed yet – retry later), 'error'.
     */
    public static function try_finish_seed(
        string $baseurl,
        string $apikey,
        string $datasetid,
        string $chatid,
        string $docid
    ): string {
        if (trim($chatid) === '' || trim($datasetid) === '') {
            return 'error';
        }
        $count = self::document_chunk_count($baseurl, $apikey, $datasetid, $docid);
        if ($count < 0) {
            // Transient read error, or the seed was already cleared: if the chat is bound, we are done.
            return in_array(trim($datasetid), self::get_chat_datasets($baseurl, $apikey, $chatid), true)
                ? 'linked' : 'pending';
        }
        if ($count === 0) {
            // Either not parsed yet, or parsed + already cleared (then the chat is bound → done).
            return in_array(trim($datasetid), self::get_chat_datasets($baseurl, $apikey, $chatid), true)
                ? 'linked' : 'pending';
        }
        // Parsed: bind now (RAGflow accepts it once the KB owns a parsed file), then clear the seed chunks.
        \cache::make('aiprovider_ragflow', 'kbbound')->delete(trim($chatid) . '|' . trim($datasetid));
        if (!self::ensure_kb_bound($baseurl, $apikey, $chatid, $datasetid)) {
            return 'pending';
        }
        self::clear_document_chunks($baseurl, $apikey, $datasetid, $docid);
        return 'linked';
    }

    /**
     * List a dataset's documents with their parse status, for the block file manager.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $datasetid
     * @return array Array of {id, name, run, chunkcount} (run: RAGflow parse state, e.g. DONE/RUNNING/FAIL).
     */
    public static function list_documents(string $baseurl, string $apikey, string $datasetid): array {
        // RAGflow caps page_size at 100 (a larger value returns an app-level error, not a list).
        $body = self::get(
            $baseurl,
            $apikey,
            '/api/v1/datasets/' . urlencode(trim($datasetid)) . '/documents?page=1&page_size=100'
        );
        $docs = $body->data->docs ?? null;
        if (!is_array($docs)) {
            return [];
        }
        $out = [];
        foreach ($docs as $doc) {
            if (empty($doc->id)) {
                continue;
            }
            $out[] = (object) [
                'id' => (string) $doc->id,
                'name' => (string) ($doc->name ?? $doc->id),
                'run' => (string) ($doc->run ?? ''),
                'chunkcount' => (int) ($doc->chunk_count ?? ($doc->chunk_num ?? 0)),
                'message' => trim((string) ($doc->progress_msg ?? '')),
            ];
        }
        return $out;
    }

    /**
     * Delete a document (and its chunks) from a dataset.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $datasetid
     * @param string $docid
     * @return bool
     */
    public static function delete_document(string $baseurl, string $apikey, string $datasetid, string $docid): bool {
        return self::delete(
            $baseurl,
            $apikey,
            '/api/v1/datasets/' . urlencode(trim($datasetid)) . '/documents',
            ['ids' => [trim($docid)]]
        );
    }

    /**
     * The name of a dataset (knowledge base), or '' if it does not exist / on error.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $datasetid
     * @return string
     */
    public static function dataset_name(string $baseurl, string $apikey, string $datasetid): string {
        $datasetid = trim($datasetid);
        if ($datasetid === '') {
            return '';
        }
        $body = self::get($baseurl, $apikey, '/api/v1/datasets?id=' . urlencode($datasetid));
        $data = $body->data ?? null;
        $list = (is_object($data) && isset($data->datasets)) ? $data->datasets : $data;
        if (is_array($list) && isset($list[0]->name)) {
            return (string) $list[0]->name;
        }
        return '';
    }

    /**
     * Map each document of a dataset to a link into its source Moodle (activity view, else the file/page
     * URL), built from the document's metadata. Used to link sources when the secure proxy is off. Cached
     * briefly per dataset. Returns [document id => url].
     *
     * @param int $providerid Cache key.
     * @param string $baseurl
     * @param string $apikey
     * @param string $datasetid
     * @return array [document id => url]
     */
    public static function dataset_document_urls(
        int $providerid,
        string $baseurl,
        string $apikey,
        string $datasetid
    ): array {
        $datasetid = trim($datasetid);
        if ($datasetid === '') {
            return [];
        }
        $cache = \cache::make('aiprovider_ragflow', 'docurls');
        $ckey = $providerid . '|' . $datasetid;
        $cached = $cache->get($ckey);
        if (is_array($cached)) {
            return $cached;
        }
        $body = self::get($baseurl, $apikey, '/api/v1/datasets/' . urlencode($datasetid) . '/documents?page=1&page_size=100');
        $data = $body->data ?? null;
        $docs = (is_object($data) && isset($data->docs)) ? $data->docs
            : ((is_object($data) && isset($data->documents)) ? $data->documents : $data);
        $out = [];
        if (is_array($docs)) {
            foreach ($docs as $doc) {
                $id = (string) ($doc->id ?? '');
                if ($id === '') {
                    continue;
                }
                $md = $doc->meta_fields ?? $doc->metadata ?? null;
                $out[$id] = is_object($md) ? self::metadata_link($md) : '';
            }
        }
        if (!empty($out)) {
            $cache->set($ckey, $out);
        }
        return $out;
    }

    /**
     * Build a link into the source Moodle from a document's metadata: the activity view URL when the
     * module info is present, else the file / page URL. Empty when nothing usable is available.
     *
     * @param \stdClass $md Document metadata.
     * @return string
     */
    public static function metadata_link(\stdClass $md): string {
        $moodleurl = rtrim((string) ($md->moodle_url ?? ''), '/');
        $modtype = (string) ($md->module_type ?? '');
        $modid = (string) ($md->module_id ?? '');
        if ($moodleurl !== '' && $modtype !== '' && $modid !== '') {
            return "{$moodleurl}/mod/{$modtype}/view.php?id={$modid}";
        }
        return (string) ($md->file_url ?? ($md->page_url ?? ''));
    }

    /**
     * Get the llm_id configured on a chat assistant (the model RAGflow actually uses).
     *
     * @param string $baseurl RAGflow base URL.
     * @param string $apikey RAGflow API key.
     * @param string $chatid RAGflow chat assistant id.
     * @return string The llm_id, or '' if it could not be determined.
     */
    public static function get_chat_llmid(string $baseurl, string $apikey, string $chatid): string {
        $chatid = trim($chatid);
        if ($chatid === '') {
            return '';
        }
        $body = self::get($baseurl, $apikey, '/api/v1/chats?id=' . urlencode($chatid));
        if ($body === null) {
            return '';
        }
        $data = $body->data ?? null;
        $chats = (is_object($data) && isset($data->chats)) ? $data->chats : $data;
        if (is_array($chats) && !empty($chats)) {
            return trim($chats[0]->llm_id ?? '');
        }
        return '';
    }

    /**
     * Per-site secret used to sign proxy-download tokens (generated once).
     *
     * @return string
     */
    public static function token_secret(): string {
        $secret = get_config('aiprovider_ragflow', 'tokensecret');
        if (empty($secret)) {
            $secret = bin2hex(random_bytes(32));
            set_config('tokensecret', $secret, 'aiprovider_ragflow');
        }
        return $secret;
    }

    /**
     * HMAC signature binding a proxy download to a provider, document, user and expiry.
     *
     * @param int $providerid
     * @param string $dataset RAGflow dataset id.
     * @param string $document RAGflow document id.
     * @param int $userid Moodle user id allowed to download.
     * @param int $expiry Unix time after which the token is invalid.
     * @return string
     */
    public static function sign_download(int $providerid, string $dataset, string $document, int $userid, int $expiry): string {
        $payload = implode('|', [$providerid, $dataset, $document, $userid, $expiry]);
        return hash_hmac('sha256', $payload, self::token_secret());
    }

    /**
     * Build a signed, time-limited proxy URL that streams a RAGflow document server-side.
     *
     * @param int $providerid
     * @param string $dataset RAGflow dataset id.
     * @param string $document RAGflow document id.
     * @param int $userid Moodle user id allowed to download.
     * @return string Empty if any id is missing.
     */
    public static function proxy_url(int $providerid, string $dataset, string $document, int $userid): string {
        if ($providerid <= 0 || $dataset === '' || $document === '' || $userid <= 0) {
            return '';
        }
        $expiry = time() + self::token_ttl($providerid);
        return (new \moodle_url('/ai/provider/ragflow/download.php', [
            'providerid' => $providerid,
            'dataset' => $dataset,
            'document' => $document,
            'userid' => $userid,
            'expiry' => $expiry,
            'sig' => self::sign_download($providerid, $dataset, $document, $userid, $expiry),
        ]))->out(false);
    }

    /**
     * Build a **token-less, durable** proxy URL for a source document, authorised **per click** by
     * `download.php` (login + access to the given context + the document belonging to the action's
     * assistant knowledge base) rather than by a time-limited signature. Use this for links that are
     * embedded into **saved** Moodle content (the generated-text placement) where a signed token would
     * expire; on-click JS is not available there, so the endpoint authorises each request live.
     *
     * @param int $contextid The context the generated content lives in (checked at click time).
     * @param string $action The text action short name (generate_text|summarise_text|explain_text).
     * @param string $dataset RAGflow dataset id.
     * @param string $document RAGflow document id.
     * @return string Empty if any id is missing.
     */
    public static function context_download_url(int $contextid, string $action, string $dataset, string $document): string {
        if ($contextid <= 0 || $action === '' || $dataset === '' || $document === '') {
            return '';
        }
        return (new \moodle_url('/ai/provider/ragflow/download.php', [
            'contextid' => $contextid,
            'action' => $action,
            'dataset' => $dataset,
            'document' => $document,
        ]))->out(false);
    }

    /**
     * Resolve, for a text action, the enabled provider's credentials and the set of datasets its configured
     * assistant can access – used by `download.php` to authorise a token-less context download (the requested
     * dataset must be one of these, so a crafted URL cannot pull an unrelated knowledge base). Returns null
     * when the provider/action is not configured.
     *
     * @param string $action The action short name (generate_text|summarise_text|explain_text).
     * @return \stdClass|null {providerid, base, key, datasets[]}
     */
    public static function action_download_context(string $action): ?\stdClass {
        global $DB;
        if (!in_array($action, ['generate_text', 'summarise_text', 'explain_text'], true)) {
            return null;
        }
        $prov = $DB->get_record_select(
            'ai_providers',
            'provider = :p AND enabled = 1',
            ['p' => provider::class],
            '*',
            IGNORE_MULTIPLE
        );
        if (!$prov) {
            return null;
        }
        $conf = json_decode($prov->config, true) ?: [];
        $base = rtrim((string) ($conf['baseurl'] ?? ''), '/');
        $key = (string) ($conf['apikey'] ?? '');
        $actionconf = json_decode($prov->actionconfig, true) ?: [];
        $chatid = trim((string) ($actionconf['core_ai\\aiactions\\' . $action]['settings']['chatid'] ?? ''));
        if ($base === '' || $key === '' || $chatid === '') {
            return null;
        }
        return (object) [
            'providerid' => (int) $prov->id,
            'base' => $base,
            'key' => $key,
            'datasets' => self::get_chat_datasets($base, $key, $chatid),
        ];
    }

    /**
     * The configured lifetime (seconds) of a signed proxy-download token for a provider instance. Read from
     * the instance's `tokenttl` config, floored at {@see DOWNLOAD_TOKEN_MIN_TTL} and defaulting to
     * {@see DOWNLOAD_TOKEN_DEFAULT_TTL}. Cached per request (the file manager builds many URLs).
     *
     * @param int $providerid
     * @return int
     */
    public static function token_ttl(int $providerid): int {
        global $DB;
        static $cache = [];
        if (isset($cache[$providerid])) {
            return $cache[$providerid];
        }
        $ttl = self::DOWNLOAD_TOKEN_DEFAULT_TTL;
        $record = $DB->get_record('ai_providers', ['id' => $providerid], 'config', IGNORE_MISSING);
        if ($record) {
            $conf = json_decode($record->config, true) ?: [];
            $set = (int) ($conf['tokenttl'] ?? 0);
            if ($set > 0) {
                $ttl = max(self::DOWNLOAD_TOKEN_MIN_TTL, $set);
            }
        }
        return $cache[$providerid] = $ttl;
    }

    /**
     * POST JSON to a RAGflow API path and return the decoded body, or null on any error.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $path
     * @param array $payload
     * @param string $errordetail Out: the technical failure cause on null return (admin-only – may reveal
     *                            server-side internals). Empty on success. The caller may still inspect the
     *                            returned body's own `code`/`message` for an app-level error (HTTP 200).
     * @return \stdClass|null
     */
    protected static function post(
        string $baseurl,
        string $apikey,
        string $path,
        array $payload,
        string &$errordetail = ''
    ): ?\stdClass {
        $errordetail = '';
        $baseurl = rtrim($baseurl, '/');
        $url = $baseurl . $path;
        $start = microtime(true);
        if ($baseurl === '' || $apikey === '') {
            $errordetail = 'not configured (missing base URL or API key)';
            self::log_apicall($url, $payload, 0, '', false, $start, $errordetail);
            return null;
        }
        try {
            $client = \core\di::get(http_client::class);
            $response = $client->request('POST', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $apikey, 'Content-Type' => 'application/json'],
                'body' => json_encode($payload),
                'timeout' => 20,
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (\Throwable $e) {
            $errordetail = 'request exception: ' . $e->getMessage();
            self::log_apicall($url, $payload, 0, '', false, $start, $errordetail);
            return null;
        }
        $status = $response->getStatusCode();
        $raw = $response->getBody()->getContents();
        if ($status !== 200) {
            $errordetail = 'HTTP ' . $status;
            self::log_apicall($url, $payload, $status, $raw, false, $start, $errordetail);
            return null;
        }
        $body = json_decode($raw);
        if (!($body instanceof \stdClass)) {
            $errordetail = 'malformed body (not a JSON object)';
            self::log_apicall($url, $payload, $status, $raw, false, $start, $errordetail);
            return null;
        }
        self::log_apicall($url, $payload, $status, $raw, true, $start, '');
        return $body;
    }

    /**
     * Best-effort raw API-call log for the optional RAGflow Dashboard. Records the request (URL + JSON
     * payload) and the raw response of a single RAGflow HTTP call – but only if the dashboard is installed
     * AND an admin has switched raw API logging on there. The **Authorization header (API key) is never
     * passed**, so it cannot be logged. A failure here must never affect the request.
     *
     * @param string $url The request URL (no credentials).
     * @param array $payload The JSON request payload.
     * @param int $status The HTTP status (0 if the request never completed).
     * @param string $raw The raw response body.
     * @param bool $success Whether the call yielded a usable JSON object.
     * @param float $start microtime(true) captured before the request.
     * @param string $errordetail The technical failure cause (empty on success).
     * @param string $method The HTTP method (default POST).
     * @return void
     */
    private static function log_apicall(
        string $url,
        array $payload,
        int $status,
        string $raw,
        bool $success,
        float $start,
        string $errordetail,
        string $method = 'POST'
    ): void {
        if (!class_exists('\local_ragflowdashboard\api')) {
            return;
        }
        try {
            $request = (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            \local_ragflowdashboard\api::capture_apicall(
                $method,
                $url,
                $request,
                $status,
                $raw,
                $success,
                (int) round((microtime(true) - $start) * 1000),
                $errordetail
            );
        } catch (\Throwable $e) {
            debugging('aiprovider_ragflow: raw API-log capture failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * DELETE a RAGflow API path with a JSON body; true when the API returns code 0.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $path
     * @param array $payload
     * @return bool
     */
    protected static function delete(string $baseurl, string $apikey, string $path, array $payload): bool {
        $baseurl = rtrim($baseurl, '/');
        if ($baseurl === '' || $apikey === '') {
            return false;
        }
        try {
            $client = \core\di::get(http_client::class);
            $response = $client->request('DELETE', $baseurl . $path, [
                'headers' => ['Authorization' => 'Bearer ' . $apikey, 'Content-Type' => 'application/json'],
                'body' => json_encode($payload),
                'timeout' => 20,
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (\Throwable $e) {
            return false;
        }
        if ($response->getStatusCode() !== 200) {
            return false;
        }
        $body = json_decode($response->getBody()->getContents());
        return ($body instanceof \stdClass) && (int) ($body->code ?? -1) === 0;
    }

    /**
     * Dataset ids a chat assistant is bound to.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $chatid
     * @return string[]
     */
    public static function get_chat_datasets(string $baseurl, string $apikey, string $chatid): array {
        $chatid = trim($chatid);
        if ($chatid === '') {
            return [];
        }
        $body = self::get($baseurl, $apikey, '/api/v1/chats?id=' . urlencode($chatid));
        if ($body === null) {
            return [];
        }
        $data = $body->data ?? null;
        $chats = (is_object($data) && isset($data->chats)) ? $data->chats : $data;
        if (is_array($chats) && !empty($chats)) {
            $ids = $chats[0]->dataset_ids ?? [];
            return is_array($ids) ? array_values(array_filter(array_map('strval', $ids))) : [];
        }
        return [];
    }

    /**
     * Semantic retrieval (search) over one or more datasets – ranked chunks, no LLM.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $question
     * @param string[] $datasetids
     * @param int $topk Number of results to return (page_size).
     * @param array|null $metadatacondition
     * @param array $opts Optional retrieval tuning: 'similarity_threshold' (float, RAGflow drops chunks
     *   below it – default RAGflow 0.2), 'top_k' (int, size of the candidate pool the vector search
     *   builds before ranking/reranking – raise it well above page_size for reranking), 'rerank_id'
     *   (string, RAGflow rerank model id – reorders candidates by a cross-encoder for far better
     *   precision), 'vector_similarity_weight' (float 0..1, vector vs keyword blend).
     * @return array List of chunk objects (content, document_keyword, similarity, dataset_id, document_id).
     */
    public static function retrieve(
        string $baseurl,
        string $apikey,
        string $question,
        array $datasetids,
        int $topk = 8,
        ?array $metadatacondition = null,
        array $opts = []
    ): array {
        $question = trim($question);
        if ($question === '' || empty($datasetids)) {
            return [];
        }
        $payload = [
            'question' => $question,
            'dataset_ids' => array_values($datasetids),
            'page' => 1,
            'page_size' => $topk,
            'top_k' => max((int) ($opts['top_k'] ?? $topk), 8),
        ];
        if (isset($opts['similarity_threshold'])) {
            $payload['similarity_threshold'] = (float) $opts['similarity_threshold'];
        }
        if (isset($opts['vector_similarity_weight'])) {
            $payload['vector_similarity_weight'] = (float) $opts['vector_similarity_weight'];
        }
        // A rerank model reorders the candidate pool by a cross-encoder (much better precision); omitted
        // (empty) means plain vector/keyword ranking.
        if (trim((string) ($opts['rerank_id'] ?? '')) !== '') {
            $payload['rerank_id'] = trim((string) $opts['rerank_id']);
        }
        // Optional metadata filter (verified honoured by /api/v1/retrieval), e.g. scope to a course id.
        if (!empty($metadatacondition)) {
            $payload['metadata_condition'] = $metadatacondition;
        }
        $body = self::post($baseurl, $apikey, '/api/v1/retrieval', $payload);
        if ($body === null || ($body->code ?? -1) !== 0) {
            return [];
        }
        $chunks = $body->data->chunks ?? [];
        return is_array($chunks) ? $chunks : [];
    }

    /**
     * The tenant's available rerank models, for the search block's rerank-model selector.
     *
     * @param string $baseurl
     * @param string $apikey
     * @return array [rerank_id => label]. The rerank_id is the model's own **id** (`model_id`) – that is
     *   what /api/v1/retrieval reliably accepts; the `name@provider` form only works when the provider
     *   name equals the internal factory name (fails for custom-named providers). The label is
     *   "name (provider)". Empty when none is configured / the call fails.
     */
    public static function rerank_models(string $baseurl, string $apikey): array {
        $body = self::get($baseurl, $apikey, '/api/v1/models');
        if ($body === null || (int) ($body->code ?? -1) !== 0) {
            return [];
        }
        $out = [];
        foreach ((array) ($body->data ?? []) as $m) {
            $isrerank = false;
            foreach ((array) ($m->model_type ?? []) as $t) {
                if (stripos((string) $t, 'rerank') !== false) {
                    $isrerank = true;
                }
            }
            if (!$isrerank) {
                continue;
            }
            $modelid = trim((string) ($m->model_id ?? ''));
            $name = trim((string) ($m->name ?? ''));
            $provider = trim((string) ($m->provider_name ?? ''));
            if ($modelid === '' || $name === '') {
                continue;
            }
            $label = ($provider !== '') ? ($name . ' (' . $provider . ')') : $name;
            // Collapse duplicate registrations of the same model (same label, different internal id).
            if (in_array($label, $out, true)) {
                continue;
            }
            $out[$modelid] = $label;
        }
        asort($out);
        return $out;
    }

    /**
     * Create a new RAGflow chat session (server-side conversation memory).
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $chatid
     * @param string $name
     * @param string $errordetail Out: the technical failure cause on '' return (admin-only). Empty on success.
     * @return string The session id, or '' on failure.
     */
    public static function create_session(
        string $baseurl,
        string $apikey,
        string $chatid,
        string $name,
        string &$errordetail = ''
    ): string {
        $errordetail = '';
        $chatid = trim($chatid);
        if ($chatid === '') {
            $errordetail = 'no chat id configured';
            return '';
        }
        $body = self::post($baseurl, $apikey, '/api/v1/chats/' . urlencode($chatid) . '/sessions', ['name' => $name], $errordetail);
        if ($body === null) {
            return '';
        }
        if (($body->code ?? -1) !== 0) {
            $apimsg = trim((string) ($body->message ?? ''));
            $errordetail = 'RAGflow code ' . (int) ($body->code ?? -1) . ($apimsg !== '' ? ': ' . $apimsg : '');
            return '';
        }
        return (string) ($body->data->id ?? '');
    }

    /**
     * Send a message inside a RAGflow chat session (stateful; RAGflow keeps the history).
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $chatid
     * @param string $sessionid
     * @param string $question
     * @param string $errordetail Out: the technical failure cause on null return (admin-only – may reveal
     *                            server-side internals). Empty on success.
     * @return \stdClass|null The `data` object (answer, reference, session_id) or null on failure.
     */
    public static function session_complete(
        string $baseurl,
        string $apikey,
        string $chatid,
        string $sessionid,
        string $question,
        string &$errordetail = ''
    ): ?\stdClass {
        $errordetail = '';
        $body = self::post($baseurl, $apikey, '/api/v1/chats/' . urlencode($chatid) . '/completions', [
            'question' => $question,
            'session_id' => $sessionid,
            'stream' => false,
        ], $errordetail);
        if ($body === null) {
            return null;
        }
        if (($body->code ?? -1) !== 0) {
            // RAGflow wraps an app-level failure (e.g. an embedding / context-window error) in a 200 with a
            // non-zero code + message; surface it so the real cause is diagnosable (not just "unexpected").
            $apimsg = trim((string) ($body->message ?? ''));
            $errordetail = 'RAGflow code ' . (int) ($body->code ?? -1) . ($apimsg !== '' ? ': ' . $apimsg : '');
            return null;
        }
        if (!($body->data instanceof \stdClass)) {
            $errordetail = 'no data object in a successful response';
            return null;
        }
        return $body->data;
    }

    /**
     * Stateless chat completion via the OpenAI-compatible endpoint (no RAGflow session). Used for chats
     * without server-side memory (the client folds the running history into $question). Optionally asks
     * for source references.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $chatid
     * @param string $question
     * @param string $systeminstruction
     * @param bool $includesources
     * @param array $extrabody Extra request options (e.g. metadata_condition).
     * @param string $errordetail Out: the technical failure cause on null return (admin-only – may reveal
     *   RAGflow internals; callers must not expose it to non-admins).
     * @return \stdClass|null {content, reference} or null on failure.
     */
    public static function openai_complete(
        string $baseurl,
        string $apikey,
        string $chatid,
        string $question,
        string $systeminstruction = '',
        bool $includesources = false,
        array $extrabody = [],
        string &$errordetail = ''
    ): ?\stdClass {
        $errordetail = '';
        $baseurl = rtrim($baseurl, '/');
        $chatid = trim($chatid);
        if ($baseurl === '' || $apikey === '' || $chatid === '') {
            return null;
        }
        $messages = [];
        if (trim($systeminstruction) !== '') {
            $messages[] = ['role' => 'system', 'content' => $systeminstruction];
        }
        $messages[] = ['role' => 'user', 'content' => $question];
        $request = [
            'model' => self::get_chat_llmid($baseurl, $apikey, $chatid),
            'stream' => false,
            'messages' => $messages,
        ];
        // The extra_body carries any caller-supplied options (e.g. a metadata_condition for KB/course
        // scoping) plus, when requested, the reference/citation flags.
        $extra = $extrabody;
        if ($includesources) {
            $extra['reference'] = true;
            $extra['reference_metadata'] = ['include' => true];
        }
        if (!empty($extra)) {
            $request['extra_body'] = $extra;
        }
        // The chat model can be slow (large local LLMs), so allow a generous timeout and retry once on a
        // transient failure (network/timeout exception or a 5xx) – RAGflow occasionally returns a momentary
        // 5xx. A 4xx (client error) is not retried. On give-up, log the cause so a transient blip can be
        // told apart from a real misconfiguration.
        $endpoint = $baseurl . '/api/v1/chats_openai/' . urlencode($chatid) . '/chat/completions';
        $body = json_encode($request, JSON_UNESCAPED_SLASHES);
        $maxattempts = 2;
        $lasterror = '';
        for ($attempt = 1; $attempt <= $maxattempts; $attempt++) {
            try {
                $response = \core\di::get(http_client::class)->request('POST', $endpoint, [
                    'headers' => ['Authorization' => 'Bearer ' . $apikey, 'Content-Type' => 'application/json'],
                    'body' => $body,
                    'timeout' => 120,
                    RequestOptions::HTTP_ERRORS => false,
                ]);
            } catch (\Throwable $e) {
                $lasterror = 'request exception: ' . $e->getMessage();
                continue; // Network/timeout – retry.
            }
            $status = $response->getStatusCode();
            if ($status >= 500) {
                $lasterror = 'HTTP ' . $status;
                continue; // Server error – retry.
            }
            if ($status !== 200) {
                $lasterror = 'HTTP ' . $status;
                break; // Client error – do not retry.
            }
            $decoded = json_decode($response->getBody()->getContents());
            if (!($decoded instanceof \stdClass) || empty($decoded->choices)) {
                // RAGflow wraps app-level failures (e.g. an embedding "context window exceeded" when the
                // query is too long for the KB's embedding model) in a 200 with {code, message} and no
                // choices – surface that message so the cause is diagnosable.
                $apimsg = (is_object($decoded) && !empty($decoded->message)) ? (string) $decoded->message : '';
                $lasterror = 'no choices' . ($apimsg !== '' ? ': ' . $apimsg : '');
                break;
            }
            $message = $decoded->choices[0]->message ?? null;
            if (!is_object($message)) {
                $lasterror = 'malformed body (no message)';
                break;
            }
            return (object) [
                'content' => (string) ($message->content ?? ''),
                'reference' => $message->reference ?? null,
                // OpenAI-compatible token accounting (may be absent on some RAGflow versions/models).
                'usage' => $decoded->usage ?? null,
            ];
        }
        $errordetail = $lasterror;
        debugging('aiprovider_ragflow: chat completion failed (' . $lasterror . ')', DEBUG_DEVELOPER);
        return null;
    }

    /**
     * Add a conversation turn to a RAGflow memory (native long-term memory; RAGflow extracts facts).
     * Per-user separation is via $sessionid (RAGflow ignores/overrides user_id to the tenant).
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $memoryid RAGflow memory id.
     * @param string $agentid RAGflow agent id (required by the API).
     * @param string $sessionid Scope key, e.g. "moodle-<userid>".
     * @param string $userinput The user's message.
     * @param string $agentresponse The assistant's answer.
     * @return bool True if the API accepted the message.
     */
    public static function memory_add(
        string $baseurl,
        string $apikey,
        string $memoryid,
        string $agentid,
        string $sessionid,
        string $userinput,
        string $agentresponse
    ): bool {
        $baseurl = rtrim($baseurl, '/');
        if ($baseurl === '' || $apikey === '' || trim($memoryid) === '' || trim($agentid) === '' || trim($sessionid) === '') {
            return false;
        }
        $body = self::post($baseurl, $apikey, '/api/v1/messages', [
            'memory_id' => [$memoryid],
            'agent_id' => $agentid,
            'session_id' => $sessionid,
            'user_input' => $userinput,
            'agent_response' => $agentresponse,
        ]);
        return $body !== null && (int) ($body->code ?? -1) === 0;
    }

    /**
     * Search a RAGflow memory for a user (scoped by $sessionid), returning the matching contents.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $memoryid
     * @param string $sessionid Scope key, e.g. "moodle-<userid>".
     * @param string $query The current question (relevance query).
     * @param int $topn
     * @return string[] Matching memory contents, most relevant first.
     */
    public static function memory_search(
        string $baseurl,
        string $apikey,
        string $memoryid,
        string $sessionid,
        string $query,
        int $topn = 8
    ): array {
        $baseurl = rtrim($baseurl, '/');
        if ($baseurl === '' || $apikey === '' || trim($memoryid) === '' || trim($sessionid) === '' || trim($query) === '') {
            return [];
        }
        $qs = http_build_query([
            'query' => $query,
            'memory_id' => $memoryid,
            'session_id' => $sessionid,
            'top_n' => $topn,
            'similarity_threshold' => 0.2,
        ]);
        try {
            $response = \core\di::get(http_client::class)->request('GET', $baseurl . '/api/v1/messages/search?' . $qs, [
                'headers' => ['Authorization' => 'Bearer ' . $apikey],
                'timeout' => 15,
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (\Throwable $e) {
            return [];
        }
        if ($response->getStatusCode() !== 200) {
            return [];
        }
        $body = json_decode($response->getBody()->getContents());
        if (!($body instanceof \stdClass) || !is_array($body->data ?? null)) {
            return [];
        }
        $out = [];
        foreach ($body->data as $m) {
            $content = trim((string) ($m->content ?? ''));
            if ($content !== '') {
                $out[] = $content;
            }
        }
        return $out;
    }

    /**
     * Forget all memory messages of a user (scope $sessionid) – used for privacy / user deletion.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $memoryid
     * @param string $sessionid Scope key, e.g. "moodle-<userid>".
     * @return void
     */
    public static function memory_forget_session(
        string $baseurl,
        string $apikey,
        string $memoryid,
        string $sessionid
    ): void {
        $baseurl = rtrim($baseurl, '/');
        if ($baseurl === '' || $apikey === '' || trim($memoryid) === '' || trim($sessionid) === '') {
            return;
        }
        $client = \core\di::get(http_client::class);
        // Page size is capped at 100 and extraction is async, so keep fetching+deleting until drained.
        for ($pass = 0; $pass < 30; $pass++) {
            $ids = self::memory_session_message_ids($baseurl, $apikey, $memoryid, $sessionid);
            if (empty($ids)) {
                break;
            }
            foreach ($ids as $msgid) {
                try {
                    $client->request(
                        'DELETE',
                        $baseurl . '/api/v1/messages/' . urlencode($memoryid) . ':' . $msgid,
                        [
                            'headers' => ['Authorization' => 'Bearer ' . $apikey],
                            'timeout' => 15,
                            RequestOptions::HTTP_ERRORS => false,
                        ]
                    );
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }
    }

    /**
     * List the message ids of a memory scope (raw + extracted semantic), for forgetting.
     * Uses `GET /api/v1/messages?memory_id=&session_id=` – an EXACT session filter that returns both
     * raw and extracted semantic messages in a flat `data[]` (the fuzzy `keywords` list endpoint is
     * unreliable and its structure is nested).
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $memoryid
     * @param string $sessionid
     * @return array Message ids (mixed int/string).
     */
    protected static function memory_session_message_ids(
        string $baseurl,
        string $apikey,
        string $memoryid,
        string $sessionid
    ): array {
        $qs = http_build_query(['memory_id' => $memoryid, 'session_id' => $sessionid, 'limit' => 100]);
        try {
            $resp = \core\di::get(http_client::class)->request(
                'GET',
                $baseurl . '/api/v1/messages?' . $qs,
                [
                    'headers' => ['Authorization' => 'Bearer ' . $apikey],
                    'timeout' => 15,
                    RequestOptions::HTTP_ERRORS => false,
                ]
            );
        } catch (\Throwable $e) {
            return [];
        }
        if ($resp->getStatusCode() !== 200) {
            return [];
        }
        $body = json_decode($resp->getBody()->getContents());
        if (!is_array($body->data ?? null)) {
            return [];
        }
        $ids = [];
        foreach ($body->data as $item) {
            if (isset($item->message_id)) {
                $ids[] = $item->message_id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Fetch the stored messages of a RAGflow chat session (to restore the transcript on reload).
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $chatid
     * @param string $sessionid
     * @return array List of {role, content} objects (empty on failure or unknown session).
     */
    public static function get_session_messages(string $baseurl, string $apikey, string $chatid, string $sessionid): array {
        $baseurl = rtrim($baseurl, '/');
        $chatid = trim($chatid);
        $sessionid = trim($sessionid);
        if ($baseurl === '' || $apikey === '' || $chatid === '' || $sessionid === '') {
            return [];
        }
        try {
            $response = \core\di::get(http_client::class)->request(
                'GET',
                $baseurl . '/api/v1/chats/' . urlencode($chatid) . '/sessions',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $apikey],
                    'query' => ['id' => $sessionid],
                    'timeout' => 15,
                    RequestOptions::HTTP_ERRORS => false,
                ]
            );
        } catch (\Throwable $e) {
            return [];
        }
        if ($response->getStatusCode() !== 200) {
            return [];
        }
        $body = json_decode($response->getBody()->getContents());
        if (!($body instanceof \stdClass) || ($body->code ?? -1) !== 0 || empty($body->data)) {
            return [];
        }
        $session = is_array($body->data) ? ($body->data[0] ?? null) : $body->data;
        if (!($session instanceof \stdClass) || empty($session->messages) || !is_array($session->messages)) {
            return [];
        }
        return array_values($session->messages);
    }

    /**
     * Delete RAGflow chat sessions.
     *
     * @param string $baseurl
     * @param string $apikey
     * @param string $chatid
     * @param string[] $ids
     * @return void
     */
    public static function delete_sessions(string $baseurl, string $apikey, string $chatid, array $ids): void {
        $ids = array_values(array_filter(array_map('strval', $ids)));
        if (empty($ids) || trim($chatid) === '') {
            return;
        }
        $baseurl = rtrim($baseurl, '/');
        try {
            \core\di::get(http_client::class)->request('DELETE', $baseurl . '/api/v1/chats/' . urlencode($chatid) . '/sessions', [
                'headers' => ['Authorization' => 'Bearer ' . $apikey, 'Content-Type' => 'application/json'],
                'body' => json_encode(['ids' => $ids]),
                'timeout' => 15,
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (\Throwable $e) {
            // Best effort – the pruning task / next attempt retries.
            return;
        }
    }
}
