<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Message provider definitions for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    // Notify teachers when a student submits their writing.
    'submission_received' => [
        'capability' => 'mod/redaction:grade',
        'defaults' => [
            'airnotifier' => MESSAGE_PERMITTED,
            'popup' => MESSAGE_PERMITTED,
            'email' => MESSAGE_PERMITTED,
        ],
    ],
    // Notify students when their grade is released.
    'grade_released' => [
        'capability' => 'mod/redaction:submit',
        'defaults' => [
            'airnotifier' => MESSAGE_PERMITTED,
            'popup' => MESSAGE_PERMITTED,
            'email' => MESSAGE_PERMITTED,
        ],
    ],
    // Notify teachers when an AI evaluation is complete.
    'ai_evaluation_complete' => [
        'capability' => 'mod/redaction:grade',
        'defaults' => [
            'airnotifier' => MESSAGE_PERMITTED,
            'popup' => MESSAGE_PERMITTED,
            'email' => MESSAGE_PERMITTED,
        ],
    ],
];
