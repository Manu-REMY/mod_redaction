<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX endpoint to trigger AI evaluation.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');
require_once(__DIR__ . '/../classes/ai_evaluator.php');

$cmid = required_param('id', PARAM_INT);
$submissionid = required_param('submissionid', PARAM_INT);
$action = optional_param('action', 'evaluate', PARAM_ALPHA);

// Validate session.
require_sesskey();

// Get course module.
$cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

// Check login.
require_login($course, false, $cm);

$context = context_module::instance($cm->id);

// Check capability.
require_capability('mod/redaction:grade', $context);

$result = ['success' => false, 'message' => ''];

try {
    // Check if AI is enabled.
    if (!$redaction->ai_enabled) {
        throw new moodle_exception('ai_not_enabled', 'redaction');
    }

    // Get submission.
    $submission = $DB->get_record('redaction_submission', ['id' => $submissionid], '*', MUST_EXIST);

    // Verify submission belongs to this activity.
    if ($submission->redactionid != $redaction->id) {
        throw new moodle_exception('invalidsubmission', 'redaction');
    }

    // Check for pending evaluation.
    if (\mod_redaction\ai_evaluator::has_pending_evaluation($submissionid)) {
        $result = [
            'success' => false,
            'message' => get_string('ai_evaluation_pending', 'redaction')
        ];
        echo json_encode($result);
        exit;
    }

    // Queue evaluation.
    $evaluationid = \mod_redaction\ai_evaluator::queue_evaluation(
        $redaction->id,
        $submissionid,
        $submission->groupid,
        $submission->userid
    );

    // Trigger AI evaluation requested event.
    $event = \mod_redaction\event\ai_evaluation_requested::create([
        'objectid' => $evaluationid,
        'context' => $context,
        'userid' => $USER->id,
        'other' => ['submissionid' => $submissionid],
    ]);
    $event->trigger();

    $result = [
        'success' => true,
        'evaluationid' => $evaluationid,
        'message' => get_string('ai_evaluation_pending', 'redaction')
    ];

} catch (Exception $e) {
    $result = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($result);
