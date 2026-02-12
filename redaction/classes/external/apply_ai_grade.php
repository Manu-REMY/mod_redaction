<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * External function for applying AI grade to a submission.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/redaction/lib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_module;

/**
 * External function to apply an AI evaluation grade to a submission.
 */
class apply_ai_grade extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'evaluationid' => new external_value(PARAM_INT, 'AI evaluation ID'),
        ]);
    }

    /**
     * Apply AI grade.
     *
     * @param int $cmid Course module ID
     * @param int $evaluationid Evaluation ID
     * @return array
     */
    public static function execute(int $cmid, int $evaluationid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'evaluationid' => $evaluationid,
        ]);

        $cm = get_coursemodule_from_id('redaction', $params['cmid'], 0, false, MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/redaction:grade', $context);

        $evaluation = $DB->get_record('redaction_ai_evaluations', ['id' => $params['evaluationid']], '*', MUST_EXIST);

        if ($evaluation->redactionid != $redaction->id) {
            throw new \moodle_exception('invalidevaluation', 'mod_redaction');
        }

        if ($evaluation->status !== 'completed') {
            throw new \moodle_exception('evaluationnotcomplete', 'mod_redaction');
        }

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

        // Trigger event.
        $event = \mod_redaction\event\ai_grade_applied::create([
            'objectid' => $submission->id,
            'context' => $context,
            'userid' => $USER->id,
            'other' => [
                'evaluationid' => $evaluation->id,
                'grade' => $evaluation->parsed_grade,
                'provider' => $evaluation->provider,
            ],
        ]);
        $event->trigger();

        return [
            'success' => true,
            'message' => get_string('grade_saved', 'mod_redaction'),
            'grade' => (float) $evaluation->parsed_grade,
            'feedback' => $evaluation->parsed_feedback ?? '',
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation succeeded'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
            'grade' => new external_value(PARAM_FLOAT, 'Applied grade', VALUE_OPTIONAL),
            'feedback' => new external_value(PARAM_RAW, 'Applied feedback', VALUE_OPTIONAL),
        ]);
    }
}
