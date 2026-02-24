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
 * External function: submit work from mobile app.
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

/**
 * Submit work external function.
 */
class submit_work extends external_api {

    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'titre' => new external_value(PARAM_TEXT, 'Submission title', VALUE_DEFAULT, ''),
            'contenu' => new external_value(PARAM_RAW, 'Submission content'),
            'submit' => new external_value(PARAM_BOOL, 'Whether to submit (true) or save as draft (false)', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $cmid Course module ID
     * @param string $titre Submission title
     * @param string $contenu Submission content
     * @param bool $submit Whether to submit
     * @return array
     */
    public static function execute(int $cmid, string $titre, string $contenu, bool $submit): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'titre' => $titre,
            'contenu' => $contenu,
            'submit' => $submit,
        ]);

        // Get module context.
        $cm = get_coursemodule_from_id('redaction', $params['cmid'], 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/redaction:submit', $context);

        // Check consignes are locked.
        if (!redaction_consignes_locked($redaction->id)) {
            throw new \moodle_exception('consignes_not_ready', 'redaction');
        }

        // Get submission.
        $groupid = 0;
        if ($redaction->group_submission) {
            $groupid = redaction_get_user_group($cm, $USER->id);
        }

        $submission = redaction_get_or_create_submission($redaction, $groupid, $USER->id);

        // Check if already submitted.
        if ($submission->status == 1) {
            throw new \moodle_exception('error:alreadysubmitted', 'redaction');
        }

        // Update content.
        $submission->titre = $params['titre'];
        $submission->contenu = clean_text($params['contenu']);
        $submission->contenuformat = FORMAT_HTML;
        $submission->timemodified = time();
        $DB->update_record('redaction_submission', $submission);

        // Save to history.
        redaction_save_history($submission, $USER->id);

        // Submit if requested.
        if ($params['submit']) {
            if (empty($submission->contenu)) {
                throw new \moodle_exception('error:empty_content', 'redaction');
            }

            $submission->status = 1;
            $submission->timesubmitted = time();
            $submission->timemodified = time();
            $DB->update_record('redaction_submission', $submission);

            // Trigger submission event.
            $event = \mod_redaction\event\submission_submitted::create([
                'objectid' => $submission->id,
                'context' => $context,
                'userid' => $USER->id,
                'other' => [
                    'groupid' => $submission->groupid,
                ],
            ]);
            $event->trigger();
        }

        return [
            'success' => true,
            'submissionid' => $submission->id,
            'status' => (int) $submission->status,
            'statustext' => $submission->status == 1 ?
                get_string('status_submitted', 'redaction') :
                get_string('status_draft', 'redaction'),
        ];
    }

    /**
     * Return description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation succeeded'),
            'submissionid' => new external_value(PARAM_INT, 'Submission ID'),
            'status' => new external_value(PARAM_INT, 'New status'),
            'statustext' => new external_value(PARAM_TEXT, 'Status text'),
        ]);
    }
}
