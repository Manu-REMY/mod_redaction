<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * External function for getting submission history.
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
use external_multiple_structure;
use external_value;
use context_module;

/**
 * External function to get version history for a submission.
 */
class get_history extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'submissionid' => new external_value(PARAM_INT, 'Submission ID'),
        ]);
    }

    /**
     * Get submission history.
     *
     * @param int $cmid Course module ID
     * @param int $submissionid Submission ID
     * @return array
     */
    public static function execute(int $cmid, int $submissionid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'submissionid' => $submissionid,
        ]);

        $cm = get_coursemodule_from_id('redaction', $params['cmid'], 0, false, MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/redaction:viewhistory', $context);

        $submission = $DB->get_record('redaction_submission', ['id' => $params['submissionid']], '*', MUST_EXIST);

        if ($submission->redactionid != $redaction->id) {
            throw new \moodle_exception('invalidsubmission', 'mod_redaction');
        }

        $history = redaction_get_history($params['submissionid']);

        $historydata = [];
        foreach ($history as $version) {
            $savedbyname = '';
            if (!empty($version->firstname) && !empty($version->lastname)) {
                $savedbyname = $version->firstname . ' ' . $version->lastname;
            } else {
                $user = $DB->get_record('user', ['id' => $version->saved_by], 'firstname, lastname');
                if ($user) {
                    $savedbyname = fullname($user);
                }
            }

            $historydata[] = [
                'id' => (int) $version->id,
                'version_number' => (int) $version->version_number,
                'titre' => $version->titre ?? '',
                'word_count' => (int) $version->word_count,
                'char_count' => (int) $version->char_count,
                'saved_by' => $savedbyname,
                'date' => userdate($version->timecreated, get_string('strftimedatetime', 'langconfig')),
            ];
        }

        return [
            'success' => true,
            'history' => $historydata,
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
            'history' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'History entry ID'),
                    'version_number' => new external_value(PARAM_INT, 'Version number'),
                    'titre' => new external_value(PARAM_TEXT, 'Title at this version'),
                    'word_count' => new external_value(PARAM_INT, 'Word count'),
                    'char_count' => new external_value(PARAM_INT, 'Character count'),
                    'saved_by' => new external_value(PARAM_TEXT, 'Name of the user who saved'),
                    'date' => new external_value(PARAM_TEXT, 'Formatted date'),
                ]),
                'Version history entries'
            ),
        ]);
    }
}
