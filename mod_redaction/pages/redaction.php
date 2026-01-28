<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Student writing page for redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Get consignes.
$consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
if (!$consignes || empty($consignes->consignes)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('error:noconsignes', 'redaction'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

// Get user's group.
$usergroup = redaction_get_user_group($cm, $USER->id);

// Check group requirement.
if ($redaction->group_submission && $usergroup == 0) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('Vous devez appartenir à un groupe pour accéder à cette activité.', 'error');
    echo $OUTPUT->footer();
    exit;
}

// Get or create submission.
$submission = redaction_get_or_create_submission($redaction, $usergroup, $USER->id);
$issubmitted = ($submission->status == 1);
$isgraded = ($submission->grade !== null);

// Page setup.
$PAGE->set_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'redaction']);
$PAGE->set_title(format_string($redaction->name) . ' - ' . get_string('redaction', 'redaction'));

// Initialize autosave JS only if not submitted.
if (!$issubmitted) {
    $PAGE->requires->js_call_amd('mod_redaction/autosave', 'init', [
        'cmid' => $cm->id,
        'page' => 'redaction',
        'interval' => $redaction->autosave_interval * 1000,
        'formSelector' => '#redaction-form',
        'groupid' => $usergroup
    ]);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('redaction', 'redaction'));

// Back button.
$homeurl = new moodle_url('/mod/redaction/view.php', ['id' => $cm->id]);
echo html_writer::link($homeurl, '← ' . get_string('back_to_home', 'redaction'), ['class' => 'btn btn-secondary mb-3']);

// Group info.
if ($redaction->group_submission && $usergroup > 0) {
    $groupinfo = $DB->get_record('groups', ['id' => $usergroup]);
    if ($groupinfo) {
        echo '<div class="alert alert-info mb-3">👥 Groupe : <strong>' . s($groupinfo->name) . '</strong></div>';
    }
}

?>

