<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * External function for training mode submission.
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
 * External function to submit work for training feedback.
 */
class training_submit extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    /**
     * Submit for training feedback.
     *
     * @param int $cmid Course module ID
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
        ]);

        $cm = get_coursemodule_from_id('redaction', $params['cmid'], 0, false, MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/redaction:submit', $context);

        if (!$redaction->ai_enabled || !$redaction->training_enabled) {
            throw new \moodle_exception('training_error_training_not_enabled', 'mod_redaction');
        }

        $usergroup = redaction_get_user_group($cm, $USER->id);
        $submission = redaction_get_or_create_submission($redaction, $usergroup, $USER->id);
        $correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);

        $check = redaction_can_training_submit($redaction, $submission, $correction);
        if (!$check['allowed']) {
            if ($check['reason'] === 'cooldown_active' && isset($check['remaining'])) {
                $minutes = ceil($check['remaining'] / 60);
                throw new \moodle_exception('training_error_cooldown_remaining', 'mod_redaction', '', $minutes);
            }
            throw new \moodle_exception('training_error_' . $check['reason'], 'mod_redaction');
        }

        if (empty(trim(strip_tags($submission->contenu ?? '')))) {
            throw new \moodle_exception('error:empty_content', 'mod_redaction');
        }

        if ($submission->training_count > 0 &&
                !redaction_check_min_change($submission->contenu, $submission->id, $redaction->training_min_change)) {
            throw new \moodle_exception('training_error_min_change', 'mod_redaction');
        }

        redaction_save_history($submission, $USER->id);

        $submission->training_count = ($submission->training_count ?? 0) + 1;
        $submission->last_training_time = time();
        $submission->timemodified = time();
        $DB->update_record('redaction_submission', $submission);

        $evaluationid = \mod_redaction\ai_evaluator::queue_training_evaluation(
            $redaction->id,
            $submission->id,
            $submission->groupid,
            $submission->userid
        );

        $remaining = $redaction->training_max_attempts > 0
            ? $redaction->training_max_attempts - $submission->training_count
            : -1;

        return [
            'success' => true,
            'evaluationid' => $evaluationid,
            'training_count' => (int) $submission->training_count,
            'max_attempts' => (int) $redaction->training_max_attempts,
            'remaining' => $remaining,
            'message' => get_string('training_submitted', 'mod_redaction'),
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
            'evaluationid' => new external_value(PARAM_INT, 'Evaluation ID'),
            'training_count' => new external_value(PARAM_INT, 'Number of training submissions made'),
            'max_attempts' => new external_value(PARAM_INT, 'Maximum attempts allowed'),
            'remaining' => new external_value(PARAM_INT, 'Remaining attempts (-1 = unlimited)'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
        ]);
    }
}
