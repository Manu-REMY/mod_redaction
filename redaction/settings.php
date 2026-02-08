<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Admin settings for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Albert API key section.
    $settings->add(new admin_setting_heading(
        'mod_redaction/albertheading',
        get_string('settings_albert_heading', 'redaction'),
        get_string('settings_albert_heading_desc', 'redaction')
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_redaction/albert_api_key',
        get_string('settings_albert_api_key', 'redaction'),
        get_string('settings_albert_api_key_desc', 'redaction'),
        ''
    ));

    // Rate limiting section.
    $settings->add(new admin_setting_heading(
        'mod_redaction/ratelimitheading',
        get_string('settings_rate_limit', 'redaction'),
        get_string('settings_rate_limit_desc', 'redaction')
    ));

    $settings->add(new admin_setting_configtext(
        'mod_redaction/ai_rate_limit',
        get_string('settings_rate_limit', 'redaction'),
        get_string('settings_rate_limit_desc', 'redaction'),
        '60',
        PARAM_INT
    ));

    // Token pricing section.
    $settings->add(new admin_setting_heading(
        'mod_redaction/tokenpricingheading',
        get_string('settings_token_pricing_heading', 'redaction'),
        get_string('settings_token_pricing_heading_desc', 'redaction')
    ));

    $defaultpricing = json_encode([
        'openai' => ['input' => 2.50, 'output' => 10.00],
        'anthropic' => ['input' => 3.00, 'output' => 15.00],
        'mistral' => ['input' => 0.25, 'output' => 0.25],
        'albert' => ['input' => 0.00, 'output' => 0.00],
    ], JSON_PRETTY_PRINT);

    $settings->add(new admin_setting_configtextarea(
        'mod_redaction/token_pricing',
        get_string('settings_token_pricing', 'redaction'),
        get_string('settings_token_pricing_desc', 'redaction'),
        $defaultpricing
    ));
}
