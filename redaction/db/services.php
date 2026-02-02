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
];
