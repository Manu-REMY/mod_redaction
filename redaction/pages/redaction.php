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
 * Student writing page for redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

// Define constant if not exists.
if (!defined('EDITOR_UNLIMITED_FILES')) {
    define('EDITOR_UNLIMITED_FILES', -1);
}

// Get consignes.
$consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
if (!$consignes || !$consignes->locked) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('consignes_not_ready', 'redaction'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

// Get user's group.
$usergroup = redaction_get_user_group($cm, $USER->id);

// Check group requirement.
if ($redaction->group_submission && $usergroup == 0) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('group_required', 'redaction'), 'error');
    echo $OUTPUT->footer();
    exit;
}

// Get or create submission.
$submission = redaction_get_or_create_submission($redaction, $usergroup, $USER->id);
$issubmitted = ($submission->status == 1);
$isgraded = ($submission->grade !== null);

// Get AI evaluation if available and graded.
$aievaluation = null;
if ($isgraded && $redaction->ai_enabled && $submission) {
    $records = $DB->get_records_sql(
        'SELECT * FROM {redaction_ai_evaluations}
         WHERE submissionid = ? AND is_training = 0 AND (status = ? OR status = ?)
         ORDER BY timecreated DESC',
        [$submission->id, 'completed', 'applied'],
        0,
        1
    );
    $aievaluation = !empty($records) ? reset($records) : null;
}

// Training mode data.
$trainingenabled = !empty($redaction->training_enabled) && !empty($redaction->ai_enabled);
$correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
$cantraining = ['allowed' => false, 'reason' => ''];
$trainingevals = [];
$maxeffective = $trainingenabled ? redaction_effective_max_attempts($redaction) : 0;
$attemptsused = (int) ($submission->training_count ?? 0);
$attemptsremaining = max(0, $maxeffective - $attemptsused);
$islastattempt = $trainingenabled && $attemptsremaining === 1; // The next click will be the last.
if ($trainingenabled && $submission) {
    $cantraining = redaction_can_submit_attempt($redaction, $submission, $correction);
    $trainingevals = redaction_get_training_evaluations($submission->id);
}

// Editor options for rich text.
$editoroptions = [
    'maxfiles' => EDITOR_UNLIMITED_FILES,
    'noclean' => true,
    'context' => $context,
    'subdirs' => true,
    'maxbytes' => $CFG->maxbytes,
    'changeformat' => 0,
    'trusttext' => false,
];

// Prepare editor for contenu field.
$submission = file_prepare_standard_editor(
    $submission,
    'contenu',
    $editoroptions,
    $context,
    'mod_redaction',
    'contenu',
    $submission->id
);

