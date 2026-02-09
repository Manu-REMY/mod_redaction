<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * External function: get AI evaluation status from mobile app.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use external_multiple_structure;

/**
 * Get evaluation status external function.
 */
class get_evaluation_status extends external_api {

    /**
     * Parameters definition.
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
     * Execute the function.
     *
     * @param int $cmid Course module ID
     * @param int $submissionid Submission ID
     * @return array
     */
    public static function execute(int $cmid, int $submissionid): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'submissionid' => $submissionid,
        ]);

        // Get module context.
        $cm = get_coursemodule_from_id('redaction', $params['cmid'], 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/redaction:view', $context);

        // Verify user can access this submission.
        $submission = $DB->get_record('redaction_submission', [
            'id' => $params['submissionid'],
            'redactionid' => $redaction->id,
        ], '*', MUST_EXIST);

        // Check access: user must be submission owner or have grade capability.
        $cangrade = has_capability('mod/redaction:grade', $context);
        $isowner = ($submission->userid == $USER->id);
        $isgroupmember = false;
        if ($redaction->group_submission && $submission->groupid > 0) {
            $isgroupmember = groups_is_member($submission->groupid, $USER->id);
        }

        if (!$cangrade && !$isowner && !$isgroupmember) {
            throw new \required_capability_exception($context, 'mod/redaction:grade', 'nopermissions', '');
        }

        // Get latest evaluation.
        $evaluations = $DB->get_records_sql(
            'SELECT * FROM {redaction_ai_evaluations} WHERE submissionid = ? ORDER BY timecreated DESC',
            [$params['submissionid']],
            0,
            1
        );
        $evaluation = !empty($evaluations) ? reset($evaluations) : null;

        $result = [
            'hasevaluation' => !empty($evaluation),
            'status' => $evaluation ? $evaluation->status : '',
            'grade' => ($evaluation && $evaluation->parsed_grade !== null) ? (float) $evaluation->parsed_grade : null,
            'feedback' => ($evaluation && $evaluation->parsed_feedback) ? $evaluation->parsed_feedback : '',
            'provider' => $evaluation ? ($evaluation->provider ?? '') : '',
            'model' => $evaluation ? ($evaluation->model ?? '') : '',
            'timecreated' => $evaluation ? (int) $evaluation->timecreated : 0,
            'criteria' => [],
        ];

        // Parse criteria if available.
        if ($evaluation && !empty($evaluation->criteria_json)) {
            $criteria = json_decode($evaluation->criteria_json, true);
            if (is_array($criteria)) {
                foreach ($criteria as $criterion) {
                    $result['criteria'][] = [
                        'name' => $criterion['name'] ?? '',
                        'score' => isset($criterion['score']) ? (float) $criterion['score'] : 0,
                        'max' => isset($criterion['max']) ? (float) $criterion['max'] : 5,
                        'comment' => $criterion['comment'] ?? '',
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'hasevaluation' => new external_value(PARAM_BOOL, 'Whether an evaluation exists'),
            'status' => new external_value(PARAM_TEXT, 'Evaluation status'),
            'grade' => new external_value(PARAM_FLOAT, 'AI grade', VALUE_OPTIONAL),
            'feedback' => new external_value(PARAM_RAW, 'AI feedback'),
            'provider' => new external_value(PARAM_TEXT, 'AI provider'),
            'model' => new external_value(PARAM_TEXT, 'AI model'),
            'timecreated' => new external_value(PARAM_INT, 'Evaluation creation time'),
            'criteria' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Criterion name'),
                    'score' => new external_value(PARAM_FLOAT, 'Score'),
                    'max' => new external_value(PARAM_FLOAT, 'Maximum score'),
                    'comment' => new external_value(PARAM_RAW, 'Comment'),
                ]),
                'Evaluation criteria',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
