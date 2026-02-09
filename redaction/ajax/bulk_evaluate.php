<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX handler for bulk AI evaluation.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/mod/redaction/lib.php');

$cmid = required_param('id', PARAM_INT);
$submissionids = required_param('submissionids', PARAM_RAW);

$cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
require_sesskey();

$context = context_module::instance($cm->id);
require_capability('mod/redaction:grade', $context);

header('Content-Type: application/json');

if (!$redaction->ai_enabled) {
    echo json_encode(['success' => false, 'message' => get_string('ai_not_enabled', 'redaction')]);
    die();
}

// Parse submission IDs.
$ids = json_decode($submissionids, true);
if (!is_array($ids) || empty($ids)) {
    echo json_encode(['success' => false, 'message' => get_string('ajax:invalid_json', 'redaction')]);
    die();
}

$results = [
    'success' => true,
    'queued' => 0,
    'skipped' => 0,
    'errors' => [],
];

foreach ($ids as $submissionid) {
    $submissionid = (int) $submissionid;
    $submission = $DB->get_record('redaction_submission', [
        'id' => $submissionid,
        'redactionid' => $redaction->id,
    ]);

    if (!$submission || empty($submission->contenu)) {
        $results['skipped']++;
        continue;
    }

    // Skip if already has pending evaluation.
    if (\mod_redaction\ai_evaluator::has_pending_evaluation($submissionid)) {
        $results['skipped']++;
        continue;
    }

    try {
        \mod_redaction\ai_evaluator::queue_evaluation(
            $redaction->id,
            $submissionid,
            $submission->groupid,
            $submission->userid,
            true // Skip rate limit for bulk operations.
        );
        $results['queued']++;
    } catch (\Exception $e) {
        $results['errors'][] = $e->getMessage();
        $results['skipped']++;
    }
}

echo json_encode($results);
