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
 * External function for autosaving content.
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
use core_external\external_value;
use context_module;

/**
 * External function to autosave content on consignes, redaction, and correction pages.
 */
class autosave extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'page' => new external_value(PARAM_ALPHA, 'Page type: consignes, redaction, or correction'),
            'data' => new external_value(PARAM_RAW, 'JSON-encoded form data'),
            'groupid' => new external_value(PARAM_INT, 'Group ID', VALUE_DEFAULT, 0),
            'action' => new external_value(PARAM_ALPHA, 'Action: save or lock', VALUE_DEFAULT, 'save'),
            'locked' => new external_value(PARAM_INT, 'Lock state for lock action', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Autosave content.
     *
     * @param int $cmid Course module ID
     * @param string $page Page type
     * @param string $data JSON form data
     * @param int $groupid Group ID
     * @param string $action Action type
     * @param int $locked Lock state
     * @return array
     */
    public static function execute(int $cmid, string $page, string $data, int $groupid = 0,
            string $action = 'save', int $locked = 0): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'page' => $page,
            'data' => $data,
            'groupid' => $groupid,
            'action' => $action,
            'locked' => $locked,
        ]);

        $cm = get_coursemodule_from_id('redaction', $params['cmid'], 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        // Decode data.
        $formdata = json_decode($params['data'], true);
        if ($formdata === null) {
            return ['success' => false, 'message' => get_string('ajax:invalid_json', 'mod_redaction')];
        }

        switch ($params['page']) {
            case 'consignes':
                require_capability('mod/redaction:editconsignes', $context);

                $consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
                if (!$consignes) {
                    redaction_create_consignes($redaction->id);
                    $consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
                }

                // Handle lock action.
                if ($params['action'] === 'lock') {
                    $consignes->locked = $params['locked'];
                    $consignes->timemodified = time();
                    $DB->update_record('redaction_consignes', $consignes);
                    return ['success' => true, 'message' => get_string('ajax:lock_updated', 'mod_redaction')];
                }

                // Check if locked.
                if ($consignes->locked) {
                    return ['success' => false, 'message' => get_string('ajax:consignes_locked', 'mod_redaction')];
                }

                $allowedfields = ['titre', 'consignes', 'criteres', 'documents'];
                foreach ($allowedfields as $field) {
                    if (isset($formdata[$field])) {
                        $consignes->$field = $formdata[$field];
                    }
                }
                $consignes->timemodified = time();
                $DB->update_record('redaction_consignes', $consignes);

                return ['success' => true, 'message' => get_string('ajax:saved', 'mod_redaction')];

            case 'redaction':
                require_capability('mod/redaction:submit', $context);

                $submission = redaction_get_or_create_submission($redaction, $params['groupid'], $USER->id);

                if ($submission->status == 1) {
                    return ['success' => false, 'message' => get_string('ajax:already_submitted', 'mod_redaction')];
                }

                $allowedfields = ['titre', 'contenu'];
                foreach ($allowedfields as $field) {
                    if (isset($formdata[$field])) {
                        $submission->$field = $formdata[$field];
                    }
                }
                $submission->timemodified = time();
                $DB->update_record('redaction_submission', $submission);

                redaction_save_history($submission, $USER->id);

                return ['success' => true, 'message' => get_string('ajax:saved', 'mod_redaction')];

            case 'correction':
                require_capability('mod/redaction:editconsignes', $context);

                $correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
                if (!$correction) {
                    redaction_create_correction($redaction->id);
                    $correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
                }

                $allowedfields = ['modele_reponse', 'grille_criteres', 'ai_instructions',
                    'submission_date', 'deadline_date'];
                foreach ($allowedfields as $field) {
                    if (isset($formdata[$field])) {
                        if (($field === 'submission_date' || $field === 'deadline_date')
                                && is_string($formdata[$field]) && !empty($formdata[$field])) {
                            $correction->$field = strtotime($formdata[$field]);
                        } else {
                            $correction->$field = $formdata[$field];
                        }
                    }
                }
                $correction->timemodified = time();
                $DB->update_record('redaction_correction', $correction);

                return ['success' => true, 'message' => get_string('ajax:saved', 'mod_redaction')];

            default:
                return ['success' => false, 'message' => get_string('ajax:invalid_page', 'mod_redaction')];
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
