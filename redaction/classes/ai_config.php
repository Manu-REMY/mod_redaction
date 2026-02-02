<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AI configuration management.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

/**
 * Class for managing AI configuration.
 */
class ai_config {

    /** @var array Providers with built-in API keys */
    const BUILTIN_KEY_PROVIDERS = ['albert'];

    /** @var array Available providers */
    const PROVIDERS = ['openai', 'anthropic', 'mistral', 'albert'];

    /**
     * Encrypt an API key for storage.
     *
     * @param string $apikey
     * @return string
     */
    public static function encrypt_api_key(string $apikey): string {
        if (empty($apikey)) {
            return '';
        }

        // Use Moodle's encryption if available (4.0+).
        if (class_exists('\core\encryption')) {
            return \core\encryption::encrypt($apikey);
        }

        // Fallback to base64 (not truly encrypted, but obfuscated).
        return base64_encode($apikey);
    }

    /**
     * Decrypt a stored API key.
     *
     * @param string $encrypted
     * @return string
     */
    public static function decrypt_api_key(string $encrypted): string {
        if (empty($encrypted)) {
            return '';
        }

        // Try Moodle's encryption first.
        if (class_exists('\core\encryption')) {
            try {
                return \core\encryption::decrypt($encrypted);
            } catch (\Exception $e) {
                // Fall through to base64 decode.
            }
        }

        // Fallback to base64 decode.
        $decoded = base64_decode($encrypted, true);
        return $decoded !== false ? $decoded : $encrypted;
    }

    /**
     * Get AI configuration for a redaction instance.
     *
     * @param int $redactionid
     * @return object|null
     */
    public static function get_config(int $redactionid): ?object {
        global $DB;

        $redaction = $DB->get_record('redaction', ['id' => $redactionid], 'ai_enabled, ai_provider, ai_api_key, ai_auto_apply');

        if (!$redaction) {
            return null;
        }

        return (object) [
            'enabled' => (bool) $redaction->ai_enabled,
            'provider' => $redaction->ai_provider,
            'api_key' => self::decrypt_api_key($redaction->ai_api_key ?? ''),
            'auto_apply' => (bool) $redaction->ai_auto_apply,
        ];
    }

    /**
     * Check if a provider has a built-in API key.
     *
     * @param string $provider
     * @return bool
     */
    public static function has_builtin_key(string $provider): bool {
        return in_array($provider, self::BUILTIN_KEY_PROVIDERS);
    }

    /**
     * Get the built-in API key for a provider.
     * For Albert, returns the hardcoded key. For others, checks admin config.
     *
     * @param string $provider
     * @return string
     */
    public static function get_builtin_api_key(string $provider): string {
        // Albert has a hardcoded built-in key.
        if ($provider === 'albert') {
            return \mod_redaction\ai_provider\albert_provider::get_builtin_key();
        }

        // Other providers check admin config.
        $key = get_config('mod_redaction', $provider . '_api_key');
        return $key ?? '';
    }

    /**
     * Get the effective API key for a provider.
     *
     * @param string $provider
     * @param string $instancekey The instance-level key
     * @return string
     */
    public static function get_effective_api_key(string $provider, string $instancekey): string {
        // Built-in providers (like Albert) use the built-in key.
        if (self::has_builtin_key($provider)) {
            return self::get_builtin_api_key($provider);
        }

        // Other providers use instance-level key.
        return $instancekey;
    }

    /**
     * Test connection to an AI provider.
     *
     * @param string $provider
     * @param string $apikey
     * @return array ['success' => bool, 'message' => string]
     */
    public static function test_connection(string $provider, string $apikey): array {
        try {
            $providerinstance = ai_evaluator::get_provider($provider, $apikey);
            return $providerinstance->test_connection();
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get available providers with labels.
     *
     * @return array
     */
    public static function get_provider_options(): array {
        return [
            '' => get_string('ai_provider_select', 'redaction'),
            'openai' => 'OpenAI (GPT-4)',
            'anthropic' => 'Anthropic (Claude)',
            'mistral' => 'Mistral AI',
            'albert' => 'Albert (Etalab) - ' . get_string('ai_provider_builtin', 'redaction'),
        ];
    }
}
