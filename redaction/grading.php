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
        // Capture old grade before update for event logging.
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

        // Redirect to next item or stay on current.
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

    // Also check for pending/processing.
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

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('grading', 'redaction'));

// Back button.
$homeurl = new moodle_url('/mod/redaction/view.php', ['id' => $cm->id]);
echo html_writer::link($homeurl, '← ' . get_string('back_to_home', 'redaction'), ['class' => 'btn btn-secondary mb-3']);

// Render teacher dashboard with statistics and AI synthesis.
$showDashboard = optional_param('dashboard', 1, PARAM_INT);
if ($showDashboard) {
    // Toggle button for dashboard visibility.
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

    // Render the dashboard.
    echo redaction_render_teacher_dashboard($cm, $redaction);
} else {
    // Show button to display dashboard.
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

?>

<div class="grading-container">
    <!-- Navigation Bar -->
    <div class="navigation-bar">
        <div class="nav-buttons">
            <?php if ($previd !== null): ?>
                <a href="<?php echo new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'grading', 'itemid' => $previd]); ?>" class="nav-btn">
                    ← <?php echo get_string('previous', 'moodle'); ?>
                </a>
            <?php else: ?>
                <span class="nav-btn disabled">← <?php echo get_string('previous', 'moodle'); ?></span>
            <?php endif; ?>

            <span class="nav-counter">
                <?php echo ($currentpos !== false ? $currentpos + 1 : 0) . ' / ' . count($navitems); ?>
            </span>

            <?php if ($nextid !== null): ?>
                <a href="<?php echo new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'grading', 'itemid' => $nextid]); ?>" class="nav-btn">
                    <?php echo get_string('next', 'moodle'); ?> →
                </a>
            <?php else: ?>
                <span class="nav-btn disabled"><?php echo get_string('next', 'moodle'); ?> →</span>
            <?php endif; ?>
        </div>

        <select class="item-selector" onchange="location.href=this.value">
            <?php foreach ($navitems as $item): ?>
                <option value="<?php echo new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'grading', 'itemid' => $item['id']]); ?>"
                        <?php echo ($item['id'] == $currentid) ? 'selected' : ''; ?>>
                    <?php echo s($item['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if (empty($navitems)): ?>
        <div class="alert alert-warning">
            <?php echo get_string('no_submission', 'redaction'); ?>
        </div>
    <?php else: ?>
        <div class="submission-panel">
            <!-- Left: Submission Content -->
            <div class="submission-content">
                <?php if (!$submission || empty($submission->contenu)): ?>
                    <div class="status-bar no-submission">
                        ❌ <?php echo get_string('no_submission', 'redaction'); ?>
                    </div>
                <?php elseif ($submission->status == 1): ?>
                    <div class="status-bar submitted">
                        ✅ <?php echo get_string('status_submitted', 'redaction'); ?>
                        <span style="margin-left: auto; font-size: 13px;">
                            <?php echo get_string('submitted_on', 'redaction', userdate($submission->timesubmitted)); ?>
                        </span>
                        <?php if (has_capability('mod/redaction:grade', $context)): ?>
                            <button type="button" class="unlock-btn" onclick="unlockSubmission(<?php echo $submission->id; ?>)">
                                🔓 <?php echo get_string('unlock_submission', 'redaction'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="status-bar draft">
                        📝 <?php echo get_string('status_draft', 'redaction'); ?>
                        <span style="margin-left: auto; font-size: 13px;">
                            <?php echo get_string('lastmodified', 'redaction') . ': ' . userdate($submission->timemodified); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($submission && !empty($submission->contenu)): ?>
                    <?php if (!empty($submission->titre)): ?>
                        <div class="content-title"><?php echo s($submission->titre); ?></div>
                    <?php endif; ?>

                    <div class="content-display">
                        <?php echo format_text($submission->contenu, $submission->contenuformat ?? FORMAT_HTML); ?>
                    </div>

                    <?php
                    $plaintext = strip_tags($submission->contenu);
                    $wordcount = str_word_count($plaintext);
                    $charcount = function_exists('mb_strlen') ? mb_strlen($plaintext) : strlen($plaintext);
                    ?>
                    <div class="word-count">
                        <?php echo get_string('word_count', 'redaction', $wordcount); ?> |
                        <?php echo get_string('char_count', 'redaction', $charcount); ?>
                        <?php if (has_capability('mod/redaction:viewhistory', $context)): ?>
                            | <a href="#" class="history-link" onclick="showHistory(<?php echo $submission->id; ?>); return false;">
                                📜 <?php echo get_string('version_history', 'redaction'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right: Grading Sidebar -->
            <div class="grading-sidebar">
                <?php if ($redaction->ai_enabled && $submission): ?>
                    <!-- AI Evaluation Section -->
                    <div class="ai-evaluation-container">
                        <h4 style="margin-bottom: 12px; font-size: 16px;">🤖 <?php echo get_string('ai_evaluation', 'redaction'); ?></h4>

                        <?php if (!$aievaluation): ?>
                            <p style="margin-bottom: 12px; font-size: 13px; color: #666;">
                                <?php echo get_string('no_ai_evaluation', 'redaction'); ?>
                            </p>
                            <button type="button" class="btn-ai btn-ai-trigger" onclick="triggerAIEvaluation(<?php echo $submission->id; ?>)">
                                🚀 <?php echo get_string('evaluate_ai', 'redaction'); ?>
                            </button>

                        <?php elseif ($aievaluation->status === 'pending' || $aievaluation->status === 'processing'): ?>
                            <div class="ai-pending">
                                <div class="spinner"></div>
                                <span><?php echo get_string('ai_evaluation_pending', 'redaction'); ?></span>
                            </div>

                        <?php elseif ($aievaluation->status === 'failed'): ?>
                            <div class="alert alert-danger" style="margin-bottom: 15px;">
                                <?php echo get_string('ai_evaluation_failed', 'redaction'); ?>
                                <?php if (!empty($aievaluation->error_message)): ?>
                                    <br><small><?php echo s($aievaluation->error_message); ?></small>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn-ai btn-ai-trigger" onclick="triggerAIEvaluation(<?php echo $submission->id; ?>)">
                                🔄 <?php echo get_string('retry', 'moodle'); ?>
                            </button>

                        <?php elseif ($aievaluation->status === 'completed' || $aievaluation->status === 'applied'): ?>
                            <div class="ai-grade-card">
                                <div class="ai-grade-value"><?php echo number_format($aievaluation->parsed_grade, 1); ?>/20</div>
                                <div class="ai-grade-label"><?php echo get_string('ai_grade', 'redaction'); ?></div>
                            </div>

                            <?php
                            // Parse criteria from JSON.
                            $criteria = [];
                            if (!empty($aievaluation->criteria_json)) {
                                $criteria = json_decode($aievaluation->criteria_json, true);
                                if (!is_array($criteria)) {
                                    $criteria = [];
                                }
                            }
                            ?>

                            <?php if (!empty($criteria)): ?>
                                <div class="ai-criteria-section">
                                    <div class="ai-section-toggle" onclick="toggleSection(this)">
                                        <h5 style="margin: 0;">📊 <?php echo get_string('ai_criteria_details', 'redaction'); ?></h5>
                                        <span class="toggle-icon">▼</span>
                                    </div>
                                    <div class="ai-section-content">
                                        <?php foreach ($criteria as $criterion): ?>
                                            <?php
                                            $score = isset($criterion['score']) ? (float)$criterion['score'] : 0;
                                            $max = isset($criterion['max']) ? (float)$criterion['max'] : 5;
                                            $percentage = $max > 0 ? ($score / $max) * 100 : 0;
                                            $scoreClass = $percentage >= 70 ? 'good' : ($percentage >= 50 ? 'medium' : 'low');
                                            ?>
                                            <div class="ai-criterion">
                                                <div class="ai-criterion-header">
                                                    <span class="ai-criterion-name"><?php echo s($criterion['name'] ?? 'Critère'); ?></span>
                                                    <span class="ai-criterion-score <?php echo $scoreClass; ?>">
                                                        <?php echo number_format($score, 1); ?>/<?php echo number_format($max, 0); ?>
                                                    </span>
                                                </div>
                                                <div class="ai-criterion-progress">
                                                    <div class="ai-criterion-progress-bar <?php echo $scoreClass; ?>"
                                                         style="width: <?php echo $percentage; ?>%"></div>
                                                </div>
                                                <?php if (!empty($criterion['comment'])): ?>
                                                    <div class="ai-criterion-comment">
                                                        <?php echo nl2br(s($criterion['comment'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($aievaluation->parsed_feedback)): ?>
                                <div class="ai-criteria-section">
                                    <div class="ai-section-toggle" onclick="toggleSection(this)">
                                        <h5 style="margin: 0;">💬 <?php echo get_string('ai_general_feedback', 'redaction'); ?></h5>
                                        <span class="toggle-icon">▼</span>
                                    </div>
                                    <div class="ai-section-content">
                                        <div class="ai-feedback" style="margin-bottom: 0;">
                                            <?php echo nl2br(s($aievaluation->parsed_feedback)); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="ai-actions">
                                <button type="button" class="btn-ai btn-ai-apply" onclick="applyAIGrade(<?php echo $aievaluation->id; ?>)">
                                    ✅ <?php echo get_string('apply_ai_grade', 'redaction'); ?>
                                </button>
                                <button type="button" class="btn-ai btn-ai-trigger" onclick="triggerAIEvaluation(<?php echo $submission->id; ?>)">
                                    🔄 <?php echo get_string('reevaluate', 'redaction'); ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Manual Grading Form -->
                <div class="grading-form-container">
                    <h4 style="margin-bottom: 15px; font-size: 16px;">📝 <?php echo get_string('grade', 'redaction'); ?></h4>

                    <form method="post" action="">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

                        <div class="form-group">
                            <label for="grade"><?php echo get_string('grade_outof', 'redaction', 20); ?></label>
                            <input type="number"
                                   id="grade"
                                   name="grade"
                                   class="form-control grade-input"
                                   min="0"
                                   max="20"
                                   step="0.5"
                                   value="<?php echo $submission ? ($submission->grade ?? '') : ''; ?>"
                                   placeholder="0 - 20">
                        </div>

                        <div class="form-group">
                            <label for="feedback"><?php echo get_string('feedback', 'redaction'); ?></label>
                            <textarea id="feedback"
                                      name="feedback"
                                      class="form-control"
                                      rows="6"
                                      placeholder="<?php echo get_string('feedback_placeholder', 'redaction'); ?>"><?php echo s($submission->feedback ?? ''); ?></textarea>
                        </div>

                        <button type="submit" class="btn-save">
                            💾 <?php echo get_string('save_grade', 'redaction'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- History Modal -->
<div id="history-modal" class="modal fade" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo get_string('version_history', 'redaction'); ?></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="history-content">
                <!-- Loaded via AJAX -->
            </div>
        </div>
    </div>
</div>

<?php
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
?>

<?php
echo $OUTPUT->footer();