// Handle form submission (manual save).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$issubmitted) {
    require_sesskey();

    $action = optional_param('action', 'save', PARAM_ALPHA);

    if ($action === 'save') {
        // Get form data.
        $submission->titre = optional_param('titre', '', PARAM_TEXT);

        // Handle editor content.
        $editordata = optional_param_array('contenu_editor', [], PARAM_RAW);
        $submission->contenu_editor = [
            'text' => $editordata['text'] ?? '',
            'format' => isset($editordata['format']) ? (int)$editordata['format'] : FORMAT_HTML,
            'itemid' => isset($editordata['itemid']) ? (int)$editordata['itemid'] : 0,
        ];

        // Save editor content.
        $submission = file_postupdate_standard_editor(
            $submission,
            'contenu',
            $editoroptions,
            $context,
            'mod_redaction',
            'contenu',
            $submission->id
        );

        $submission->timemodified = time();
        $DB->update_record('redaction_submission', $submission);

        // Save to history.
        redaction_save_history($submission, $USER->id);

        // Reload with fresh editor data.
        $submission = $DB->get_record('redaction_submission', ['id' => $submission->id]);
        $submission = file_prepare_standard_editor(
            $submission,
            'contenu',
            $editoroptions,
            $context,
            'mod_redaction',
            'contenu',
            $submission->id
        );

        \core\notification::success(get_string('changessaved', 'moodle'));
    }

    if ($action === 'submit') {
        // Get form data first.
        $submission->titre = optional_param('titre', '', PARAM_TEXT);

        // Handle editor content.
        $editordata = optional_param_array('contenu_editor', [], PARAM_RAW);
        $submission->contenu_editor = [
            'text' => $editordata['text'] ?? '',
            'format' => isset($editordata['format']) ? (int)$editordata['format'] : FORMAT_HTML,
            'itemid' => isset($editordata['itemid']) ? (int)$editordata['itemid'] : 0,
        ];

        // Save editor content.
        $submission = file_postupdate_standard_editor(
            $submission,
            'contenu',
            $editoroptions,
            $context,
            'mod_redaction',
            'contenu',
            $submission->id
        );

        // In training mode, increment counter and auto-finalize on last attempt.
        if ($trainingenabled) {
            $check = redaction_can_submit_attempt($redaction, $submission, $correction);
            if (!$check['allowed']) {
                redirect(
                    new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'redaction']),
                    get_string('training_error_' . $check['reason'], 'redaction'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }

            $submission->training_count = $attemptsused + 1;
            $submission->timemodified = time();

            $maxeff = redaction_effective_max_attempts($redaction);
            $islast = ($submission->training_count >= $maxeff);
            if ($islast) {
                $submission->status = 1;
                $submission->timesubmitted = time();
            }
        } else {
            // Classic mode — single shot, immediate lock.
            $submission->status = 1;
            $submission->timesubmitted = time();
            $submission->timemodified = time();
        }

        $DB->update_record('redaction_submission', $submission);

        // Save to history.
        redaction_save_history($submission, $USER->id);

        // Trigger AI evaluation if enabled.
        if ($redaction->ai_enabled) {
            \mod_redaction\ai_evaluator::queue_evaluation(
                $redaction->id,
                $submission->id,
                $submission->groupid,
                $submission->userid
            );
        }

        redirect(
            new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'redaction']),
            get_string('status_submitted', 'redaction'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// Page setup.
$PAGE->set_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'redaction']);
$PAGE->set_title(format_string($redaction->name) . ' - ' . get_string('redaction', 'redaction'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('redaction', 'redaction'));

// Back button.
$homeurl = new moodle_url('/mod/redaction/view.php', ['id' => $cm->id]);
echo html_writer::link($homeurl, '← ' . get_string('back_to_home', 'redaction'), ['class' => 'btn btn-secondary mb-3']);

// Group info.
if ($redaction->group_submission && $usergroup > 0) {
    $groupinfo = $DB->get_record('groups', ['id' => $usergroup]);
    if ($groupinfo) {
        echo '<div class="alert alert-info mb-3">' . get_string('group_label', 'redaction') .
             ' <strong>' . s($groupinfo->name) . '</strong></div>';
    }
}

// Pre-render editor HTML for template injection.
$editorhtml = '';
if (!$issubmitted) {
    ob_start();
    $editor = editors_get_preferred_editor($submission->contenuformat ?? FORMAT_HTML);
    $editor->set_text($submission->contenu_editor['text'] ?? '');
    $editor->use_editor('contenu_editor_text', $editoroptions, ['context' => $context]);
    echo '<textarea id="contenu_editor_text"
                    name="contenu_editor[text]"
                    rows="20"
                    style="width: 100%;">' . s($submission->contenu_editor['text'] ?? '') . '</textarea>';
    echo '<input type="hidden" name="contenu_editor[format]" value="' . ($submission->contenuformat ?? FORMAT_HTML) . '">';
    echo '<input type="hidden" name="contenu_editor[itemid]" value="' . ($submission->contenu_editor['itemid'] ?? 0) . '">';
    $editorhtml = ob_get_clean();
}

// Helper to build criteria template data from JSON.
$buildcriteriatemplatedata = function(array $rawcriteria): array {
    $result = [];
    foreach ($rawcriteria as $criterion) {
        $score = isset($criterion['score']) ? (float)$criterion['score'] : 0;
        $max = isset($criterion['max']) ? (float)$criterion['max'] : 5;
        $percentage = $max > 0 ? ($score / $max) * 100 : 0;

        if ($percentage >= 80) {
            $levelclass = 'excellent';
            $leveltext = get_string('level_excellent', 'redaction');
        } else if ($percentage >= 65) {
            $levelclass = 'good';
            $leveltext = get_string('level_good', 'redaction');
        } else if ($percentage >= 50) {
            $levelclass = 'medium';
            $leveltext = get_string('level_medium', 'redaction');
        } else {
            $levelclass = 'low';
            $leveltext = get_string('level_low', 'redaction');
        }

        $result[] = [
            'name' => s($criterion['name'] ?? get_string('ai_criterion_default', 'redaction')),
            'scoreformatted' => number_format($score, 1),
            'maxformatted' => number_format($max, 0),
            'percentage' => $percentage,
            'levelclass' => $levelclass,
            'leveltext' => $leveltext,
            'hascomment' => !empty($criterion['comment']),
            'comment' => nl2br(s($criterion['comment'] ?? '')),
        ];
    }
    return $result;
};

// Parse main AI criteria.
$criteriadata = [];
if ($aievaluation && !empty($aievaluation->criteria_json)) {
    $rawcriteria = json_decode($aievaluation->criteria_json, true);
    if (is_array($rawcriteria)) {
        $criteriadata = $buildcriteriatemplatedata($rawcriteria);
    }
}

// Build training evaluations template data.
$trainingevalsdata = [];
if ($trainingenabled) {
    $attemptnum = count($trainingevals);
    foreach ($trainingevals as $eval) {
        $evaldata = [
            'attemptlabel' => get_string('training_attempt', 'redaction', $attemptnum),
            'dateformatted' => userdate($eval->timecreated),
            'hasgrade' => ($eval->status === 'completed' && $eval->parsed_grade !== null),
            'gradeformatted' => ($eval->parsed_grade !== null) ? number_format($eval->parsed_grade, 1) : '',
            'iscompleted' => ($eval->status === 'completed'),
            'ispending' => ($eval->status === 'pending' || $eval->status === 'processing'),
            'isfailed' => ($eval->status === 'failed'),
            'errormessage' => s($eval->error_message ?? get_string('ai_evaluation_failed', 'redaction')),
            'hasevalcriteria' => false,
            'evalcriteria' => [],
            'hasevalfeedback' => !empty($eval->parsed_feedback),
            'evalfeedback' => nl2br(s($eval->parsed_feedback ?? '')),
        ];

        if ($eval->status === 'completed' && !empty($eval->criteria_json)) {
            $evalrawcriteria = json_decode($eval->criteria_json, true);
            if (is_array($evalrawcriteria) && !empty($evalrawcriteria)) {
                $evaldata['hasevalcriteria'] = true;
                $evaldata['evalcriteria'] = $buildcriteriatemplatedata($evalrawcriteria);
            }
        }

        $trainingevalsdata[] = $evaldata;
        $attemptnum--;
    }
}

// Training blocked reason.
$trainingblockedreason = '';
if (!$cantraining['allowed'] && !empty($cantraining['reason'])) {
    $reason = $cantraining['reason'];
    if ($reason === 'cooldown_active' && isset($cantraining['remaining'])) {
        $minutes = ceil($cantraining['remaining'] / 60);
        $trainingblockedreason = get_string('training_error_cooldown_remaining', 'redaction', $minutes);
    } else {
        $trainingblockedreason = get_string('training_error_' . $reason, 'redaction');
    }
}

// Training counter string.
$trainingcounterstr = '';
if ($trainingenabled) {
    $trainingcounterstr = get_string('training_attempt', 'redaction', $submission->training_count ?? 0)
        . ' / '
        . ($redaction->training_max_attempts > 0
            ? $redaction->training_max_attempts
            : get_string('unlimited', 'redaction'));
}

// Compute submit button label.
$attemptbuttonlabel = '';
$attemptconfirm = '';
if ($trainingenabled && !$issubmitted) {
    if ($attemptsused === 0) {
        $attemptbuttonlabel = get_string('attempt_button_first', 'redaction');
    } else if ($attemptsremaining > 1) {
        $attemptbuttonlabel = get_string('attempt_button_remaining', 'redaction',
            (object) ['used' => $attemptsused, 'max' => $maxeffective]);
    } else {
        $attemptbuttonlabel = get_string('attempt_button_last', 'redaction');
        $attemptconfirm = get_string('attempt_last_confirm', 'redaction');
    }
}

// Build template data.
$templatedata = [
    'consignestitre' => s($consignes->titre ?? get_string('consignes', 'redaction')),
    'consignescontent' => format_text($consignes->consignes, $consignes->consignesformat ?? FORMAT_HTML),
    'hascriteres' => !empty($consignes->criteres),
    'criteres' => nl2br(s($consignes->criteres ?? '')),
    'hasdocuments' => !empty($consignes->documents),
    'documents' => nl2br(s($consignes->documents ?? '')),
    'isgraded' => $isgraded,
    'gradeformatted' => $isgraded ? number_format($submission->grade, 1) : '',
    'hascriteriadata' => !empty($criteriadata),
    'criteria' => $criteriadata,
    'hasaifeedback' => ($aievaluation && !empty($aievaluation->parsed_feedback)),
    'aifeedback' => $aievaluation ? nl2br(s($aievaluation->parsed_feedback ?? '')) : '',
    'hasteacherfeedback' => !empty($submission->feedback),
    'teacherfeedback' => !empty($submission->feedback) ? format_text($submission->feedback, $submission->feedbackformat ?? FORMAT_HTML) : '',
    'issubmitted' => $issubmitted,
    'submittedon' => $issubmitted ? get_string('submitted_on', 'redaction', userdate($submission->timesubmitted)) : '',
    'submittedgradedstr' => $isgraded
        ? get_string('submitted_graded', 'redaction', get_string('status_submitted', 'redaction'))
        : '',
    'trainingenabled' => $trainingenabled,
    'cantraining' => $cantraining['allowed'],
    'trainingblockedreason' => $trainingblockedreason,
    'trainingcounterstr' => $trainingcounterstr,
    'hasremainingstr' => ($trainingenabled && $redaction->training_max_attempts > 0),
    'trainingremainingstr' => $trainingenabled && $redaction->training_max_attempts > 0
        ? get_string('training_remaining', 'redaction', max(0, $redaction->training_max_attempts - ($submission->training_count ?? 0)))
        : '',
    'trainingevalcount' => count($trainingevals),
    'trainingevals' => $trainingevalsdata,
    'formurl' => $PAGE->url->out(false),
    'sesskey' => sesskey(),
    'usergroup' => $usergroup,
    'titre' => s($submission->titre ?? ''),
    'editorhtml' => $editorhtml,
    'submittedcontent' => $issubmitted ? format_text($submission->contenu ?? '', $submission->contenuformat ?? FORMAT_HTML) : '',
    'trainingfinalconfirm' => get_string('training_final_confirm', 'redaction'),
    'submitconfirm' => get_string('submit_confirm', 'redaction'),
    'attemptbuttonlabel' => $attemptbuttonlabel,
    'attemptconfirm' => $attemptconfirm,
    'hasattemptconfirm' => !empty($attemptconfirm),
    'attemptsexhaustedstr' => ($trainingenabled && $issubmitted && $attemptsused >= $maxeffective)
        ? get_string('attempts_exhausted', 'redaction', $maxeffective)
        : '',
];

// Render using the Output API.
/** @var \mod_redaction\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_redaction');
echo $renderer->render_redaction($templatedata);

// Initialise the redaction page AMD module.
$jsparams = [
    'cmid' => $cm->id,
    'sesskey' => sesskey(),
    'wwwroot' => $CFG->wwwroot,
    'formurl' => $PAGE->url->out(false),
    'submissionid' => $submission->id,
    'trainingenabled' => $trainingenabled && !$issubmitted,
    'strings' => [
        'ai_request_failed' => get_string('ai_request_failed', 'redaction'),
    ],
];
$PAGE->requires->js_call_amd('mod_redaction/redaction_page', 'init', [$jsparams]);

echo $OUTPUT->footer();
