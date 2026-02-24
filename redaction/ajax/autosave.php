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
 * AJAX autosave handler for redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

$cmid = required_param('cmid', PARAM_INT);
$page = required_param('page', PARAM_ALPHA);
$data = required_param('data', PARAM_RAW);
$groupid = optional_param('groupid', 0, PARAM_INT);
$action = optional_param('action', 'save', PARAM_ALPHA);

// Validate session.
require_sesskey();

// Get course module.
$cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

// Check login.
require_login($course, false, $cm);

$context = context_module::instance($cm->id);

// Decode data.
$formdata = json_decode($data, true);
if ($formdata === null) {
    echo json_encode(['success' => false, 'message' => get_string('ajax:invalid_json', 'redaction')]);
    exit;
}

$result = ['success' => false, 'message' => ''];

try {
    switch ($page) {
        case 'consignes':
            // Teacher consignes page.
            require_capability('mod/redaction:editconsignes', $context);

            $consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
            if (!$consignes) {
                redaction_create_consignes($redaction->id);
                $consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
            }

            // Handle lock action.
            if ($action === 'lock') {
                $locked = required_param('locked', PARAM_INT);
                $consignes->locked = $locked;
                $consignes->timemodified = time();
                $DB->update_record('redaction_consignes', $consignes);

                $result = ['success' => true, 'message' => get_string('ajax:lock_updated', 'redaction')];
                break;
            }

            // Check if locked.
            if ($consignes->locked) {
                $result = ['success' => false, 'message' => get_string('ajax:consignes_locked', 'redaction')];
                break;
            }

            // Allowed fields for consignes.
            $allowedfields = ['titre', 'consignes', 'criteres', 'documents'];

            foreach ($allowedfields as $field) {
                if (isset($formdata[$field])) {
                    $consignes->$field = $formdata[$field];
                }
            }

            $consignes->timemodified = time();
            $DB->update_record('redaction_consignes', $consignes);

            $result = ['success' => true, 'message' => get_string('ajax:saved', 'redaction')];
            break;

        case 'redaction':
            // Student writing page.
            require_capability('mod/redaction:submit', $context);

            // Get or create submission.
            $submission = redaction_get_or_create_submission($redaction, $groupid, $USER->id);

            // Check if already submitted.
            if ($submission->status == 1) {
                $result = ['success' => false, 'message' => get_string('ajax:already_submitted', 'redaction')];
                break;
            }

            // Allowed fields for submission.
            $allowedfields = ['titre', 'contenu'];

            foreach ($allowedfields as $field) {
                if (isset($formdata[$field])) {
                    $submission->$field = $formdata[$field];
                }
            }

            $submission->timemodified = time();
            $DB->update_record('redaction_submission', $submission);

            // Save to history.
            redaction_save_history($submission, $USER->id);

            $result = ['success' => true, 'message' => get_string('ajax:saved', 'redaction')];
            break;

        case 'correction':
            // Teacher correction model page.
            require_capability('mod/redaction:editconsignes', $context);

            $correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
            if (!$correction) {
                redaction_create_correction($redaction->id);
                $correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
            }

            // Allowed fields for correction model.
            $allowedfields = ['modele_reponse', 'grille_criteres', 'ai_instructions', 'submission_date', 'deadline_date'];

            foreach ($allowedfields as $field) {
                if (isset($formdata[$field])) {
                    if ($field === 'submission_date' || $field === 'deadline_date') {
                        // Convert date string to timestamp if needed.
                        if (is_string($formdata[$field]) && !empty($formdata[$field])) {
                            $correction->$field = strtotime($formdata[$field]);
                        } else {
                            $correction->$field = $formdata[$field];
                        }
                    } else {
                        $correction->$field = $formdata[$field];
                    }
                }
            }

            $correction->timemodified = time();
            $DB->update_record('redaction_correction', $correction);

            $result = ['success' => true, 'message' => get_string('ajax:saved', 'redaction')];
            break;

        default:
            $result = ['success' => false, 'message' => get_string('ajax:invalid_page', 'redaction')];
    }
} catch (Exception $e) {
    $result = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($result);
