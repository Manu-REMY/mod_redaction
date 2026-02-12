<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * External function for submit/unlock actions.
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
 * External function to submit or unlock a submission.
 */
class submit_action extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'action' => new external_value(PARAM_ALPHA, 'Action: submit or unlock'),
            'groupid' => new external_value(PARAM_INT, 'Group ID', VALUE_DEFAULT, 0),
            'userid' => new external_value(PARAM_INT, 'User ID', VALUE_DEFAULT, 0),
            'submissionid' => new external_value(PARAM_INT, 'Submission ID', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Submit or unlock a submission.
     *
     * @param int $cmid Course module ID
     * @param string $action Action type
     * @param int $groupid Group ID
     * @param int $userid User ID
     * @param int $submissionid Submission ID
     * @return array
     */
    public static function execute(int $cmid, string $action, int $groupid = 0,
            int $userid = 0, int $submissionid = 0): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'action' => $action,
            'groupid' => $groupid,
            'userid' => $userid,
            'submissionid' => $submissionid,
        ]);

        $cm = get_coursemodule_from_id('redaction', $params['cmid'], 0, false, MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        switch ($params['action']) {
            case 'submit':
                require_capability('mod/redaction:submit', $context);

                $userid = $params['userid'] ?: $USER->id;
                $submission = redaction_get_or_create_submission($redaction, $params['groupid'], $userid);

                if ($submission->status == 1) {
                    return ['success' => false, 'message' => get_string('error:alreadysubmitted', 'mod_redaction')];
                }

                if (empty($submission->contenu)) {
                    return ['success' => false, 'message' => get_string('error:empty_content', 'mod_redaction')];
                }

                if (redaction_submit($redaction, $params['groupid'], $userid)) {
                    $submission = $DB->get_record('redaction_submission', ['id' => $submission->id]);
                    redaction_save_history($submission, $USER->id);

                    $event = \mod_redaction\event\submission_submitted::create([
                        'objectid' => $submission->id,
                        'context' => $context,
                        'userid' => $USER->id,
                    ]);
                    $event->trigger();

                    return ['success' => true, 'message' => get_string('ajax:submitted', 'mod_redaction')];
                }
                return ['success' => false, 'message' => get_string('ajax:submit_failed', 'mod_redaction')];

            case 'unlock':
                require_capability('mod/redaction:grade', $context);

                if ($params['submissionid'] > 0) {
                    $submission = $DB->get_record('redaction_submission', ['id' => $params['submissionid']]);
                    if ($submission && $submission->redactionid != $redaction->id) {
                        return ['success' => false, 'message' => get_string('ajax:invalid_submission', 'mod_redaction')];
                    }
                } else {
                    $userid = $params['userid'] ?: $USER->id;
                    $submission = redaction_get_or_create_submission($redaction, $params['groupid'], $userid);
                }

                if (!$submission) {
                    return ['success' => false, 'message' => get_string('error:nosubmission', 'mod_redaction')];
                }

                if ($submission->status != 1) {
                    return ['success' => false, 'message' => get_string('error:notsubmitted', 'mod_redaction')];
                }

                $submission->status = 0;
                $submission->timemodified = time();
                if ($DB->update_record('redaction_submission', $submission)) {
                    return ['success' => true, 'message' => get_string('ajax:unlocked', 'mod_redaction')];
                }
                return ['success' => false, 'message' => get_string('ajax:unlock_failed', 'mod_redaction')];

            default:
                return ['success' => false, 'message' => get_string('ajax:invalid_action', 'mod_redaction')];
        }
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
        ]);
    }
}
