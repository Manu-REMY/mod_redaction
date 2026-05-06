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
            'itemid' => $redirectid,
            'dashboard' => $showDashboard
        ]);
        redirect($url, get_string('grade_saved', 'redaction'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Get all AI evaluations for this submission (chronological, newest first).
$aievaluation = null;
$aievaluations = [];
if ($submission && $redaction->ai_enabled) {
    $evalrecords = $DB->get_records_sql(
        'SELECT * FROM {redaction_ai_evaluations}
         WHERE submissionid = ?
           AND status IN (\'completed\', \'applied\', \'failed\', \'pending\', \'processing\')
         ORDER BY timecreated DESC, id DESC',
        [$submission->id]
    );
    $first = true;
    foreach ($evalrecords as $evalrecord) {
        $evalrecord->is_latest = $first;
        $aievaluations[] = $evalrecord;
        if ($first) {
            $aievaluation = $evalrecord; // Keep for backward-compat usage below.
        }
        $first = false;
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
    'prevurl' => ($previd !== null) ? (new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'grading', 'itemid' => $previd, 'dashboard' => $showDashboard]))->out(false) : '',
    'hasnext' => ($nextid !== null),
    'nexturl' => ($nextid !== null) ? (new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'grading', 'itemid' => $nextid, 'dashboard' => $showDashboard]))->out(false) : '',
    'currentpos' => ($currentpos !== false ? $currentpos + 1 : 0),
    'totalitems' => count($navitems),
    'navitems' => [],
];
foreach ($navitems as $item) {
    $navdata['navitems'][] = [
        'url' => (new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'grading', 'itemid' => $item['id'], 'dashboard' => $showDashboard]))->out(false),
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

    // Add training info if training mode is enabled.
    $subdata['hastraining'] = false;
    if ($submission && !empty($redaction->training_enabled)) {
        $trainingcount = (int)($submission->training_count ?? 0);
        $subdata['hastraining'] = true;
        $subdata['trainingcount'] = $trainingcount;
    }

    // Training timeline panel (above submission info).
    if ($submission && !empty($redaction->training_enabled)) {
        $trainingevals = redaction_get_training_evaluations($submission->id);

        // Fetch correction for timeline bounds.
        if (!isset($correction) || $correction === false) {
            $correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
        }

        // Determine timeline bounds.
        $timelinestart = ($correction && !empty($correction->submission_date))
            ? $correction->submission_date
            : $redaction->timecreated;
        $timelineend = ($correction && !empty($correction->deadline_date))
            ? $correction->deadline_date
            : time();
        $effectiveend = max($timelineend, time());
        $timespan = max($effectiveend - $timelinestart, 1); // Avoid division by zero.

        // Build attempt data array (need ASC order for timeline, evals come DESC).
        $evalsasc = array_reverse(array_values($trainingevals));
        $attemptdata = [];
        $attemptnum = 0;

        foreach ($evalsasc as $teval) {
            $attemptnum++;
            $positionpercent = (($teval->timecreated - $timelinestart) / $timespan) * 100;
            $positionpercent = max(2, min(98, $positionpercent)); // Clamp to avoid edge overflow.

            $gradelevel = 'pending';
            $gradestr = '-';
            $grade = null;
            $criteria = [];
            $shortfeedback = '';

            if ($teval->status === 'completed' && $teval->parsed_grade !== null) {
                $grade = (float)$teval->parsed_grade;
                $gradestr = number_format($grade, 1) . '/20';
                $gradelevel = \mod_redaction\ai_response_parser::get_grade_level($grade);

                // Parse criteria for the detail panel.
                if (!empty($teval->criteria_json)) {
                    $criteriajson = json_decode($teval->criteria_json, true);
                    if (is_array($criteriajson)) {
                        foreach ($criteriajson as $c) {
                            $cscore = isset($c['score']) ? (float)$c['score'] : 0;
                            $cmax = isset($c['max']) ? (float)$c['max'] : 5;
                            $cpct = $cmax > 0 ? ($cscore / $cmax) * 100 : 0;
                            $criteria[] = [
                                'name' => s($c['name'] ?? ''),
                                'score' => number_format($cscore, 1),
                                'max' => number_format($cmax, 0),
                                'percentage' => round($cpct),
                                'scoreclass' => \mod_redaction\ai_response_parser::calculate_level($cpct),
                            ];
                        }
                    }
                }

                if (!empty($teval->parsed_feedback)) {
                    $shortfeedback = shorten_text(strip_tags($teval->parsed_feedback), 150);
                }
            } elseif ($teval->status === 'failed') {
                $gradelevel = 'failed';
                $gradestr = get_string('ai_evaluation_failed', 'redaction');
            } elseif (in_array($teval->status, ['pending', 'processing'])) {
                $gradelevel = 'pending';
                $gradestr = get_string('ai_evaluation_pending', 'redaction');
            }

            $attemptdata[] = [
                'index' => $attemptnum - 1,
                'num' => $attemptnum,
                'timecreated' => $teval->timecreated,
                'datestr' => userdate($teval->timecreated),
                'dateshort' => userdate($teval->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                'grade' => $grade,
                'gradestr' => $gradestr,
                'gradelevel' => $gradelevel,
                'positionpercent' => round($positionpercent, 2),
                'status' => $teval->status,
                'criteria' => $criteria,
                'hascriteria' => !empty($criteria),
                'shortfeedback' => $shortfeedback,
            ];
        }

        // Final submission marker.
        $hasfinal = ($submission->status == 1 && !empty($submission->timesubmitted));
        $finalpositionpercent = 0;
        if ($hasfinal) {
            $finalpositionpercent = (($submission->timesubmitted - $timelinestart) / $timespan) * 100;
            $finalpositionpercent = max(2, min(98, $finalpositionpercent));
        }

        // Elapsed time percentage.
        $elapsedpercent = ((time() - $timelinestart) / $timespan) * 100;
        $elapsedpercent = max(0, min(100, $elapsedpercent));

        // Timeline template data.
        $timelinedata = [
            'hasattempts' => !empty($attemptdata),
            'attemptcount' => count($attemptdata),
            'attempts' => $attemptdata,
            'timelinestartstr' => userdate($timelinestart, '%d %b'),
            'timelineendstr' => userdate($effectiveend, '%d %b'),
            'elapsedpercent' => round($elapsedpercent, 1),
            'hasfinalsubmission' => $hasfinal,
            'finalpositionpercent' => round($finalpositionpercent, 2),
            'finaldate' => $hasfinal ? userdate($submission->timesubmitted) : '',
            'hasdeadline' => ($correction && !empty($correction->deadline_date)),
            'deadlinedatestr' => ($correction && !empty($correction->deadline_date))
                ? userdate($correction->deadline_date) : '',
        ];

        echo $renderer->render_training_timeline($timelinedata);

        // Pass attempt data to JS for interactive timeline.
        if (!empty($attemptdata)) {
            $PAGE->requires->js_call_amd('mod_redaction/training_timeline', 'init', [[
                'attempts' => $attemptdata,
                'strings' => [
                    'attempt' => get_string('training_timeline_attempt', 'redaction'),
                ],
            ]]);
        }
    }

    echo $renderer->render_submission_panel($subdata);

    // Grading sidebar.
    echo '<div class="grading-sidebar">';

    // AI evaluation data — build a list of all evaluations (newest first).
    if ($redaction->ai_enabled && $submission) {
        $levelstrmap = [
            'excellent' => get_string('level_excellent', 'redaction'),
            'good' => get_string('level_good', 'redaction'),
            'medium' => get_string('level_medium', 'redaction'),
            'low' => get_string('level_low', 'redaction'),
        ];

        // Build one entry per evaluation for the template loop.
        $evallist = [];
        $attemptnum = count($aievaluations);
        foreach ($aievaluations as $evalitem) {
            $entry = [
                'is_latest' => (bool)$evalitem->is_latest,
                'attemptnum' => $attemptnum,
                'dateformatted' => userdate($evalitem->timecreated),
                'evaluationid' => $evalitem->id,
                'submissionid' => $submission->id,
                'ispending' => in_array($evalitem->status, ['pending', 'processing']),
                'isfailed' => ($evalitem->status === 'failed'),
                'iscompleted' => in_array($evalitem->status, ['completed', 'applied']),
                'grade' => '',
                'gradelevel' => '',
                'gradelevelstr' => '',
                'confidencepercent' => 0,
                'confidenceclass' => '',
                'error_message' => !empty($evalitem->error_message) ? s($evalitem->error_message) : '',
                'criteria' => [],
                'hascriteria' => false,
                'parsed_feedback' => '',
                'hasfeedback' => false,
                'overall_appreciation' => '',
                'hasappreciation' => false,
                'strengths' => [],
                'hasstrengths' => false,
                'weaknesses' => [],
                'hasweaknesses' => false,
                'keywords_found' => [],
                'haskeywordsfound' => false,
                'keywords_missing' => [],
                'haskeywordsmissing' => false,
                'haskeywords' => false,
                'suggestions' => [],
                'hassuggestions' => false,
            ];

            if (in_array($evalitem->status, ['completed', 'applied'])) {
                // Grade.
                $entry['grade'] = number_format((float)$evalitem->parsed_grade, 1);

                // Grade level.
                $gradelevel = \mod_redaction\ai_response_parser::get_grade_level((float)$evalitem->parsed_grade);
                $entry['gradelevel'] = $gradelevel;
                $entry['gradelevelstr'] = $levelstrmap[$gradelevel] ?? '';

                // Confidence from raw response.
                $rawresponse = null;
                if (!empty($evalitem->raw_response)) {
                    $rawjson = json_decode($evalitem->raw_response, true);
                    if (is_array($rawjson)) {
                        $rawresponse = $rawjson;
                    } else {
                        if (preg_match('/\{[\s\S]*\}/', $evalitem->raw_response, $matches)) {
                            $rawresponse = json_decode($matches[0], true);
                        }
                    }
                }
                $confidence = isset($rawresponse['confidence']) ? (float)$rawresponse['confidence'] : 0.8;
                $confidence = max(0.0, min(1.0, $confidence));
                $confidencepercent = round($confidence * 100);
                $entry['confidencepercent'] = $confidencepercent;
                $entry['confidenceclass'] = $confidencepercent >= 80 ? 'good' : ($confidencepercent >= 60 ? 'medium' : 'low');

                // Criteria.
                $criteria = [];
                if (!empty($evalitem->criteria_json)) {
                    $criteria = json_decode($evalitem->criteria_json, true);
                    if (!is_array($criteria)) {
                        $criteria = [];
                    }
                }
                if (!empty($criteria)) {
                    $entry['hascriteria'] = true;
                    foreach ($criteria as $criterion) {
                        $score = isset($criterion['score']) ? (float)$criterion['score'] : 0;
                        $max = isset($criterion['max']) ? (float)$criterion['max'] : 5;
                        $percentage = $max > 0 ? ($score / $max) * 100 : 0;
                        $scoreClass = \mod_redaction\ai_response_parser::calculate_level($percentage);
                        $entry['criteria'][] = [
                            'name' => s($criterion['name'] ?? get_string('ai_criterion_default', 'mod_redaction')),
                            'score' => number_format($score, 1),
                            'max' => number_format($max, 0),
                            'percentage' => $percentage,
                            'scoreclass' => $scoreClass,
                            'comment' => !empty($criterion['comment']) ? nl2br(s($criterion['comment'])) : '',
                            'hascomment' => !empty($criterion['comment']),
                            'levelstr' => $levelstrmap[$scoreClass] ?? '',
                        ];
                    }
                }

                // Extended fields from raw response.
                if ($rawresponse) {
                    if (!empty($rawresponse['strengths']) && is_array($rawresponse['strengths'])) {
                        $entry['hasstrengths'] = true;
                        foreach ($rawresponse['strengths'] as $strength) {
                            $entry['strengths'][] = ['text' => s(trim($strength))];
                        }
                    }
                    if (!empty($rawresponse['weaknesses']) && is_array($rawresponse['weaknesses'])) {
                        $entry['hasweaknesses'] = true;
                        foreach ($rawresponse['weaknesses'] as $weakness) {
                            $entry['weaknesses'][] = ['text' => s(trim($weakness))];
                        }
                    }
                    if (!empty($rawresponse['keywords_found']) && is_array($rawresponse['keywords_found'])) {
                        $entry['haskeywordsfound'] = true;
                        foreach ($rawresponse['keywords_found'] as $kw) {
                            $entry['keywords_found'][] = ['word' => s(trim($kw))];
                        }
                    }
                    if (!empty($rawresponse['keywords_missing']) && is_array($rawresponse['keywords_missing'])) {
                        $entry['haskeywordsmissing'] = true;
                        foreach ($rawresponse['keywords_missing'] as $kw) {
                            $entry['keywords_missing'][] = ['word' => s(trim($kw))];
                        }
                    }
                    $entry['haskeywords'] = $entry['haskeywordsfound'] || $entry['haskeywordsmissing'];
                    if (!empty($rawresponse['suggestions']) && is_array($rawresponse['suggestions'])) {
                        $entry['hassuggestions'] = true;
                        foreach ($rawresponse['suggestions'] as $suggestion) {
                            $entry['suggestions'][] = ['text' => s(trim($suggestion))];
                        }
                    }
                    if (!empty($rawresponse['overall_appreciation'])) {
                        $entry['hasappreciation'] = true;
                        $entry['overall_appreciation'] = nl2br(s(trim($rawresponse['overall_appreciation'])));
                    }
                }

                if (!empty($evalitem->parsed_feedback)) {
                    $entry['hasfeedback'] = true;
                    $entry['parsed_feedback'] = nl2br(s($evalitem->parsed_feedback));
                }
            }

            $evallist[] = $entry;
            $attemptnum--;
        }

        $aidata = [
            'ai_enabled' => true,
            'hassubmission' => true,
            'hasevaluation' => !empty($evallist),
            'submissionid' => $submission->id,
            'evaluationid' => $aievaluation ? $aievaluation->id : 0,
            'evaluations' => $evallist,
            'hasevaluations' => !empty($evallist),
            'totalevaluations' => count($evallist),
        ];

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
