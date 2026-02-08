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

    /** @var array Providers with admin-configured API keys */
    const ADMIN_KEY_PROVIDERS = ['albert'];

    /** @var array Available providers */
    const PROVIDERS = ['openai', 'anthropic', 'mistral', 'albert'];

    /**
     * Encrypt an API key for storage.
     *
     * Requires Moodle 4.0+ encryption (guaranteed by plugin requiring Moodle 4.5+).
     *
     * @param string $apikey
     * @return string
     * @throws \moodle_exception If encryption is not available.
     */
    public static function encrypt_api_key(string $apikey): string {
        if (empty($apikey)) {
            return '';
        }

        if (!class_exists('\core\encryption')) {
            throw new \moodle_exception('error:encryption_unavailable', 'redaction');
        }

        return \core\encryption::encrypt($apikey);
    }

    /**
     * Decrypt a stored API key.
     *
     * Handles migration from legacy base64-encoded keys.
     *
     * @param string $encrypted
     * @return string
     * @throws \moodle_exception If encryption is not available.
     */
    public static function decrypt_api_key(string $encrypted): string {
        if (empty($encrypted)) {
            return '';
        }

        if (!class_exists('\core\encryption')) {
            throw new \moodle_exception('error:encryption_unavailable', 'redaction');
        }

        try {
            return \core\encryption::decrypt($encrypted);
        } catch (\Exception $e) {
            // Attempt legacy base64 migration: decode and return.
            $decoded = base64_decode($encrypted, true);
            if ($decoded !== false && $decoded !== $encrypted) {
                return $decoded;
            }
            return $encrypted;
        }
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
    public static function has_admin_key(string $provider): bool {
        return in_array($provider, self::ADMIN_KEY_PROVIDERS);
    }

    /**
     * Get the admin-configured API key for a provider.
     *
     * @param string $provider
     * @return string
     */
    public static function get_admin_api_key(string $provider): string {
        $key = get_config('mod_redaction', $provider . '_api_key');
        return !empty($key) ? $key : '';
    }

    /**
     * Get the effective API key for a provider.
     *
     * Priority: instance-level key > admin-configured key.
     * For Albert, admin key is used when no instance key is set.
     *
     * @param string $provider
     * @param string $instancekey The instance-level key
     * @return string
     */
    public static function get_effective_api_key(string $provider, string $instancekey): string {
        // Use instance key if available.
        if (!empty($instancekey)) {
            return $instancekey;
        }

        // Fall back to admin-configured key (applies to all providers including Albert).
        return self::get_admin_api_key($provider);
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
            'albert' => 'Albert (Etalab) - ' . get_string('ai_provider_admin_key', 'redaction'),
        ];
    }
}
