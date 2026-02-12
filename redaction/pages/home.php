<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Home page content for redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Check if consignes are complete.
$consignescomplete = redaction_consignes_complete($redaction->id);
$correctioncomplete = redaction_correction_complete($redaction->id);

// Get consignes data.
$consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);

// Build template data.
$templatedata = [
    'isteacher' => $caneditconsignes,
    'cangrade' => $cangrade,
    'consignescomplete' => $consignescomplete,
    'correctioncomplete' => $correctioncomplete,
    'consignesurl' => (new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'consignes']))->out(false),
    'correctionurl' => (new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'correction']))->out(false),
    'gradingurl' => (new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'grading']))->out(false),
    'redactionurl' => (new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'redaction']))->out(false),
    'haswarning' => false,
    'warningmessage' => '',
    'hasgroupinfo' => false,
    'groupname' => '',
    'hassubmission' => false,
    'issubmitted' => false,
    'isgraded' => false,
    'hasdraft' => false,
    'gradeformatted' => '',
    'submittedcount' => 0,
    'submittedcountstr' => '',
    'consignestitle' => '',
    'buttonlabel' => get_string('work_on_it', 'redaction'),
];

// Teacher-specific data.
if ($caneditconsignes && $cangrade) {
    $submittedcount = $DB->count_records('redaction_submission', [
        'redactionid' => $redaction->id,
        'status' => 1,
    ]);
    $templatedata['submittedcount'] = $submittedcount;
    $templatedata['submittedcountstr'] = get_string('submissions_count', 'redaction', $submittedcount);
}

// Student-specific data.
if (!$caneditconsignes) {
    if (!$consignescomplete) {
        $templatedata['haswarning'] = true;
        $templatedata['warningmessage'] = get_string('error:noconsignes', 'redaction');
    } else if ($redaction->group_submission && $usergroup == 0) {
        $templatedata['haswarning'] = true;
        $templatedata['warningmessage'] = get_string('no_group_error', 'redaction');
    } else {
        // Get group info if group mode.
        if ($redaction->group_submission && $usergroup > 0) {
            $groupinfo = $DB->get_record('groups', ['id' => $usergroup]);
            if ($groupinfo) {
                $templatedata['hasgroupinfo'] = true;
                $templatedata['groupname'] = s($groupinfo->name);
            }
        }

        // Get submission.
        $submission = redaction_get_or_create_submission($redaction, $usergroup, $USER->id);
        $templatedata['hassubmission'] = true;

        if ($submission->status == 1) {
            $templatedata['issubmitted'] = true;
            $templatedata['buttonlabel'] = get_string('view_my_redaction', 'redaction');

            if ($submission->grade !== null) {
                $templatedata['isgraded'] = true;
                $templatedata['gradeformatted'] = number_format($submission->grade, 1);
            }
        } else if (!empty($submission->contenu)) {
            $templatedata['hasdraft'] = true;
        }

        $templatedata['consignestitle'] = ($consignes && $consignes->titre) ? s($consignes->titre) : '';
    }
}

// Render using the Output API.
/** @var \mod_redaction\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_redaction');
echo $renderer->render_home($templatedata);
