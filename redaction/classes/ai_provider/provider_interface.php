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
 * AI provider interface.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\ai_provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Interface for AI providers.
 */
interface provider_interface {

    /**
     * Get the provider name.
     *
     * @return string
     */
    public function get_name(): string;

    /**
     * Get available models.
     *
     * @return array
     */
    public function get_models(): array;

    /**
     * Get the default model.
     *
     * @return string
     */
    public function get_default_model(): string;

    /**
     * Evaluate content using the AI.
     *
     * @param string $systemprompt System prompt
     * @param string $userprompt User prompt
     * @param string $model Model to use
     * @param int $maxtokens Maximum tokens for response
     * @return array ['content' => string, 'prompt_tokens' => int, 'completion_tokens' => int]
     */
    public function evaluate(string $systemprompt, string $userprompt, string $model, int $maxtokens): array;

    /**
     * Estimate token count for text.
     *
     * @param string $text
     * @return int
     */
    public function estimate_tokens(string $text): int;

    /**
     * Test the connection to the AI service.
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function test_connection(): array;
}
