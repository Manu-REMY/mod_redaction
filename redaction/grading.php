<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Grading interface for redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Page setup.
$PAGE->set_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'grading']);
$PAGE->set_title(format_string($redaction->name) . ' - ' . get_string('grading', 'redaction'));

// Get group mode.
$groupmode = groups_get_activity_groupmode($cm);
$isGroupSubmission = $redaction->group_submission;

// Build navigation items (groups or users).
$navitems = [];
$currentid = optional_param('itemid', 0, PARAM_INT);

if ($isGroupSubmission) {
    $groups = groups_get_all_groups($course->id);
    foreach ($groups as $group) {
        $navitems[$group->id] = [
            'id' => $group->id,
            'name' => $group->name,
            'type' => 'group'
        ];
    }
} else {
    $coursecontext = context_course::instance($course->id);
    $users = get_enrolled_users($coursecontext, 'mod/redaction:submit', 0, 'u.*', 'u.lastname, u.firstname');
    foreach ($users as $user) {
        $navitems[$user->id] = [
            'id' => $user->id,
            'name' => fullname($user),
            'type' => 'user'
        ];
    }
}

// Auto-select first item if none specified.
if ($currentid == 0 && !empty($navitems)) {
    $currentid = array_key_first($navitems);
}

// Calculate prev/next navigation.
$navkeys = array_keys($navitems);
$currentpos = array_search($currentid, $navkeys);
$previd = ($currentpos > 0) ? $navkeys[$currentpos - 1] : null;
$nextid = ($currentpos < count($navkeys) - 1) ? $navkeys[$currentpos + 1] : null;

// Get current submission.
$submission = null;
if ($currentid > 0) {
    if ($isGroupSubmission) {
        $submission = $DB->get_record('redaction_submission', [
            'redactionid' => $redaction->id,
            'groupid' => $currentid,
            'userid' => 0
        ]);
    } else {
        $submission = $DB->get_record('redaction_submission', [
            'redactionid' => $redaction->id,
            'userid' => $currentid
        ]);
    }
}

