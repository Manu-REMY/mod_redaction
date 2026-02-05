<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Anthropic (Claude) provider for AI evaluation.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\ai_provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Anthropic provider implementation.
 */
class anthropic_provider extends base_provider {

    /** @var string API endpoint */
    const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** @var string API version */
    const API_VERSION = '2023-06-01';

    /** @var array Available models */
    const MODELS = [
        'claude-3-5-sonnet-20241022',
        'claude-3-5-haiku-20241022',
        'claude-3-opus-20240229',
        'claude-3-sonnet-20240229',
        'claude-3-haiku-20240307',
    ];

    /** @var string Default model */
    const DEFAULT_MODEL = 'claude-3-5-sonnet-20241022';

    /**
     * Get the provider name.
     *
     * @return string
     */
    public function get_name(): string {
        return 'anthropic';
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
            'x-api-key: ' . $this->apikey,
            'anthropic-version: ' . self::API_VERSION,
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
            'system' => $systemprompt,
            'messages' => [
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
        // Anthropic returns content as an array of blocks.
        $content = '';
        if (isset($response['content']) && is_array($response['content'])) {
            foreach ($response['content'] as $block) {
                if (isset($block['text'])) {
                    $content .= $block['text'];
                }
            }
        }

        $usage = $response['usage'] ?? [];

        return [
            'content' => $content,
            'prompt_tokens' => $usage['input_tokens'] ?? 0,
            'completion_tokens' => $usage['output_tokens'] ?? 0,
        ];
    }
}
