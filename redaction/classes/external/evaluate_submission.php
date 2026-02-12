<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * External function for triggering AI evaluation.
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
 * External function to trigger AI evaluation for a submission.
 */
class evaluate_submission extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'submissionid' => new external_value(PARAM_INT, 'Submission ID'),
        ]);
    }

    /**
     * Trigger AI evaluation.
     *
     * @param int $cmid Course module ID
     * @param int $submissionid Submission ID
     * @return array
     */
    public static function execute(int $cmid, int $submissionid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'submissionid' => $submissionid,
        ]);

        $cm = get_coursemodule_from_id('redaction', $params['cmid'], 0, false, MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/redaction:grade', $context);

        if (!$redaction->ai_enabled) {
            return [
                'success' => false,
                'evaluationid' => 0,
                'message' => get_string('ai_not_enabled', 'mod_redaction'),
            ];
        }

        $submission = $DB->get_record('redaction_submission', ['id' => $params['submissionid']], '*', MUST_EXIST);

        if ($submission->redactionid != $redaction->id) {
            throw new \moodle_exception('invalidsubmission', 'mod_redaction');
        }

        if (\mod_redaction\ai_evaluator::has_pending_evaluation($params['submissionid'])) {
            return [
                'success' => false,
                'evaluationid' => 0,
                'message' => get_string('ai_evaluation_pending', 'mod_redaction'),
            ];
        }

        $evaluationid = \mod_redaction\ai_evaluator::queue_evaluation(
            $redaction->id,
            $params['submissionid'],
            $submission->groupid,
            $submission->userid
        );

        $event = \mod_redaction\event\ai_evaluation_requested::create([
            'objectid' => $evaluationid,
            'context' => $context,
            'userid' => $USER->id,
            'other' => ['submissionid' => $params['submissionid']],
        ]);
        $event->trigger();

        return [
            'success' => true,
            'evaluationid' => $evaluationid,
            'message' => get_string('ai_evaluation_pending', 'mod_redaction'),
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
            'evaluationid' => new external_value(PARAM_INT, 'Evaluation ID if queued'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
        ]);
    }
}
