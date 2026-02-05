<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Mistral AI provider for AI evaluation.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\ai_provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Mistral provider implementation.
 */
class mistral_provider extends base_provider {

    /** @var string API endpoint */
    const ENDPOINT = 'https://api.mistral.ai/v1/chat/completions';

    /** @var array Available models */
    const MODELS = [
        'mistral-medium-latest',
        'mistral-small-latest',
        'mistral-large-latest',
        'open-mistral-7b',
        'open-mixtral-8x7b',
    ];

    /** @var string Default model */
    const DEFAULT_MODEL = 'mistral-medium-latest';

    /**
     * Get the provider name.
     *
     * @return string
     */
    public function get_name(): string {
        return 'mistral';
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
