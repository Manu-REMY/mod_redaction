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
 * External function for bulk AI grade application.
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
 * External function to apply multiple AI grades.
 */
class bulk_apply_grade extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'evaluationids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Evaluation ID'),
                'Array of evaluation IDs to apply'
            ),
        ]);
    }

    /**
     * Bulk apply AI grades.
     *
     * @param int $cmid Course module ID
     * @param array $evaluationids Evaluation IDs
     * @return array
     */
    public static function execute(int $cmid, array $evaluationids): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'evaluationids' => $evaluationids,
        ]);

        $cm = get_coursemodule_from_id('redaction', $params['cmid'], 0, false, MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/redaction:grade', $context);

        $results = [
            'success' => true,
            'applied' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($params['evaluationids'] as $evaluationid) {
            $evaluation = $DB->get_record('redaction_ai_evaluations', [
                'id' => (int) $evaluationid,
                'redactionid' => $redaction->id,
            ]);

            if (!$evaluation || !in_array($evaluation->status, ['completed', 'pending_apply'])) {
                $results['skipped']++;
                continue;
            }

            try {
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
            'applied' => new external_value(PARAM_INT, 'Number of grades applied'),
            'skipped' => new external_value(PARAM_INT, 'Number of evaluations skipped'),
            'errors' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Error message'),
                'List of error messages',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
