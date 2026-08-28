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
 * Proxy that streams a RAGflow source document server-side.
 *
 * The RAGflow API key never reaches the browser. Two authorisation modes, both after require_login():
 *  - **Token mode** (on-click links from the chat/search/file-manager web services): a signed,
 *    time-limited token bound to a specific provider, document and user.
 *  - **Context mode** (durable links embedded in *saved* generated-text content, where on-click JS is
 *    not available and a signed token would expire): no token; each request is authorised live by the
 *    user's access to the given context PLUS the document belonging to the text action's assistant KB.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

require_login();

$dataset  = required_param('dataset', PARAM_ALPHANUMEXT);
$document = required_param('document', PARAM_ALPHANUMEXT);
$sig      = optional_param('sig', '', PARAM_ALPHANUMEXT);

if ($sig !== '') {
    // Token mode: signed, time-limited, bound to the requesting user.
    $providerid = required_param('providerid', PARAM_INT);
    $userid     = required_param('userid', PARAM_INT);
    $expiry     = required_param('expiry', PARAM_INT);
    if ($userid !== (int) $USER->id) {
        throw new \moodle_exception('error:tokeninvalid', 'aiprovider_ragflow');
    }
    if ($expiry < time()) {
        throw new \moodle_exception('error:tokenexpired', 'aiprovider_ragflow');
    }
    $expected = \aiprovider_ragflow\helper::sign_download($providerid, $dataset, $document, $userid, $expiry);
    if (!hash_equals($expected, (string) $sig)) {
        throw new \moodle_exception('error:tokeninvalid', 'aiprovider_ragflow');
    }
    // Load the provider config server-side – the API key stays on the server.
    // (The ai_providers.provider column holds the class name, e.g. aiprovider_ragflow\provider.)
    $record = $DB->get_record('ai_providers', ['id' => $providerid], '*', MUST_EXIST);
    if (($record->provider ?? '') !== \aiprovider_ragflow\provider::class) {
        throw new \moodle_exception('error:tokeninvalid', 'aiprovider_ragflow');
    }
    $config  = json_decode($record->config, true) ?: [];
    $baseurl = rtrim((string) ($config['baseurl'] ?? ''), '/');
    $apikey  = (string) ($config['apikey'] ?? '');
} else {
    // Context mode: durable link, authorised per click.
    $contextid = required_param('contextid', PARAM_INT);
    $action    = required_param('action', PARAM_ALPHANUMEXT);
    $context   = \core\context::instance_by_id($contextid, MUST_EXIST);
    // Access to the context the generated content lives in: for a course/activity context this enforces
    // enrolment/visibility (throws, not redirects); for a site-level context require_login() above is it.
    $coursecontext = $context->get_course_context(false);
    if ($coursecontext && (int) $coursecontext->instanceid !== SITEID) {
        require_login($coursecontext->instanceid, false, null, false, true);
    }
    // The document must belong to the text action's assistant knowledge base, so a crafted URL cannot pull
    // an unrelated dataset. Credentials are resolved server-side from the enabled provider.
    $authz = \aiprovider_ragflow\helper::action_download_context($action);
    if ($authz === null || !in_array($dataset, $authz->datasets, true)) {
        throw new \moodle_exception('error:downloaddenied', 'aiprovider_ragflow');
    }
    $baseurl = $authz->base;
    $apikey  = $authz->key;
}
if ($baseurl === '' || $apikey === '') {
    throw new \moodle_exception('error:notconfigured', 'aiprovider_ragflow');
}

// Fetch from RAGflow and stream to the browser.
$client = \core\di::get(\core\http_client::class);
try {
    $response = $client->request('GET', "{$baseurl}/api/v1/datasets/{$dataset}/documents/{$document}", [
        'headers' => ['Authorization' => 'Bearer ' . $apikey],
        'timeout' => 60,
        \GuzzleHttp\RequestOptions::HTTP_ERRORS => false,
        'stream' => true,
    ]);
} catch (\Throwable $e) {
    throw new \moodle_exception('error:unexpectedresponse', 'aiprovider_ragflow');
}
if ($response->getStatusCode() !== 200) {
    throw new \moodle_exception('error:unexpectedresponse', 'aiprovider_ragflow');
}

$filename = '';
if (preg_match('/filename="?([^"]+)"?/', $response->getHeaderLine('Content-Disposition'), $m)) {
    $filename = clean_filename(basename(trim($m[1])));
}

// The document content is user-supplied (uploaded to RAGflow), so it must never be rendered inline in the
// Moodle origin (an HTML/SVG file would run as script → stored XSS). Mirror send_stored_file(): only a small
// allowlist of safe types is served inline; everything else is forced to download as application/octet-stream.
$mime = \core_text::strtolower(trim(explode(';', $response->getHeaderLine('Content-Type') ?: '')[0]));
$safeinline = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain'];
if (in_array($mime, $safeinline, true)) {
    $contenttype = $mime;
    $disposition = 'inline';
} else {
    $contenttype = 'application/octet-stream';
    $disposition = 'attachment';
}

// Release the session lock so a large download doesn't block the user's other requests.
\core\session\manager::write_close();

header('Content-Type: ' . $contenttype);
header('Content-Disposition: ' . $disposition . ($filename !== '' ? '; filename="' . $filename . '"' : ''));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=60');
if ($response->hasHeader('Content-Length')) {
    header('Content-Length: ' . $response->getHeaderLine('Content-Length'));
}

$body = $response->getBody();
while (!$body->eof()) {
    echo $body->read(65536);
    flush();
}
$body->close();
