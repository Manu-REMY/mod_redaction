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
 * External function for bulk unlock of submissions.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;
use context_module;

/**
 * External function to unlock multiple submissions in one call.
 *
 * Mirrors the per-submission unlock logic in submit_action::execute (case 'unlock'):
 * sets status from 1 (locked) back to 0 (draft) so the student can edit again.
 */
class bulk_unlock extends external_api {

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
                'Array of submission IDs to unlock'
            ),
        ]);
    }

    /**
     * Bulk unlock submissions.
     *
     * @param int   $cmid          Course module ID
     * @param int[] $submissionids Submission IDs to unlock
     * @return array{success: bool, unlocked: int, skipped: int, errors: string[]}
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

        $results = [
            'success' => true,
            'unlocked' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($params['submissionids'] as $submissionid) {
            $submission = $DB->get_record('redaction_submission', [
                'id' => (int) $submissionid,
                'redactionid' => $redaction->id,
            ]);

            if (!$submission) {
                $results['skipped']++;
                continue;
            }

            if ((int) $submission->status !== 1) {
                $results['skipped']++;
                continue;
            }

            $submission->status = 0;
            $submission->timemodified = time();
            try {
                if ($DB->update_record('redaction_submission', $submission)) {
                    $results['unlocked']++;
                } else {
                    $results['skipped']++;
                }
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
            'unlocked' => new external_value(PARAM_INT, 'Number of submissions unlocked'),
            'skipped' => new external_value(PARAM_INT, 'Number of submissions skipped'),
            'errors' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Error message'),
                'List of error messages',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