// Handle grade submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $grade = optional_param('grade', null, PARAM_FLOAT);
    $feedback = optional_param('feedback', '', PARAM_RAW);

    if ($submission) {
        $oldgrade = $submission->grade;

        $submission->grade = $grade;
        $submission->feedback = $feedback;
        $submission->timemodified = time();
        $DB->update_record('redaction_submission', $submission);

        // Trigger grade updated event.
        $event = \mod_redaction\event\grade_updated::create([
            'objectid' => $submission->id,
            'context' => $context,
            'userid' => $USER->id,
            'other' => [
                'oldgrade' => $oldgrade,
                'newgrade' => $grade,
            ],
        ]);
        $event->trigger();

        // Update gradebook.
        redaction_update_grades($redaction);

        $redirectid = $nextid ?? $currentid;
        $url = new moodle_url('/mod/redaction/view.php', [
            'id' => $cm->id,
            'page' => 'grading',
            'itemid' => $redirectid
        ]);
        redirect($url, get_string('grade_saved', 'redaction'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Get AI evaluation if available.
$aievaluation = null;
if ($submission && $redaction->ai_enabled) {
    $aievaluation = $DB->get_record('redaction_ai_evaluations', [
        'submissionid' => $submission->id,
        'status' => 'completed'
    ], '*', IGNORE_MULTIPLE);

    if (!$aievaluation) {
        $records = $DB->get_records_sql(
            'SELECT * FROM {redaction_ai_evaluations} WHERE submissionid = ? ORDER BY timecreated DESC',
            [$submission->id],
            0,
            1
        );
        $aievaluation = !empty($records) ? reset($records) : null;
    }
}

// Load JS modules.
$PAGE->requires->js_call_amd('mod_redaction/grading', 'init', [
    'cmid' => $cm->id,
    'submissionid' => $submission ? $submission->id : 0
]);

// Get renderer.
$renderer = $PAGE->get_renderer('mod_redaction');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('grading', 'redaction'));

// Back button.
$homeurl = new moodle_url('/mod/redaction/view.php', ['id' => $cm->id]);
echo html_writer::link($homeurl, '← ' . get_string('back_to_home', 'redaction'), ['class' => 'btn btn-secondary mb-3']);

// Render teacher dashboard with statistics and AI synthesis.
$showDashboard = optional_param('dashboard', 1, PARAM_INT);
if ($showDashboard) {
    $dashboardToggleUrl = new moodle_url('/mod/redaction/view.php', [
        'id' => $cm->id,
        'page' => 'grading',
        'itemid' => $currentid,
        'dashboard' => 0
    ]);
    echo '<div class="mb-3">';
    echo '<a href="' . $dashboardToggleUrl . '" class="btn btn-sm btn-outline-secondary">';
    echo '<i class="fa fa-chevron-up mr-1"></i> ' . get_string('dashboard_hide', 'redaction');
    echo '</a>';
    echo '</div>';
    echo redaction_render_teacher_dashboard($cm, $redaction);
} else {
    $dashboardToggleUrl = new moodle_url('/mod/redaction/view.php', [
        'id' => $cm->id,
        'page' => 'grading',
        'itemid' => $currentid,
        'dashboard' => 1
    ]);
    echo '<div class="mb-3">';
    echo '<a href="' . $dashboardToggleUrl . '" class="btn btn-sm btn-outline-primary">';
    echo '<i class="fa fa-chart-bar mr-1"></i> ' . get_string('dashboard_show', 'redaction');
    echo '</a>';
    echo '</div>';
}

// Build navigation data.
$navdata = [
    'hasprev' => ($previd !== null),
    'prevurl' => ($previd !== null) ? (new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'grading', 'itemid' => $previd]))->out(false) : '',
    'hasnext' => ($nextid !== null),
    'nexturl' => ($nextid !== null) ? (new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'grading', 'itemid' => $nextid]))->out(false) : '',
    'currentpos' => ($currentpos !== false ? $currentpos + 1 : 0),
    'totalitems' => count($navitems),
    'navitems' => [],
];
foreach ($navitems as $item) {
    $navdata['navitems'][] = [
        'url' => (new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'grading', 'itemid' => $item['id']]))->out(false),
        'name' => $item['name'],
        'selected' => ($item['id'] == $currentid),
    ];
}

echo '<div class="grading-container">';

// Render navigation.
echo $renderer->render_grading_navigation($navdata);

