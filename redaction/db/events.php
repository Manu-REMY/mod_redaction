<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Event observer definitions for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_redaction\event\submission_submitted',
        'callback' => '\mod_redaction\notification_manager::handle_submission_received',
    ],
    [
        'eventname' => '\mod_redaction\event\grade_updated',
        'callback' => '\mod_redaction\notification_manager::handle_grade_released',
    ],
    [
        'eventname' => '\mod_redaction\event\ai_evaluation_completed',
        'callback' => '\mod_redaction\notification_manager::handle_ai_evaluation_complete',
    ],
    [
        'eventname' => '\mod_redaction\event\ai_grade_applied',
        'callback' => '\mod_redaction\notification_manager::handle_ai_grade_applied',
    ],
];
