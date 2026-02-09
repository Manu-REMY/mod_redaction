<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * External function: get submission for mobile app.
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
use external_multiple_structure;

/**
 * Get submission external function.
 */
class get_submission extends external_api {

    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $cmid Course module ID
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        $cmid = $params['cmid'];

        // Get module context.
        $cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/redaction:view', $context);

        // Get consignes.
        $consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
        $consignesdata = [
            'titre' => $consignes ? format_string($consignes->titre ?? '') : '',
            'consignes' => $consignes ? format_text($consignes->consignes ?? '', $consignes->consignesformat ?? FORMAT_HTML) : '',
            'criteres' => $consignes ? format_text($consignes->criteres ?? '', $consignes->criteresformat ?? FORMAT_HTML) : '',
            'locked' => $consignes ? (bool) $consignes->locked : false,
        ];

        // Get submission.
        $groupid = 0;
        if ($redaction->group_submission) {
            $groupid = redaction_get_user_group($cm, $USER->id);
        }

        $submission = redaction_get_or_create_submission($redaction, $groupid, $USER->id);

        $submissiondata = [
            'id' => $submission->id,
            'titre' => $submission->titre ?? '',
            'contenu' => format_text($submission->contenu ?? '', $submission->contenuformat ?? FORMAT_HTML),
            'status' => (int) $submission->status,
            'statustext' => $submission->status == 1 ?
                get_string('status_submitted', 'redaction') :
                get_string('status_draft', 'redaction'),
            'grade' => $submission->grade !== null ? (float) $submission->grade : null,
            'feedback' => $submission->feedback ?? '',
            'timesubmitted' => (int) ($submission->timesubmitted ?? 0),
            'timemodified' => (int) ($submission->timemodified ?? 0),
        ];

        // Get AI evaluation status if enabled.
        $evaluationdata = [
            'ai_enabled' => (bool) $redaction->ai_enabled,
            'status' => '',
            'grade' => null,
            'feedback' => '',
        ];

        if ($redaction->ai_enabled && $submission->id) {
            $evaluation = $DB->get_records_sql(
                'SELECT * FROM {redaction_ai_evaluations} WHERE submissionid = ? ORDER BY timecreated DESC',
                [$submission->id],
                0,
                1
            );
            $evaluation = !empty($evaluation) ? reset($evaluation) : null;

            if ($evaluation) {
                $evaluationdata['status'] = $evaluation->status;
                $evaluationdata['grade'] = $evaluation->parsed_grade !== null ? (float) $evaluation->parsed_grade : null;
                $evaluationdata['feedback'] = $evaluation->parsed_feedback ?? '';
            }
        }

        return [
            'activityname' => format_string($redaction->name),
            'groupsubmission' => (bool) $redaction->group_submission,
            'consignes' => $consignesdata,
            'submission' => $submissiondata,
            'evaluation' => $evaluationdata,
        ];
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'activityname' => new external_value(PARAM_TEXT, 'Activity name'),
            'groupsubmission' => new external_value(PARAM_BOOL, 'Group submission mode'),
            'consignes' => new external_single_structure([
                'titre' => new external_value(PARAM_TEXT, 'Consignes title'),
                'consignes' => new external_value(PARAM_RAW, 'Detailed instructions HTML'),
                'criteres' => new external_value(PARAM_RAW, 'Evaluation criteria HTML'),
                'locked' => new external_value(PARAM_BOOL, 'Whether consignes are locked'),
            ]),
            'submission' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Submission ID'),
                'titre' => new external_value(PARAM_TEXT, 'Submission title'),
                'contenu' => new external_value(PARAM_RAW, 'Submission content HTML'),
                'status' => new external_value(PARAM_INT, 'Status: 0=draft, 1=submitted'),
                'statustext' => new external_value(PARAM_TEXT, 'Status text'),
                'grade' => new external_value(PARAM_FLOAT, 'Grade', VALUE_OPTIONAL),
                'feedback' => new external_value(PARAM_RAW, 'Teacher feedback'),
                'timesubmitted' => new external_value(PARAM_INT, 'Submission timestamp'),
                'timemodified' => new external_value(PARAM_INT, 'Last modified timestamp'),
            ]),
            'evaluation' => new external_single_structure([
                'ai_enabled' => new external_value(PARAM_BOOL, 'AI evaluation enabled'),
                'status' => new external_value(PARAM_TEXT, 'AI evaluation status'),
                'grade' => new external_value(PARAM_FLOAT, 'AI suggested grade', VALUE_OPTIONAL),
                'feedback' => new external_value(PARAM_RAW, 'AI feedback'),
            ]),
        ]);
    }
}
