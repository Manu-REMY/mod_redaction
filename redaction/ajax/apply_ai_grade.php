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
 * AJAX endpoint to apply AI grade to a submission.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

$cmid = required_param('id', PARAM_INT);
$evaluationid = required_param('evaluationid', PARAM_INT);

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
    // Get the AI evaluation.
    $evaluation = $DB->get_record('redaction_ai_evaluations', ['id' => $evaluationid], '*', MUST_EXIST);

    // Verify it belongs to this activity.
    if ($evaluation->redactionid != $redaction->id) {
        throw new moodle_exception('invalidevaluation', 'redaction');
    }

    // Check evaluation is completed.
    if ($evaluation->status !== 'completed') {
        throw new moodle_exception('evaluationnotcomplete', 'redaction');
    }

    // Get the submission.
    $submission = $DB->get_record('redaction_submission', ['id' => $evaluation->submissionid], '*', MUST_EXIST);

    // Apply the grade.
    $submission->grade = $evaluation->parsed_grade;
    $submission->feedback = $evaluation->parsed_feedback;
    $submission->timemodified = time();
    $DB->update_record('redaction_submission', $submission);

    // Mark evaluation as applied.
    $evaluation->status = 'applied';
    $evaluation->applied_by = $USER->id;
    $evaluation->applied_at = time();
    $evaluation->timemodified = time();
    $DB->update_record('redaction_ai_evaluations', $evaluation);

    // Update gradebook.
    redaction_update_grades($redaction);

    $result = [
        'success' => true,
        'message' => get_string('grade_saved', 'redaction'),
        'grade' => $evaluation->parsed_grade,
        'feedback' => $evaluation->parsed_feedback
    ];

} catch (Exception $e) {
    $result = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($result);
