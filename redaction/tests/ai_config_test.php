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
 * Unit tests for mod_redaction ai_config class.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

/**
 * Test class for ai_config.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_redaction\ai_config
 */
class ai_config_test extends \advanced_testcase {

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Test encrypt and decrypt cycle produces the original key.
     */
    public function test_encrypt_decrypt_cycle(): void {
        $originalkey = 'sk-test-api-key-12345';

        $encrypted = ai_config::encrypt_api_key($originalkey);
        $this->assertNotEquals($originalkey, $encrypted);
        $this->assertNotEmpty($encrypted);

        $decrypted = ai_config::decrypt_api_key($encrypted);
        $this->assertEquals($originalkey, $decrypted);
    }

    /**
     * Test encrypt returns empty string for empty input.
     */
    public function test_encrypt_empty_key(): void {
        $result = ai_config::encrypt_api_key('');
        $this->assertEquals('', $result);
    }

    /**
     * Test decrypt returns empty string for empty input.
     */
    public function test_decrypt_empty_key(): void {
        $result = ai_config::decrypt_api_key('');
        $this->assertEquals('', $result);
    }

    /**
     * Test decrypt handles legacy base64-encoded keys.
     */
    public function test_decrypt_legacy_base64(): void {
        $originalkey = 'sk-legacy-key-abc';
        $legacyencoded = base64_encode($originalkey);

        // Should decode the base64 legacy key.
        $decrypted = ai_config::decrypt_api_key($legacyencoded);
        $this->assertEquals($originalkey, $decrypted);
    }

    /**
     * Test PROVIDERS constant contains expected providers.
     */
    public function test_providers_list(): void {
        $providers = ai_config::PROVIDERS;

        $this->assertContains('openai', $providers);
        $this->assertContains('anthropic', $providers);
        $this->assertContains('mistral', $providers);
        $this->assertContains('albert', $providers);
        $this->assertCount(4, $providers);
    }

    /**
     * Test ADMIN_KEY_PROVIDERS constant.
     */
    public function test_admin_key_providers(): void {
        $this->assertContains('albert', ai_config::ADMIN_KEY_PROVIDERS);
    }

    /**
     * Test has_admin_key returns true for albert.
     */
    public function test_has_admin_key_albert(): void {
        $this->assertTrue(ai_config::has_admin_key('albert'));
    }

    /**
     * Test has_admin_key returns false for openai.
     */
    public function test_has_admin_key_openai(): void {
        $this->assertFalse(ai_config::has_admin_key('openai'));
    }

    /**
     * Test has_admin_key returns false for anthropic.
     */
    public function test_has_admin_key_anthropic(): void {
        $this->assertFalse(ai_config::has_admin_key('anthropic'));
    }

    /**
     * Test has_admin_key returns false for mistral.
     */
    public function test_has_admin_key_mistral(): void {
        $this->assertFalse(ai_config::has_admin_key('mistral'));
    }

    /**
     * Test get_effective_api_key uses instance key when provided.
     */
    public function test_get_effective_api_key_instance(): void {
        $instancekey = 'sk-instance-key-123';
        $result = ai_config::get_effective_api_key('openai', $instancekey);
        $this->assertEquals($instancekey, $result);
    }

    /**
     * Test get_effective_api_key falls back to admin key.
     */
    public function test_get_effective_api_key_admin_fallback(): void {
        set_config('albert_api_key', 'admin-key-123', 'mod_redaction');

        $result = ai_config::get_effective_api_key('albert', '');
        $this->assertEquals('admin-key-123', $result);
    }

    /**
     * Test get_effective_api_key returns empty when no key available.
     */
    public function test_get_effective_api_key_empty(): void {
        $result = ai_config::get_effective_api_key('openai', '');
        $this->assertEquals('', $result);
    }

    /**
     * Test get_admin_api_key returns configured key.
     */
    public function test_get_admin_api_key(): void {
        set_config('albert_api_key', 'admin-albert-key', 'mod_redaction');

        $result = ai_config::get_admin_api_key('albert');
        $this->assertEquals('admin-albert-key', $result);
    }

    /**
     * Test get_admin_api_key returns empty for unconfigured provider.
     */
    public function test_get_admin_api_key_empty(): void {
        $result = ai_config::get_admin_api_key('openai');
        $this->assertEquals('', $result);
    }

    /**
     * Test get_config returns configuration for valid instance.
     */
    public function test_get_config(): void {
        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_redaction');

        $redaction = $generator->create_instance([
            'course' => $course->id,
            'ai_enabled' => 1,
            'ai_provider' => 'albert',
            'ai_auto_apply' => 0,
        ]);

        $config = ai_config::get_config($redaction->id);

        $this->assertNotNull($config);
        $this->assertTrue($config->enabled);
        $this->assertEquals('albert', $config->provider);
        $this->assertFalse($config->auto_apply);
    }

    /**
     * Test get_config returns null for non-existent instance.
     */
    public function test_get_config_nonexistent(): void {
        $config = ai_config::get_config(999999);
        $this->assertNull($config);
    }

    /**
     * Test get_provider_options returns valid options array.
     */
    public function test_get_provider_options(): void {
        $options = ai_config::get_provider_options();

        $this->assertIsArray($options);
        $this->assertArrayHasKey('', $options);
        $this->assertArrayHasKey('openai', $options);
        $this->assertArrayHasKey('anthropic', $options);
        $this->assertArrayHasKey('mistral', $options);
        $this->assertArrayHasKey('albert', $options);
    }

    /**
     * Test multiple encrypt/decrypt cycles with different keys.
     */
    public function test_encrypt_decrypt_different_keys(): void {
        $key1 = 'sk-first-key-111';
        $key2 = 'sk-second-key-222';

        $encrypted1 = ai_config::encrypt_api_key($key1);
        $encrypted2 = ai_config::encrypt_api_key($key2);

        // Encrypted values should differ.
        $this->assertNotEquals($encrypted1, $encrypted2);

        // Each should decrypt to its original.
        $this->assertEquals($key1, ai_config::decrypt_api_key($encrypted1));
        $this->assertEquals($key2, ai_config::decrypt_api_key($encrypted2));
    }
}
