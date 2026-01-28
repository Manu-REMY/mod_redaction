<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX endpoint to get AI evaluation status.
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
$submissionid = optional_param('submissionid', 0, PARAM_INT);
$evaluationid = optional_param('evaluationid', 0, PARAM_INT);

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

$result = ['success' => false, 'has_evaluation' => false];

try {
    $evaluation = null;

    if ($evaluationid > 0) {
        $evaluation = $DB->get_record('redaction_ai_evaluations', ['id' => $evaluationid]);
    } elseif ($submissionid > 0) {
        $evaluation = \mod_redaction\ai_evaluator::get_evaluation($submissionid);
    }

    if ($evaluation) {
        $result = [
            'success' => true,
            'has_evaluation' => true,
            'evaluation_id' => $evaluation->id,
            'status' => $evaluation->status,
        ];

        if ($evaluation->status === 'completed' || $evaluation->status === 'applied') {
            $result['grade'] = $evaluation->parsed_grade;
            $result['feedback'] = $evaluation->parsed_feedback;

            if (!empty($evaluation->criteria_json)) {
                $result['criteria'] = json_decode($evaluation->criteria_json, true);
            }

            $result['tokens_used'] = ($evaluation->prompt_tokens ?? 0) + ($evaluation->completion_tokens ?? 0);
        }

        if ($evaluation->status === 'failed' && !empty($evaluation->error_message)) {
            $result['error_message'] = $evaluation->error_message;
        }
    } else {
        $result = [
            'success' => true,
            'has_evaluation' => false,
        ];
    }

} catch (Exception $e) {
    $result = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($result);