if (empty($navitems)) {
    echo '<div class="alert alert-warning">' . get_string('no_submission', 'redaction') . '</div>';
} else {
    echo '<div class="submission-panel">';

    // Build submission data.
    $hascontent = ($submission && !empty($submission->contenu));
    $subdata = [
        'hassubmission' => !empty($submission),
        'hascontent' => $hascontent,
        'issubmitted' => ($submission && $submission->status == 1),
        'isdraft' => ($submission && $submission->status == 0),
        'submissionid' => $submission ? $submission->id : 0,
        'titre' => $submission && !empty($submission->titre) ? s($submission->titre) : '',
        'contenu' => $hascontent ? format_text($submission->contenu, $submission->contenuformat ?? FORMAT_HTML) : '',
        'timesubmitted' => ($submission && $submission->timesubmitted) ? userdate($submission->timesubmitted) : '',
        'timemodified' => ($submission && $submission->timemodified) ? userdate($submission->timemodified) : '',
        'wordcount' => 0,
        'charcount' => 0,
        'cangrade' => has_capability('mod/redaction:grade', $context),
        'canviewhistory' => has_capability('mod/redaction:viewhistory', $context),
    ];

    if ($hascontent) {
        $plaintext = strip_tags($submission->contenu);
        $subdata['wordcount'] = str_word_count($plaintext);
        $subdata['charcount'] = function_exists('mb_strlen') ? mb_strlen($plaintext) : strlen($plaintext);
    }

    echo $renderer->render_submission_panel($subdata);

    // Grading sidebar.
    echo '<div class="grading-sidebar">';

    // AI evaluation data.
    if ($redaction->ai_enabled && $submission) {
        $aidata = [
            'ai_enabled' => true,
            'hassubmission' => true,
            'hasevaluation' => !empty($aievaluation),
            'ispending' => ($aievaluation && in_array($aievaluation->status, ['pending', 'processing'])),
            'isfailed' => ($aievaluation && $aievaluation->status === 'failed'),
            'iscompleted' => ($aievaluation && in_array($aievaluation->status, ['completed', 'applied'])),
            'submissionid' => $submission->id,
            'evaluationid' => $aievaluation ? $aievaluation->id : 0,
            'grade' => $aievaluation ? number_format($aievaluation->parsed_grade, 1) : '',
            'error_message' => ($aievaluation && !empty($aievaluation->error_message)) ? s($aievaluation->error_message) : '',
            'criteria' => [],
            'hascriteria' => false,
            'parsed_feedback' => '',
            'hasfeedback' => false,
        ];

        if ($aievaluation && in_array($aievaluation->status, ['completed', 'applied'])) {
            // Parse criteria.
            $criteria = [];
            if (!empty($aievaluation->criteria_json)) {
                $criteria = json_decode($aievaluation->criteria_json, true);
                if (!is_array($criteria)) {
                    $criteria = [];
                }
            }

            if (!empty($criteria)) {
                $aidata['hascriteria'] = true;
                foreach ($criteria as $criterion) {
                    $score = isset($criterion['score']) ? (float)$criterion['score'] : 0;
                    $max = isset($criterion['max']) ? (float)$criterion['max'] : 5;
                    $percentage = $max > 0 ? ($score / $max) * 100 : 0;
                    $scoreClass = $percentage >= 70 ? 'good' : ($percentage >= 50 ? 'medium' : 'low');

                    $aidata['criteria'][] = [
                        'name' => s($criterion['name'] ?? 'Critère'),
                        'score' => number_format($score, 1),
                        'max' => number_format($max, 0),
                        'percentage' => $percentage,
                        'scoreclass' => $scoreClass,
                        'comment' => !empty($criterion['comment']) ? nl2br(s($criterion['comment'])) : '',
                        'hascomment' => !empty($criterion['comment']),
                    ];
                }
            }

            if (!empty($aievaluation->parsed_feedback)) {
                $aidata['hasfeedback'] = true;
                $aidata['parsed_feedback'] = nl2br(s($aievaluation->parsed_feedback));
            }
        }

        echo $renderer->render_ai_evaluation($aidata);
    }

    // Grading form data.
    $formdata = [
        'sesskey' => sesskey(),
        'currentgrade' => $submission ? ($submission->grade ?? '') : '',
        'currentfeedback' => s($submission->feedback ?? ''),
    ];
    echo $renderer->render_grading_form($formdata);

    echo '</div>'; // .grading-sidebar
    echo '</div>'; // .submission-panel
}

echo '</div>'; // .grading-container

// History modal.
echo $renderer->render_history_modal([]);

// Pass strings and configuration to the grading module.
$PAGE->requires->js_call_amd('mod_redaction/grading_actions', 'init', [
    'cmid' => $cm->id,
    'sesskey' => sesskey(),
    'wwwroot' => $CFG->wwwroot,
    'strings' => [
        'evaluating' => get_string('js:evaluating', 'redaction'),
        'evaluate_with_ai' => get_string('js:evaluate_with_ai', 'redaction'),
        'words' => get_string('js:words', 'redaction'),
        'characters' => get_string('js:characters', 'redaction'),
        'no_history' => get_string('js:no_history', 'redaction'),
        'loading_error' => get_string('js:loading_error', 'redaction'),
        'connection_error' => get_string('js:connection_error', 'redaction'),
        'unlock_confirm' => get_string('unlock_confirm', 'redaction'),
    ],
]);

echo $OUTPUT->footer();
