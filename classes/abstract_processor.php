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
use core_ai\process_base;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Abstract processor for the RAGflow provider (OpenAI-compatible chat/completions).
 *
 * @package    aiprovider_ragflow
 * @copyright  2026 RAGcon GmbH <info@ragcon.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class abstract_processor extends process_base {
    /**
     * Get the endpoint URI, built from the provider base URL + the action's chat id:
     * {baseurl}/api/v1/chats_openai/{chatid}/chat/completions
     *
     * @return UriInterface
     */
    protected function get_endpoint(): UriInterface {
        return new Uri($this->provider->get_baseurl() . '/api/v1/chats_openai/' . $this->get_chatid() . '/chat/completions');
    }

    /**
     * Get the RAGflow chat assistant id for this action.
     *
     * @return string
     */
    protected function get_chatid(): string {
        return trim((string) $this->get_setting('chatid', ''));
    }

    /**
     * Read a raw action setting.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function get_setting(string $key, $default = null) {
        return $this->provider->actionconfig[$this->action::class]['settings'][$key] ?? $default;
    }

    /**
     * Determine the current course id from the action's context (0 if not inside a course).
     *
     * @return int
     */
    protected function get_courseid(): int {
        $contextid = (int) $this->action->get_configuration('contextid');
        if ($contextid <= 0) {
            return 0;
        }
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if (!$context) {
            return 0;
        }
        $coursecontext = $context->get_course_context(false);
        return $coursecontext ? (int) $coursecontext->instanceid : 0;
    }

    /**
     * Course ids (as strings) to scope the RAGflow knowledge base to, per the 'coursescope' setting:
     * 'current' = the action's course; 'usercourses' = the requesting user's enrolled courses;
     * anything else = no scoping. Returns [] when nothing applies (then no filter is sent).
     *
     * @return string[]
     */
    protected function get_scope_course_ids(): array {
        $scope = (string) $this->get_setting('coursescope', '');
        if ($scope === 'current') {
            $cid = $this->get_courseid();
            return $cid > 0 ? [(string) $cid] : [];
        }
        if ($scope === 'usercourses') {
            $userid = (int) $this->action->get_configuration('userid');
            if ($userid <= 0) {
                return [];
            }
            $courses = enrol_get_users_courses($userid, true, 'id');
            return array_map('strval', array_keys($courses));
        }
        return [];
    }

    /**
     * Get the model to use (the RAGflow llm_id).
     *
     * Trimmed on purpose: RAGflow validates the model as a registered llm_id, and a stray
     * trailing space makes it fail with "101 llm_id doesn't exist".
     *
     * @return string
     */
    protected function get_model(): string {
        // Stored at config time as the selected chat assistant's llm_id (RAGflow ignores it for
        // model selection but requires + validates it). Trimmed on purpose.
        return trim((string) $this->get_setting('model', ''));
    }

    /**
     * Get the extra model settings (passed straight through to the request body).
     *
     * @return array
     */
    protected function get_model_settings(): array {
        $settings = $this->provider->actionconfig[$this->action::class]['settings'];
        if (!empty($settings['modelextraparams'])) {
            $params = json_decode($settings['modelextraparams'], true);
            foreach ($params as $key => $param) {
                $settings[$key] = $param;
            }
        }
        unset(
            $settings['model'],
            $settings['chatid'],
            $settings['helpdeskchatid'],
            $settings['helpdeskmodel'],
            $settings['helpdeskmemory'],
            $settings['helpdesklongtermmemory'],
            $settings['helpdeskmemoryid'],
            $settings['helpdeskmemoryagentid'],
            $settings['systeminstruction'],
            $settings['providerid'],
            $settings['modelextraparams'],
            $settings['datasource'],
            $settings['coursescope'],
            $settings['coursemetadatafield'],
            $settings['includesources'],
            $settings['serveviaproxy'],
        );
        return $settings;
    }

    /**
     * Get the system instruction.
     *
     * @return string
     */
    protected function get_system_instruction(): string {
        return $this->action::get_system_instruction();
    }

    /**
     * Return the request's extra_body as an object, preserving any manual extra_body already set.
     *
     * @param \stdClass $requestobj
     * @return \stdClass
     */
    protected function ensure_extra_body(\stdClass $requestobj): \stdClass {
        if (isset($requestobj->extra_body)) {
            if (is_array($requestobj->extra_body)) {
                return (object) $requestobj->extra_body;
            }
            if (is_object($requestobj->extra_body)) {
                return $requestobj->extra_body;
            }
        }
        return new \stdClass();
    }

    /**
     * Create the request object to send to the RAGflow API.
     *
     * @param string $userid The user id.
     * @return RequestInterface
     */
    abstract protected function create_request_object(
        string $userid,
    ): RequestInterface;

    /**
     * Handle a successful response from the external AI api.
     *
     * @param ResponseInterface $response The response object.
     * @return array The response.
     */
    abstract protected function handle_api_success(ResponseInterface $response): array;

    #[\Override]
    protected function query_ai_api(): array {
        // Guard: an action with no chat assistant selected would build .../chats_openai//chat/... (404).
        if ($this->get_chatid() === '') {
            return $this->error_details(400, get_string('error:nochatid', 'aiprovider_ragflow'));
        }
        $request = $this->create_request_object(
            userid: $this->provider->generate_userid($this->action->get_configuration('userid')),
        );
        $request = $this->provider->add_authentication_headers($request);

        $client = \core\di::get(http_client::class);
        try {
            $response = $client->send($request, [
                'base_uri' => $this->get_endpoint(),
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (RequestException $e) {
            return $this->error_details($e->getCode(), $e->getMessage());
        }

        $status = $response->getStatusCode();
        if ($status === 200) {
            return $this->handle_api_success($response);
        } else {
            return $this->handle_api_error($response);
        }
    }

    /**
     * Handle an error from the external AI api.
     *
     * @param ResponseInterface $response The response object.
     * @return array The error response.
     */
    protected function handle_api_error(ResponseInterface $response): array {
        $status = $response->getStatusCode();
        if ($status >= 500 && $status < 600) {
            $errormessage = $response->getReasonPhrase();
        } else {
            $bodyobj = json_decode($response->getBody()->getContents());
            // RAGflow returns {"code":101,"message":"..."} for app-level errors (HTTP 200 with
            // an error body is handled in handle_api_success); OpenAI-style errors use error.message.
            $errormessage = $bodyobj->error->message ?? ($bodyobj->message ?? $response->getReasonPhrase());
        }

        return $this->error_details($status, $errormessage);
    }

    /**
     * Build the AI-action error-details array in a Moodle-version-compatible way.
     *
     * Moodle 5.1+ provides \core_ai\error\factory (with error-source classification); Moodle 5.0 has no
     * error factory and expects a plain array (success/errorcode/errormessage), so fall back to that shape.
     *
     * @param int $errorcode
     * @param string $errormessage
     * @param string $errorsource
     * @return array The error details.
     */
    protected function error_details(int $errorcode, string $errormessage, string $errorsource = 'upstream'): array {
        if (class_exists('\\core_ai\\error\\factory')) {
            return \core_ai\error\factory::create($errorcode, $errormessage, $errorsource)->get_error_details();
        }
        // Moodle 5.0: the pre-5.1 error-details shape expected by the AI subsystem.
        return [
            'success' => false,
            'errorcode' => $errorcode,
            'errormessage' => $errormessage,
        ];
    }
}
