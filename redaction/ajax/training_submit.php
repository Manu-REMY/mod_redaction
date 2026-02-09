<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX endpoint to submit for training feedback.
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
require_capability('mod/redaction:submit', $context);

$result = ['success' => false, 'message' => ''];

try {
    // Check if AI and training are enabled.
    if (!$redaction->ai_enabled || !$redaction->training_enabled) {
        throw new moodle_exception('training_error_training_not_enabled', 'redaction');
    }

    // Get user's group and submission.
    $usergroup = redaction_get_user_group($cm, $USER->id);
    $submission = redaction_get_or_create_submission($redaction, $usergroup, $USER->id);
    $correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);

    // Check training eligibility.
    $check = redaction_can_training_submit($redaction, $submission, $correction);
    if (!$check['allowed']) {
        if ($check['reason'] === 'cooldown_active' && isset($check['remaining'])) {
            $minutes = ceil($check['remaining'] / 60);
            throw new moodle_exception('training_error_cooldown_remaining', 'redaction', '', $minutes);
        }
        throw new moodle_exception('training_error_' . $check['reason'], 'redaction');
    }

    // Check content is not empty.
    if (empty(trim(strip_tags($submission->contenu ?? '')))) {
        throw new moodle_exception('error:empty_content', 'redaction');
    }

    // Check minimum change.
    if ($submission->training_count > 0 &&
        !redaction_check_min_change($submission->contenu, $submission->id, $redaction->training_min_change)) {
        throw new moodle_exception('training_error_min_change', 'redaction');
    }

    // Save history entry.
    redaction_save_history($submission, $USER->id);

    // Update training counter.
    $submission->training_count = ($submission->training_count ?? 0) + 1;
    $submission->last_training_time = time();
    $submission->timemodified = time();
    $DB->update_record('redaction_submission', $submission);

    // Queue AI evaluation with is_training=1.
    $evaluationid = \mod_redaction\ai_evaluator::queue_training_evaluation(
        $redaction->id,
        $submission->id,
        $submission->groupid,
        $submission->userid
    );

    $remaining = $redaction->training_max_attempts > 0
        ? $redaction->training_max_attempts - $submission->training_count
        : -1; // -1 means unlimited.

    $result = [
        'success' => true,
        'evaluationid' => $evaluationid,
        'training_count' => $submission->training_count,
        'max_attempts' => $redaction->training_max_attempts,
        'remaining' => $remaining,
        'message' => get_string('training_submitted', 'redaction'),
    ];

} catch (Exception $e) {
    $result = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($result);
