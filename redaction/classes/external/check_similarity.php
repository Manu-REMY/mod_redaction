<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * External function for checking submission similarity.
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
use external_multiple_structure;
use external_value;
use context_module;

/**
 * External function to check similarity between submissions.
 */
class check_similarity extends external_api {

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
     * Check submission similarity.
     *
     * @param int $cmid Course module ID
     * @param int $submissionid Submission ID
     * @return array
     */
    public static function execute(int $cmid, int $submissionid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'submissionid' => $submissionid,
        ]);

        $cm = get_coursemodule_from_id('redaction', $params['cmid'], 0, false, MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/redaction:grade', $context);

        $results = \mod_redaction\plagiarism_checker::check_submission($redaction->id, $params['submissionid']);
        $threshold = \mod_redaction\plagiarism_checker::get_threshold();

        $formattedresults = [];
        foreach ($results as $result) {
            $result['alert'] = ($result['similarity_percent'] >= $threshold);
            $formattedresults[] = [
                'submission_id' => (int) ($result['submission_id'] ?? 0),
                'student_name' => $result['student_name'] ?? '',
                'similarity_percent' => (float) $result['similarity_percent'],
                'alert' => $result['alert'],
            ];
        }

        return [
            'success' => true,
            'threshold' => (float) $threshold,
            'results' => $formattedresults,
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
            'threshold' => new external_value(PARAM_FLOAT, 'Similarity alert threshold'),
            'results' => new external_multiple_structure(
                new external_single_structure([
                    'submission_id' => new external_value(PARAM_INT, 'Compared submission ID'),
                    'student_name' => new external_value(PARAM_TEXT, 'Student name'),
                    'similarity_percent' => new external_value(PARAM_FLOAT, 'Similarity percentage'),
                    'alert' => new external_value(PARAM_BOOL, 'Whether this exceeds the threshold'),
                ]),
                'Similarity comparison results'
            ),
        ]);
    }
}
