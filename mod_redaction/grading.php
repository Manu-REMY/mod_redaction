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
        $submission->grade = $grade;
        $submission->feedback = $feedback;
        $submission->timemodified = time();
        $DB->update_record('redaction_submission', $submission);

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
        $aievaluation = $DB->get_record_sql(
            'SELECT * FROM {redaction_ai_evaluations} WHERE submissionid = ? ORDER BY timecreated DESC LIMIT 1',
            [$submission->id]
        );
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

?>

<style>
    .grading-container {
        max-width: 1200px;
        margin: 20px auto;
    }

    .navigation-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: white;
        padding: 15px 20px;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        margin-bottom: 20px;
    }

    .nav-buttons {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .nav-btn {
        padding: 8px 16px;
        border: none;
        border-radius: 8px;
        background: #667eea;
        color: white;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.3s;
    }

    .nav-btn:hover {
        background: #5a6fd6;
        color: white;
        text-decoration: none;
    }

    .nav-btn.disabled {
        background: #ccc;
        cursor: not-allowed;
        pointer-events: none;
    }

    .nav-counter {
        font-weight: 600;
        color: #333;
    }

    .item-selector {
        padding: 8px 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        min-width: 200px;
    }

    .submission-panel {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 20px;
    }

    @media (max-width: 992px) {
        .submission-panel {
            grid-template-columns: 1fr;
        }
    }

    .submission-content {
        background: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .grading-sidebar {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .grading-form-container {
        background: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .ai-evaluation-container {
        background: linear-gradient(135deg, #f0f4ff 0%, #e8f0fe 100%);
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .status-bar {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 12px 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .status-bar.submitted {
        background: #d4edda;
        color: #155724;
    }

    .status-bar.draft {
        background: #fff3cd;
        color: #856404;
    }

    .status-bar.no-submission {
        background: #f8d7da;
        color: #721c24;
    }

    .content-display {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
        margin-top: 15px;
        white-space: pre-wrap;
        line-height: 1.7;
    }

    .content-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 10px;
        color: #333;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        font-weight: 600;
        margin-bottom: 8px;
        display: block;
        color: #333;
    }

    .form-control {
        width: 100%;
        padding: 10px 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 15px;
    }

    .form-control:focus {
        border-color: #667eea;
        outline: none;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .grade-input {
        font-size: 24px;
        text-align: center;
        font-weight: 600;
    }

    .btn-save {
        width: 100%;
        padding: 12px;
        background: #48bb78;
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        font-size: 16px;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-save:hover {
        background: #38a169;
    }

    .ai-grade-card {
        background: white;
        padding: 20px;
        border-radius: 10px;
        text-align: center;
        margin-bottom: 15px;
    }

    .ai-grade-value {
        font-size: 36px;
        font-weight: 700;
        color: #667eea;
    }

    .ai-grade-label {
        font-size: 14px;
        color: #666;
    }

    .ai-feedback {
        background: white;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 15px;
        font-size: 14px;
        line-height: 1.6;
    }

    .ai-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .btn-ai {
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-ai-apply {
        background: #667eea;
        color: white;
    }

    .btn-ai-apply:hover {
        background: #5a6fd6;
    }

    .btn-ai-trigger {
        background: #9f7aea;
        color: white;
    }

    .btn-ai-trigger:hover {
        background: #805ad5;
    }

    .ai-pending {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 15px;
        background: white;
        border-radius: 8px;
    }

    .spinner {
        width: 20px;
        height: 20px;
        border: 3px solid #f3f3f3;
        border-top: 3px solid #667eea;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .word-count {
        font-size: 13px;
        color: #666;
        margin-top: 10px;
    }

    .unlock-btn {
        padding: 6px 12px;
        background: #e53e3e;
        color: white;
        border: none;
        border-radius: 6px;
        font-size: 13px;
        cursor: pointer;
    }

    .unlock-btn:hover {
        background: #c53030;
    }

    .history-link {
        font-size: 13px;
        color: #667eea;
        text-decoration: none;
    }

    .history-link:hover {
        text-decoration: underline;
    }
</style>

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
                    $charcount = mb_strlen($plaintext);
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
                        <h4 style="margin-bottom: 15px;">🤖 <?php echo get_string('ai_evaluation', 'redaction'); ?></h4>

                        <?php if (!$aievaluation): ?>
                            <p style="margin-bottom: 15px; font-size: 14px; color: #666;">
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

                            <?php if (!empty($aievaluation->parsed_feedback)): ?>
                                <div class="ai-feedback">
                                    <?php echo nl2br(s($aievaluation->parsed_feedback)); ?>
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
                    <h4 style="margin-bottom: 20px;">📝 <?php echo get_string('grade', 'redaction'); ?></h4>

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

<script>
function unlockSubmission(submissionId) {
    if (!confirm('<?php echo get_string('unlock_confirm', 'redaction'); ?>')) {
        return;
    }

    const formData = new FormData();
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    formData.append('id', '<?php echo $cm->id; ?>');
    formData.append('action', 'unlock');
    formData.append('submissionid', submissionId);

    fetch('<?php echo $CFG->wwwroot; ?>/mod/redaction/ajax/submit.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Connection error');
    });
}

function triggerAIEvaluation(submissionId) {
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="display:inline-block;width:16px;height:16px;margin-right:5px;"></span> En cours...';

    const formData = new FormData();
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    formData.append('id', '<?php echo $cm->id; ?>');
    formData.append('action', 'evaluate');
    formData.append('submissionid', submissionId);

    fetch('<?php echo $CFG->wwwroot; ?>/mod/redaction/ajax/evaluate.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reload after short delay to check status.
            setTimeout(() => location.reload(), 2000);
        } else {
            alert(data.message || 'Error');
            btn.disabled = false;
            btn.innerHTML = '🚀 Évaluer avec l\'IA';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Connection error');
        btn.disabled = false;
    });
}

function applyAIGrade(evaluationId) {
    const formData = new FormData();
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    formData.append('id', '<?php echo $cm->id; ?>');
    formData.append('evaluationid', evaluationId);

    fetch('<?php echo $CFG->wwwroot; ?>/mod/redaction/ajax/apply_ai_grade.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Connection error');
    });
}

function showHistory(submissionId) {
    const modal = document.getElementById('history-modal');
    const content = document.getElementById('history-content');

    content.innerHTML = '<div class="text-center p-4"><div class="spinner"></div></div>';

    // Show modal using Bootstrap.
    if (typeof $ !== 'undefined' && $.fn.modal) {
        $(modal).modal('show');
    } else {
        modal.style.display = 'block';
        modal.classList.add('show');
    }

    fetch('<?php echo $CFG->wwwroot; ?>/mod/redaction/ajax/get_history.php?sesskey=<?php echo sesskey(); ?>&id=<?php echo $cm->id; ?>&submissionid=' + submissionId)
    .then(response => response.json())
    .then(data => {
        if (data.success && data.history) {
            let html = '<div class="history-list">';
            data.history.forEach(version => {
                html += `
                    <div class="history-item" style="padding: 15px; border-bottom: 1px solid #eee;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                            <strong>Version ${version.version_number}</strong>
                            <span style="color: #666; font-size: 13px;">${version.date} - ${version.saved_by}</span>
                        </div>
                        <div style="font-size: 13px; color: #666;">
                            ${version.word_count} mots | ${version.char_count} caractères
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            content.innerHTML = html;
        } else {
            content.innerHTML = '<p class="text-muted">Aucun historique disponible.</p>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        content.innerHTML = '<p class="text-danger">Erreur de chargement.</p>';
    });
}
</script>

<?php
echo $OUTPUT->footer();
