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
 * External function for generating AI summary.
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
 * External function to generate/refresh AI summary for teacher dashboard.
 */
class generate_ai_summary extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'force' => new external_value(PARAM_BOOL, 'Force regeneration even if cached', VALUE_DEFAULT, false),
            'groupid' => new external_value(PARAM_INT, 'Group ID filter (0 = global)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Generate or refresh the AI summary.
     *
     * @param int $cmid Course module ID
     * @param bool $force Force regeneration
     * @return array Result with summary data
     */
    public static function execute(int $cmid, bool $force = false, int $groupid = 0): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'force' => $force,
            'groupid' => $groupid,
        ]);

        // Get course module and context.
        $cm = get_coursemodule_from_id('redaction', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        // Check capability.
        self::validate_context($context);
        require_capability('mod/redaction:grade', $context);

        // Get the redaction instance.
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        // Generate the summary.
        $generator = new \mod_redaction\dashboard\ai_summary_generator($redaction->id, $params['groupid']);
        $summary = $generator->get_summary($params['force']);

        if ($summary === null) {
            // Log so we can investigate why generation came back empty in prod
            // (provider timeout, rate-limit, parse failure, no eligible evals…).
            error_log(sprintf(
                '[mod_redaction summary] null result: cmid=%d, redactionid=%d, groupid=%d, force=%s',
                $params['cmid'],
                $redaction->id,
                $params['groupid'],
                $params['force'] ? 'true' : 'false'
            ));
            // The summary key is omitted (not set to null): it is declared
            // VALUE_OPTIONAL in execute_returns and clean_returnvalue rejects
            // null for an external_single_structure, surfacing as
            // "invalidresponse" client-side.
            return [
                'success' => false,
                'message' => get_string('dashboard_no_data', 'mod_redaction'),
            ];
        }

        return [
            'success' => true,
            'message' => get_string('dashboard_summary_generated', 'mod_redaction'),
            'summary' => [
                'difficulties' => $summary->difficulties,
                'strengths' => $summary->strengths,
                'recommendations' => $summary->recommendations,
                'general_observation' => $summary->general_observation,
                'submissions_analyzed' => $summary->submissions_analyzed,
                'provider' => $summary->provider,
                'model' => $summary->model,
                'timemodified' => $summary->timemodified,
            ],
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
            'summary' => new external_single_structure([
                'difficulties' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'Difficulty description'),
                    'List of identified difficulties',
                    VALUE_OPTIONAL
                ),
                'strengths' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'Strength description'),
                    'List of identified strengths',
                    VALUE_OPTIONAL
                ),
                'recommendations' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'Recommendation description'),
                    'List of recommendations',
                    VALUE_OPTIONAL
                ),
                'general_observation' => new external_value(PARAM_TEXT, 'General observation', VALUE_OPTIONAL),
                'submissions_analyzed' => new external_value(PARAM_INT, 'Number of submissions analyzed', VALUE_OPTIONAL),
                'provider' => new external_value(PARAM_TEXT, 'AI provider used', VALUE_OPTIONAL),
                'model' => new external_value(PARAM_TEXT, 'AI model used', VALUE_OPTIONAL),
                'timemodified' => new external_value(PARAM_INT, 'Last modification timestamp', VALUE_OPTIONAL),
            ], 'Summary data', VALUE_OPTIONAL),
        ]);
    }
}
