<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Albert (Etalab French Government AI) provider for AI evaluation.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\ai_provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Albert provider implementation.
 * Uses built-in API key (no user configuration needed).
 */
class albert_provider extends base_provider {

    /** @var string API endpoint */
    const ENDPOINT = 'https://albert.api.etalab.gouv.fr/v1/chat/completions';

    /** @var array Available models */
    const MODELS = ['albert-large', 'albert-base', 'guillaumetell-7b'];

    /** @var string Default model */
    const DEFAULT_MODEL = 'albert-large';

    /**
     * Get the API key for Albert from admin settings.
     *
     * @return string The configured API key, or empty string if not configured.
     */
    public static function get_admin_key(): string {
        $key = get_config('mod_redaction', 'albert_api_key');
        return !empty($key) ? $key : '';
    }

    /**
     * Constructor - uses admin-configured key if none provided.
     *
     * @param string $apikey API key (optional, will use admin key if empty)
     */
    public function __construct(string $apikey = '') {
        if (empty($apikey)) {
            $apikey = self::get_admin_key();
        }
        if (empty($apikey)) {
            throw new \moodle_exception('ai_albert_no_key', 'redaction');
        }
        parent::__construct($apikey);
    }

    /**
     * Get the provider name.
     *
     * @return string
     */
    public function get_name(): string {
        return 'albert';
    }

    /**
     * Get available models.
     *
     * @return array
     */
    public function get_models(): array {
        return self::MODELS;
    }

    /**
     * Get the default model.
     *
     * @return string
     */
    public function get_default_model(): string {
        return self::DEFAULT_MODEL;
    }

    /**
     * Get the API endpoint.
     *
     * @return string
     */
    protected function get_endpoint(): string {
        return self::ENDPOINT;
    }

    /**
     * Build request headers.
     *
     * @return array
     */
    protected function build_headers(): array {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apikey,
        ];
    }

    /**
     * Build request body.
     *
     * @param string $systemprompt
     * @param string $userprompt
     * @param string $model
     * @param int $maxtokens
     * @return array
     */
    protected function build_body(string $systemprompt, string $userprompt, string $model, int $maxtokens): array {
        return [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemprompt],
                ['role' => 'user', 'content' => $userprompt],
            ],
            'max_tokens' => $maxtokens,
            'temperature' => 0.3,
        ];
    }

    /**
     * Parse the API response.
     *
     * @param array $response
     * @return array
     */
    protected function parse_response(array $response): array {
        $content = $response['choices'][0]['message']['content'] ?? '';
        $usage = $response['usage'] ?? [];

        return [
            'content' => $content,
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
        ];
    }
}
