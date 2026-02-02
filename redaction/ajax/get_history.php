<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX endpoint to get version history for a submission.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

$cmid = required_param('id', PARAM_INT);
$submissionid = required_param('submissionid', PARAM_INT);

// Validate session.
require_sesskey();

// Get course module.
$cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

// Check login.
require_login($course, false, $cm);

$context = context_module::instance($cm->id);

// Check capability.
require_capability('mod/redaction:viewhistory', $context);

$result = ['success' => false, 'history' => []];

try {
    // Get the submission to verify access.
    $submission = $DB->get_record('redaction_submission', ['id' => $submissionid], '*', MUST_EXIST);

    // Verify the submission belongs to this activity.
    if ($submission->redactionid != $redaction->id) {
        throw new moodle_exception('invalidsubmission', 'redaction');
    }

    // Get history records.
    $history = redaction_get_history($submissionid);

    $historydata = [];
    foreach ($history as $version) {
        $savedbyname = '';
        if ($version->firstname && $version->lastname) {
            $savedbyname = $version->firstname . ' ' . $version->lastname;
        } else {
            $user = $DB->get_record('user', ['id' => $version->saved_by], 'firstname, lastname');
            if ($user) {
                $savedbyname = fullname($user);
            }
        }

        $historydata[] = [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'titre' => $version->titre,
            'word_count' => $version->word_count,
            'char_count' => $version->char_count,
            'saved_by' => $savedbyname,
            'date' => userdate($version->timecreated, get_string('strftimedatetime', 'langconfig'))
        ];
    }

    $result = [
        'success' => true,
        'history' => $historydata
    ];

} catch (Exception $e) {
    $result = ['success' => false, 'message' => $e->getMessage()];
}

echo json_encode($result);
