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
 * External function for bulk AI evaluation.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/redaction/lib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;
use context_module;

/**
 * External function to queue multiple submissions for AI evaluation.
 */
class bulk_evaluate extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'submissionids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Submission ID'),
                'Array of submission IDs to evaluate'
            ),
        ]);
    }

    /**
     * Bulk evaluate submissions.
     *
     * @param int $cmid Course module ID
     * @param array $submissionids Submission IDs
     * @return array
     */
    public static function execute(int $cmid, array $submissionids): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'submissionids' => $submissionids,
        ]);

        $cm = get_coursemodule_from_id('redaction', $params['cmid'], 0, false, MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/redaction:grade', $context);

        if (!$redaction->ai_enabled) {
            return [
                'success' => false,
                'queued' => 0,
                'skipped' => 0,
                'errors' => [get_string('ai_not_enabled', 'mod_redaction')],
            ];
        }

        $results = [
            'success' => true,
            'queued' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($params['submissionids'] as $submissionid) {
            $submission = $DB->get_record('redaction_submission', [
                'id' => (int) $submissionid,
                'redactionid' => $redaction->id,
            ]);

            if (!$submission || empty($submission->contenu)) {
                $results['skipped']++;
                continue;
            }

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
                    true
                );
                $results['queued']++;
            } catch (\Exception $e) {
                $results['errors'][] = $e->getMessage();
                $results['skipped']++;
            }
        }

        return $results;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation succeeded'),
            'queued' => new external_value(PARAM_INT, 'Number of evaluations queued'),
            'skipped' => new external_value(PARAM_INT, 'Number of submissions skipped'),
            'errors' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Error message'),
                'List of error messages',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
