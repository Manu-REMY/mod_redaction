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
 * AJAX submit handler for redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

$cmid = required_param('id', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);
$groupid = optional_param('groupid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$submissionid = optional_param('submissionid', 0, PARAM_INT);

// Validate session.
require_sesskey();

// Get course module.
$cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

// Check login.
require_login($course, false, $cm);

$context = context_module::instance($cm->id);

$result = ['success' => false, 'message' => ''];

try {
    switch ($action) {
        case 'submit':
            // Submit the redaction.
            require_capability('mod/redaction:submit', $context);

            // Use current user if not specified.
            if ($userid == 0) {
                $userid = $USER->id;
            }

            // Get submission.
            $submission = redaction_get_or_create_submission($redaction, $groupid, $userid);

            // Check if already submitted.
            if ($submission->status == 1) {
                $result = ['success' => false, 'message' => get_string('error:alreadysubmitted', 'redaction')];
                break;
            }

            // Check if content is not empty.
            if (empty($submission->contenu)) {
                $result = ['success' => false, 'message' => get_string('error:empty_content', 'redaction')];
                break;
            }

            // Submit.
            if (redaction_submit($redaction, $groupid, $userid)) {
                // Save final version to history.
                $submission = $DB->get_record('redaction_submission', ['id' => $submission->id]);
                redaction_save_history($submission, $USER->id);

                $result = ['success' => true, 'message' => get_string('ajax:submitted', 'redaction')];
            } else {
                $result = ['success' => false, 'message' => get_string('ajax:submit_failed', 'redaction')];
            }
            break;

        case 'unlock':
            // Unlock submission (teachers only).
            require_capability('mod/redaction:grade', $context);

            // Get submission by ID or by group/user.
            if ($submissionid > 0) {
                $submission = $DB->get_record('redaction_submission', ['id' => $submissionid]);
                if ($submission && $submission->redactionid != $redaction->id) {
                    $result = ['success' => false, 'message' => get_string('ajax:invalid_submission', 'redaction')];
                    break;
                }
            } else {
                // Use specified user or current user.
                if ($userid == 0) {
                    $userid = $USER->id;
                }
                $submission = redaction_get_or_create_submission($redaction, $groupid, $userid);
            }

            if (!$submission) {
                $result = ['success' => false, 'message' => get_string('error:nosubmission', 'redaction')];
                break;
            }

            // Check if actually submitted.
            if ($submission->status != 1) {
                $result = ['success' => false, 'message' => get_string('error:notsubmitted', 'redaction')];
                break;
            }

            // Revert to draft directly.
            $submission->status = 0;
            $submission->timemodified = time();
            if ($DB->update_record('redaction_submission', $submission)) {
                $result = ['success' => true, 'message' => get_string('ajax:unlocked', 'redaction')];
            } else {
                $result = ['success' => false, 'message' => get_string('ajax:unlock_failed', 'redaction')];
            }
            break;

        default:
            $result = ['success' => false, 'message' => get_string('ajax:invalid_action', 'redaction')];
    }
} catch (Exception $e) {
    $result = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($result);
