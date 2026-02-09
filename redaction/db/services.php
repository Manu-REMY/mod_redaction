<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * External functions and service definitions.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_redaction_generate_ai_summary' => [
        'classname' => 'mod_redaction\external\generate_ai_summary',
        'methodname' => 'execute',
        'description' => 'Generate or refresh AI summary for teacher dashboard',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/redaction:grade',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_get_submission' => [
        'classname' => 'mod_redaction\external\get_submission',
        'methodname' => 'execute',
        'description' => 'Get submission data for mobile app',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/redaction:view',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_submit_work' => [
        'classname' => 'mod_redaction\external\submit_work',
        'methodname' => 'execute',
        'description' => 'Submit or save work from mobile app',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/redaction:submit',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_get_evaluation_status' => [
        'classname' => 'mod_redaction\external\get_evaluation_status',
        'methodname' => 'execute',
        'description' => 'Get AI evaluation status for mobile app',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/redaction:view',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