<style>
    .redaction-container {
        max-width: 1000px;
        margin: 20px auto;
    }

    .consignes-panel {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 25px;
    }

    .consignes-panel h3 {
        color: #667eea;
        margin-bottom: 15px;
        font-size: 18px;
    }

    .consignes-content {
        background: white;
        padding: 20px;
        border-radius: 8px;
        border-left: 4px solid #667eea;
    }

    .criteres-panel {
        margin-top: 15px;
        padding: 15px;
        background: #fff3cd;
        border-radius: 8px;
    }

    .criteres-panel h4 {
        color: #856404;
        margin-bottom: 10px;
        font-size: 14px;
    }

    .redaction-form {
        background: white;
        padding: 30px;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .form-group {
        margin-bottom: 25px;
    }

    .form-group label {
        font-weight: 600;
        color: #333;
        margin-bottom: 8px;
        display: block;
    }

    .form-control {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 15px;
        transition: border-color 0.3s;
    }

    .form-control:focus {
        border-color: #48bb78;
        outline: none;
        box-shadow: 0 0 0 3px rgba(72, 187, 120, 0.1);
    }

    .form-control:disabled,
    .form-control:read-only {
        background: #f8f9fa;
        cursor: not-allowed;
    }

    .editor-container {
        border: 1px solid #ddd;
        border-radius: 8px;
        overflow: hidden;
    }

    .editor-container textarea {
        border: none;
        border-radius: 0;
    }

    .submission-status {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .submission-status.draft {
        background: #fff3cd;
        color: #856404;
    }

    .submission-status.submitted {
        background: #cce5ff;
        color: #004085;
    }

    .submission-status.graded {
        background: #d4edda;
        color: #155724;
    }

    .grade-display {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 20px;
        border-radius: 12px;
        text-align: center;
        margin-bottom: 20px;
    }

    .grade-display .grade-value {
        font-size: 36px;
        font-weight: bold;
    }

    .feedback-panel {
        background: #e7f3ff;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .feedback-panel h4 {
        color: #004085;
        margin-bottom: 10px;
    }

    .btn-submit {
        background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        color: white;
        border: none;
        padding: 15px 30px;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-submit:hover {
        transform: scale(1.02);
    }

    .btn-submit:disabled {
        background: #ccc;
        cursor: not-allowed;
        transform: none;
    }

    .autosave-status {
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 10px 20px;
        background: #333;
        color: white;
        border-radius: 8px;
        opacity: 0;
        transition: opacity 0.3s;
        z-index: 1000;
    }

    .autosave-status.visible {
        opacity: 1;
    }

    .autosave-status.saving {
        background: #667eea;
    }

    .autosave-status.saved {
        background: #48bb78;
    }

    .autosave-status.error {
        background: #e53e3e;
    }

    .word-counter {
        text-align: right;
        font-size: 13px;
        color: #666;
        margin-top: 5px;
    }
</style>

<div class="redaction-container">
    <!-- Panel Consignes -->
    <div class="consignes-panel">
        <h3>📋 <?php echo s($consignes->titre ?? get_string('consignes', 'redaction')); ?></h3>
        <div class="consignes-content">
            <?php echo format_text($consignes->consignes, $consignes->consignesformat); ?>
        </div>

        <?php if (!empty($consignes->criteres)): ?>
            <div class="criteres-panel">
                <h4>📝 <?php echo get_string('consignes_criteres', 'redaction'); ?></h4>
                <?php echo format_text($consignes->criteres, $consignes->criteresformat); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($consignes->documents)): ?>
            <div class="mt-3">
                <strong>📎 <?php echo get_string('consignes_documents', 'redaction'); ?></strong>
                <div><?php echo format_text($consignes->documents, $consignes->documentsformat); ?></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Status and Grade Display -->
    <?php if ($isgraded): ?>
        <div class="grade-display">
            <div><?php echo get_string('grade', 'redaction'); ?></div>
            <div class="grade-value"><?php echo number_format($submission->grade, 1); ?> / 20</div>
        </div>

        <?php if (!empty($submission->feedback)): ?>
            <div class="feedback-panel">
                <h4><?php echo get_string('feedback', 'redaction'); ?></h4>
                <?php echo format_text($submission->feedback, $submission->feedbackformat); ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($issubmitted): ?>
        <div class="submission-status <?php echo $isgraded ? 'graded' : 'submitted'; ?>">
            <?php if ($isgraded): ?>
                ✓ <?php echo get_string('status_submitted', 'redaction'); ?> - Noté
            <?php else: ?>
                ✓ <?php echo get_string('submitted_on', 'redaction', userdate($submission->timesubmitted)); ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="submission-status draft">
            📝 <?php echo get_string('status_draft', 'redaction'); ?>
            - <?php echo get_string('saving', 'redaction'); ?>
        </div>
    <?php endif; ?>

    <!-- Formulaire de rédaction -->
    <div class="redaction-form">
        <form id="redaction-form" method="post">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="id" value="<?php echo $cm->id; ?>">
            <input type="hidden" name="page" value="redaction">
            <input type="hidden" name="groupid" value="<?php echo $usergroup; ?>">

            <div class="form-group">
                <label for="titre"><?php echo get_string('redaction_title', 'redaction'); ?></label>
                <input type="text"
                       id="titre"
                       name="titre"
                       class="form-control"
                       value="<?php echo s($submission->titre ?? ''); ?>"
                       <?php echo $issubmitted ? 'readonly' : ''; ?>
                       placeholder="<?php echo get_string('redaction_title_placeholder', 'redaction'); ?>">
            </div>

            <div class="form-group">
                <label for="contenu"><?php echo get_string('redaction_content', 'redaction'); ?></label>
                <div class="editor-container">
                    <textarea id="contenu"
                              name="contenu"
                              class="form-control"
                              rows="15"
                              <?php echo $issubmitted ? 'readonly' : ''; ?>
                              placeholder="<?php echo get_string('redaction_content_placeholder', 'redaction'); ?>"
                              style="min-height: 300px;"><?php echo s($submission->contenu ?? ''); ?></textarea>
                </div>
                <div class="word-counter" id="word-counter">0 mots</div>
            </div>

            <?php if (!$issubmitted): ?>
                <div class="d-flex justify-content-end gap-3">
                    <button type="button"
                            class="btn-submit"
                            onclick="submitRedaction()">
                        <?php echo get_string('submit_redaction', 'redaction'); ?>
                    </button>
                </div>
            <?php elseif ($cangrade): ?>
                <div class="d-flex justify-content-end">
                    <button type="button"
                            class="btn btn-warning"
                            onclick="unlockSubmission()">
                        🔓 <?php echo get_string('unlock_submission', 'redaction'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div id="autosave-status" class="autosave-status"></div>

<script>
// Word counter.
const contenuField = document.getElementById('contenu');
const wordCounter = document.getElementById('word-counter');

function updateWordCount() {
    const text = contenuField.value.trim();
    const words = text ? text.split(/\s+/).length : 0;
    wordCounter.textContent = words + ' mots';
}

contenuField.addEventListener('input', updateWordCount);
updateWordCount();

// Submit function.
function submitRedaction() {
    if (!confirm('<?php echo get_string('submit_confirm', 'redaction'); ?>')) {
        return;
    }

    const formData = new FormData(document.getElementById('redaction-form'));
    formData.append('action', 'submit');

    fetch('<?php echo $CFG->wwwroot; ?>/mod/redaction/ajax/submit.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Erreur lors de la soumission');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Erreur de connexion');
    });
}

// Unlock function (teachers only).
function unlockSubmission() {
    const formData = new FormData();
    formData.append('sesskey', '<?php echo sesskey(); ?>');
    formData.append('id', '<?php echo $cm->id; ?>');
    formData.append('action', 'unlock');
    formData.append('groupid', '<?php echo $usergroup; ?>');
    formData.append('userid', '<?php echo $USER->id; ?>');

    fetch('<?php echo $CFG->wwwroot; ?>/mod/redaction/ajax/submit.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Erreur');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Erreur de connexion');
    });
}
</script>

<?php
echo $OUTPUT->footer();
