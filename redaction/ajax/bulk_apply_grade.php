<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX handler for bulk AI grade application.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/mod/redaction/lib.php');

$cmid = required_param('id', PARAM_INT);
$evaluationids = required_param('evaluationids', PARAM_RAW);

$cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
require_sesskey();

$context = context_module::instance($cm->id);
require_capability('mod/redaction:grade', $context);

header('Content-Type: application/json');

// Parse evaluation IDs.
$ids = json_decode($evaluationids, true);
if (!is_array($ids) || empty($ids)) {
    echo json_encode(['success' => false, 'message' => get_string('ajax:invalid_json', 'redaction')]);
    die();
}

$results = [
    'success' => true,
    'applied' => 0,
    'skipped' => 0,
    'errors' => [],
];

foreach ($ids as $evaluationid) {
    $evaluationid = (int) $evaluationid;
    $evaluation = $DB->get_record('redaction_ai_evaluations', [
        'id' => $evaluationid,
        'redactionid' => $redaction->id,
    ]);

    if (!$evaluation || !in_array($evaluation->status, ['completed', 'pending_apply'])) {
        $results['skipped']++;
        continue;
    }

    try {
        // Force status to completed for pending_apply before applying.
        if ($evaluation->status === 'pending_apply') {
            $evaluation->status = 'completed';
            $DB->update_record('redaction_ai_evaluations', $evaluation);
        }

        $applied = \mod_redaction\ai_evaluator::apply_evaluation($evaluationid, $USER->id);
        if ($applied) {
            $results['applied']++;
        } else {
            $results['skipped']++;
        }
    } catch (\Exception $e) {
        $results['errors'][] = $e->getMessage();
        $results['skipped']++;
    }
}

echo json_encode($results);
