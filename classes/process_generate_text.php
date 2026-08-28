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

use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Process text generation via RAGflow's OpenAI-compatible chat/completions endpoint.
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_generate_text extends abstract_processor {
    #[\Override]
    protected function get_system_instruction(): string {
        return $this->provider->actionconfig[$this->action::class]['settings']['systeminstruction'] ?? '';
    }

    #[\Override]
    protected function create_request_object(string $userid): RequestInterface {
        // User message.
        $userobj = new \stdClass();
        $userobj->role = 'user';
        $userobj->content = $this->action->get_configuration('prompttext');

        $requestobj = new \stdClass();
        $requestobj->model = $this->get_model();
        $requestobj->user = $userid;
        $requestobj->stream = false;

        $systeminstruction = $this->get_system_instruction();
        if (!empty($systeminstruction)) {
            $systemobj = new \stdClass();
            $systemobj->role = 'system';
            $systemobj->content = $systeminstruction;
            $requestobj->messages = [$systemobj, $userobj];
        } else {
            $requestobj->messages = [$userobj];
        }

        // Append any extra model settings (incl. a manually configured extra_body).
        foreach ($this->get_model_settings() as $setting => $value) {
            $requestobj->$setting = $value;
        }

        // Build the knowledge-base metadata filter, per data source.
        $conditions = [];
        $ds = (string) $this->get_setting('datasource', 'thismoodle');
        // Legacy 'ragflow' source == documents from this Moodle; its filtering is identical to 'thismoodle'.
        if ($ds === 'ragflow') {
            $ds = 'thismoodle';
        }

        if ($ds === 'wholekb') {
            // Whole knowledge base: apply NO metadata filter, the assistant's entire KB is searched. Needed
            // for a KB not populated from Moodle (its documents carry no course_id / external_sharing), where
            // any filter would exclude every document (RAGflow drops documents lacking a filtered field).
            $conditions = [];
        } else if ($ds === 'external') {
            // Documents from a DIFFERENT Moodle: only the explicitly shared ones. RAGflow compares
            // metadata as strings (like course_id, numeric but matched with "4"), so 1 is matched as "1".
            // Each source links via its own document moodle_url, so no global source-site filter here.
            $conditions[] = (object) [
                'name' => 'external_sharing',
                'comparison_operator' => 'in',
                'value' => ['1'],
            ];
        } else {
            // Source 'thismoodle': documents originate from THIS Moodle. Course scoping is valid here –
            // ids + site (moodle_url) are unique within this Moodle, so the current course context
            // matches only local docs.
            $scopeids = $this->get_scope_course_ids();
            if (!empty($scopeids)) {
                global $CFG;
                $field = trim((string) $this->get_setting('coursemetadatafield', 'course_id')) ?: 'course_id';
                $conditions[] = (object) [
                    'name' => $field,
                    'comparison_operator' => 'in',
                    'value' => array_values($scopeids),
                ];
                $conditions[] = (object) [
                    'name' => 'moodle_url',
                    'comparison_operator' => 'in',
                    'value' => [rtrim($CFG->wwwroot, '/')],
                ];
            }
        }

        if (!empty($conditions)) {
            $requestobj->extra_body = $this->ensure_extra_body($requestobj);
            $requestobj->extra_body->metadata_condition = (object) [
                'logic' => 'and',
                'conditions' => $conditions,
            ];
        }

        // Optional: ask RAGflow to return the source chunks (appended to the answer in handle_api_success).
        if (!empty($this->get_setting('includesources'))) {
            $requestobj->extra_body = $this->ensure_extra_body($requestobj);
            $requestobj->extra_body->reference = true;
            $requestobj->extra_body->reference_metadata = (object) ['include' => true];
        }

        return new Request(
            method: 'POST',
            uri: '',
            headers: [
                'Content-Type' => 'application/json',
            ],
            body: json_encode($requestobj, JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Handle a successful HTTP response from RAGflow.
     *
     * RAGflow returns HTTP 200 even for application-level errors, e.g.
     * {"code":101,"message":"`llm_id` ... doesn't exist"} – so validate the shape here.
     *
     * @param ResponseInterface $response The response object.
     * @return array The response.
     */
    protected function handle_api_success(ResponseInterface $response): array {
        $bodyobj = json_decode($response->getBody()->getContents());

        if (empty($bodyobj->choices) || (isset($bodyobj->code) && $bodyobj->code !== 0)) {
            $message = $bodyobj->message ?? get_string('error:unexpectedresponse', 'aiprovider_ragflow');
            return $this->error_details(400, $message);
        }

        $message = $bodyobj->choices[0]->message;
        $content = $message->content;

        // Append the source documents when RAGflow returned references (the includesources setting).
        if (!empty($message->reference) && is_array($message->reference)) {
            $refs = array_values($message->reference);
            // For a downloadable source file we build a durable, token-less proxy link (download.php
            // authorises each click by the user's access to this context + the document belonging to this
            // action's assistant KB). On-click JS is not available in saved generated content, and a signed
            // token would expire, so authorisation happens live at the endpoint instead.
            $contextid = (int) $this->action->get_configuration('contextid');
            $classname = get_class($this->action);
            $pos = strrpos($classname, '\\');
            $actionname = ($pos !== false) ? substr($classname, $pos + 1) : $classname;

            // RAGflow cites sources inline as [ID:N], where N is a 0-based index into the reference
            // list. Renumber the inline markers to start at 1 so they read naturally for the user.
            $content = preg_replace_callback('/\[ID:(\d+)\]/', function ($m) {
                return '[ID:' . ((int) $m[1] + 1) . ']';
            }, (string) $content);

            // List the same (now 1-based) numbers so the reader can match them; if there are no inline
            // markers, list every reference.
            if (preg_match_all('/\[ID:(\d+)\]/', $content, $matches)) {
                $displaynums = array_values(array_unique(array_map('intval', $matches[1])));
                sort($displaynums);
            } else {
                $displaynums = array_map(function ($i) {
                    return $i + 1;
                }, array_keys($refs));
            }

            $lines = [];
            foreach ($displaynums as $num) {
                $n = $num - 1; // Back to the 0-based reference index.
                if (!isset($refs[$n]) || !is_object($refs[$n])) {
                    continue;
                }
                $ref = $refs[$n];
                $name = (string) ($ref->document_name ?? '');
                $url = '';
                // Prefer the in-context Moodle activity link when the document carries it (opens the actual
                // activity in the user's session); otherwise a durable, per-click-authorised proxy download
                // of the raw file (works for a knowledge base not backed by Moodle activities).
                if (isset($ref->document_metadata) && is_object($ref->document_metadata)) {
                    $md = $ref->document_metadata;
                    $moodleurl = rtrim((string) ($md->moodle_url ?? ''), '/');
                    $modtype = (string) ($md->module_type ?? '');
                    $modid = (string) ($md->module_id ?? '');
                    if ($moodleurl !== '' && $modtype !== '' && $modid !== '') {
                        $url = "{$moodleurl}/mod/{$modtype}/view.php?id={$modid}";
                    }
                }
                if ($url === '') {
                    $dsid = (string) ($ref->dataset_id ?? '');
                    $docid = (string) ($ref->document_id ?? '');
                    if ($dsid !== '' && $docid !== '' && $contextid > 0) {
                        $url = helper::context_download_url($contextid, $actionname, $dsid, $docid);
                    }
                }
                // The URL comes from untrusted RAGflow document metadata; strip anything that is not a safe
                // http(s)/relative URL (clean_param(PARAM_URL) returns '' for e.g. a javascript: scheme).
                $url = clean_param($url, PARAM_URL);
                if ($name === '' && $url === '') {
                    continue;
                }
                $label = ($name !== '') ? $name : $url;
                // Link opens in a new tab (external file). html_writer emits an escaped anchor.
                $entry = ($url !== '')
                    ? \html_writer::link($url, $label, ['target' => '_blank', 'rel' => 'noopener noreferrer'])
                    : $label;
                $lines[] = "[ID:{$num}] {$entry}";
            }

            if ($lines) {
                $content .= "\n\n" . get_string('sourcesheading', 'aiprovider_ragflow') . "\n"
                    . implode("\n", $lines) . "\n";
            }
        }

        return [
            'success' => true,
            'id' => $bodyobj->id ?? '',
            'fingerprint' => $bodyobj->system_fingerprint ?? null,
            'generatedcontent' => $content,
            'finishreason' => $bodyobj->choices[0]->finish_reason ?? 'stop',
            'prompttokens' => $bodyobj->usage->prompt_tokens ?? 0,
            'completiontokens' => $bodyobj->usage->completion_tokens ?? 0,
            'model' => $bodyobj->model ?? $this->get_model(),
        ];
    }
}
