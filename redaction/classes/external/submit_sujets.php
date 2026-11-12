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
 * External function: submit the answers of the group to the AI correction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Uses one attempt of the group and queues the correction.
 */
class submit_sujets extends sujets_base {

    /** @var int Seconds to wait for the submission lock. */
    const LOCK_TIMEOUT = 10;

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
     * Queues the correction of the saved answers and counts the attempt, exactly once.
     *
     * The check, the count and the queuing happen under a lock and in one transaction, so two members
     * clicking together use one attempt only (the second gets evaluation_pending).
     *
     * @param int $cmid Course module ID.
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        [$cm, $redaction, $context, $groupid, $submission] = self::contexte($params['cmid']);

        // The attribution is written (and its own lock taken) before the transaction below, never inside it.
        \mod_redaction\sujets::de_soumission($redaction, $submission);

        $factory = \core\lock\lock_config::get_lock_factory('mod_redaction');
        $lock = $factory->get_lock('redaction_submit_' . $submission->id, self::LOCK_TIMEOUT);
        if (!$lock) {
            return ['success' => false, 'evaluationid' => 0, 'message' => get_string('sujets_verrou', 'mod_redaction')];
        }

        try {
            // Read again, inside the transaction and under the lock: the other member may have just submitted.
            $transaction = $DB->start_delegated_transaction();
            try {
                $submission = $DB->get_record('redaction_submission', ['id' => $submission->id], '*', MUST_EXIST);
                $check = redaction_can_submit_attempt($redaction, $submission);
                if (!$check['allowed']) {
                    $transaction->allow_commit();
                    return [
                        'success' => false,
                        'evaluationid' => 0,
                        'message' => get_string('training_error_' . $check['reason'], 'mod_redaction'),
                    ];
                }

                // Count first, then queue and capture the members, all or nothing: a fast failure gives the
                // attempt back after the commit, and the task only becomes visible with the commit.
                $DB->execute(
                    'UPDATE {redaction_submission} SET training_count = training_count + 1 WHERE id = ?',
                    [$submission->id]
                );
                $evaluationid = \mod_redaction\ai_evaluator::queue_evaluation(
                    $redaction->id,
                    $submission->id,
                    $groupid,
                    $USER->id
                );
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        \mod_redaction\event\ai_evaluation_requested::create([
            'objectid' => $evaluationid,
            'context' => $context,
            'userid' => $USER->id,
            'other' => ['submissionid' => $submission->id],
        ])->trigger();

        return [
            'success' => true,
            'evaluationid' => $evaluationid,
            'message' => get_string('sujets_correction_en_cours', 'mod_redaction'),
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the correction was queued'),
            'evaluationid' => new external_value(PARAM_INT, 'Evaluation ID if queued'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
        ]);
    }
}
