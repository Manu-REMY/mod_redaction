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
 * Base AI provider class.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\ai_provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Abstract base class for AI providers.
 */
abstract class base_provider implements provider_interface {

    /** @var string API key */
    protected string $apikey;

    /** @var int Request timeout in seconds */
    protected int $timeout = 60;

    /** @var int Connection timeout in seconds */
    protected int $connecttimeout = 15;

    /** @var int Maximum retries */
    protected int $maxretries = 3;

    /** @var array Retry delays in seconds */
    protected array $retrydelays = [5, 30, 120];

    /**
     * Constructor.
     *
     * @param string $apikey API key
     */
    public function __construct(string $apikey) {
        $this->apikey = $apikey;
    }

    /**
     * Get the API endpoint URL.
     *
     * @return string
     */
    abstract protected function get_endpoint(): string;

    /**
     * Build request headers.
     *
     * @return array
     */
    abstract protected function build_headers(): array;

    /**
     * Build request body.
     *
     * @param string $systemprompt
     * @param string $userprompt
     * @param string $model
     * @param int $maxtokens
     * @return array
     */
    abstract protected function build_body(string $systemprompt, string $userprompt, string $model, int $maxtokens): array;

    /**
     * Parse the response from the API.
     *
     * @param array $response
     * @return array ['content' => string, 'prompt_tokens' => int, 'completion_tokens' => int]
     */
    abstract protected function parse_response(array $response): array;

    /**
     * Evaluate content using the AI.
     *
     * @param string $systemprompt
     * @param string $userprompt
     * @param string $model
     * @param int $maxtokens
     * @return array
     */
    public function evaluate(string $systemprompt, string $userprompt, string $model, int $maxtokens): array {
        return $this->call_with_retry(function() use ($systemprompt, $userprompt, $model, $maxtokens) {
            return $this->make_request($systemprompt, $userprompt, $model, $maxtokens);
        });
    }

    /**
     * Make the API request.
     *
     * @param string $systemprompt
     * @param string $userprompt
     * @param string $model
     * @param int $maxtokens
     * @return array
     */
    protected function make_request(string $systemprompt, string $userprompt, string $model, int $maxtokens): array {
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_CONNECTTIMEOUT' => $this->connecttimeout,
        ]);

        $headers = $this->build_headers();
        $body = $this->build_body($systemprompt, $userprompt, $model, $maxtokens);

        // JSON_INVALID_UTF8_SUBSTITUTE replaces any stray invalid byte
        // sequence with U+FFFD instead of making json_encode return false.
        // Without this, an upstream truncation that lands inside a multi-byte
        // UTF-8 character produces no payload and the provider rejects the
        // call as HTTP 400 "invalid JSON body" — see prior incident in
        // ai_summary_generator::build_synthesis_prompt.
        $payload = json_encode(
            $body,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE
        );
        if ($payload === false) {
            throw new \moodle_exception(
                'ai_request_failed', 'redaction', '', null,
                'json_encode of request body failed: ' . json_last_error_msg()
            );
        }

        $response = $curl->post($this->get_endpoint(), $payload, [
            'CURLOPT_HTTPHEADER' => $headers,
        ]);

        $httpcode = $curl->get_info()['http_code'] ?? 0;

        if ($httpcode < 200 || $httpcode >= 300) {
            $error = json_decode($response, true);
            $message = $error['error']['message'] ?? $response ?? 'Unknown error';
            throw new \moodle_exception('ai_request_failed', 'redaction', '', null, "HTTP $httpcode: $message");
        }

        $data = json_decode($response, true);
        if ($data === null) {
            throw new \moodle_exception('ai_invalid_response', 'redaction', '', null, 'Invalid JSON response');
        }

        return $this->parse_response($data);
    }

    /**
     * Call with retry logic.
     *
     * @param callable $callback
     * @return mixed
     */
    protected function call_with_retry(callable $callback) {
        $lastexception = null;

        for ($attempt = 0; $attempt <= $this->maxretries; $attempt++) {
            try {
                return $callback();
            } catch (\Exception $e) {
                $lastexception = $e;

                // Don't retry auth errors.
                if ($this->is_auth_error($e)) {
                    throw $e;
                }

                // Wait before retry.
                if ($attempt < $this->maxretries) {
                    $delay = $this->retrydelays[$attempt] ?? 120;
                    sleep($delay);
                }
            }
        }

        throw $lastexception;
    }

    /**
     * Check if the exception is an authentication error.
     *
     * @param \Exception $e
     * @return bool
     */
    protected function is_auth_error(\Exception $e): bool {
        $message = $e->getMessage();
        return stripos($message, '401') !== false ||
               stripos($message, '403') !== false ||
               stripos($message, 'unauthorized') !== false ||
               stripos($message, 'invalid_api_key') !== false;
    }

    /**
     * Test the connection.
     *
     * @return array
     */
    public function test_connection(): array {
        try {
            $result = $this->evaluate(
                'You are a test assistant.',
                'Reply with the word "OK".',
                $this->get_default_model(),
                10
            );

            return [
                'success' => true,
                'message' => 'Connection successful'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Estimate token count.
     *
     * @param string $text
     * @return int
     */
    public function estimate_tokens(string $text): int {
        // Rough estimate: 1 token ≈ 4 characters for most languages.
        // Use mb_strlen if available, fall back to strlen otherwise.
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        return (int) ceil($length / 4);
    }
}
