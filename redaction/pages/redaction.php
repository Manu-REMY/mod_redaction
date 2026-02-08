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
    echo $OUTPUT->notification('Vous devez appartenir à un groupe pour accéder à cette activité.', 'error');
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
         WHERE submissionid = ? AND (status = ? OR status = ?)
         ORDER BY timecreated DESC',
        [$submission->id, 'completed', 'applied'],
        0,
        1
    );
    $aievaluation = !empty($records) ? reset($records) : null;
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

        // Mark as submitted.
        $submission->status = 1;
        $submission->timesubmitted = time();
        $submission->timemodified = time();
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

    .ai-evaluation-detail {
        background: white;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .ai-evaluation-detail h4 {
        color: #667eea;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid #e9ecef;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .ai-criteria-grid {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin-bottom: 20px;
    }

    .ai-criterion-card {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 15px;
        border-left: 4px solid #667eea;
    }

    .ai-criterion-card.excellent {
        border-left-color: #48bb78;
    }

    .ai-criterion-card.good {
        border-left-color: #38a169;
    }

    .ai-criterion-card.medium {
        border-left-color: #ed8936;
    }

    .ai-criterion-card.low {
        border-left-color: #f56565;
    }

    .criterion-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .criterion-name {
        font-weight: 600;
        color: #333;
        font-size: 15px;
    }

    .criterion-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 600;
        color: white;
    }

    .criterion-badge.excellent {
        background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
    }

    .criterion-badge.good {
        background: linear-gradient(135deg, #38a169 0%, #2f855a 100%);
    }

    .criterion-badge.medium {
        background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%);
    }

    .criterion-badge.low {
        background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%);
    }

    .criterion-progress-bar {
        height: 8px;
        background: #e2e8f0;
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 10px;
    }

    .criterion-progress-fill {
        height: 100%;
        border-radius: 4px;
        transition: width 0.5s ease;
    }

    .criterion-progress-fill.excellent {
        background: linear-gradient(90deg, #48bb78, #38a169);
    }

    .criterion-progress-fill.good {
        background: linear-gradient(90deg, #38a169, #2f855a);
    }

    .criterion-progress-fill.medium {
        background: linear-gradient(90deg, #ed8936, #dd6b20);
    }

    .criterion-progress-fill.low {
        background: linear-gradient(90deg, #f56565, #e53e3e);
    }

    .criterion-comment {
        font-size: 14px;
        color: #555;
        line-height: 1.6;
        background: white;
        padding: 12px;
        border-radius: 8px;
        margin-top: 10px;
    }

    .ai-general-feedback {
        background: #f0f4ff;
        border-radius: 10px;
        padding: 15px;
        margin-top: 15px;
    }

    .ai-general-feedback h5 {
        color: #667eea;
        margin-bottom: 10px;
        font-size: 14px;
    }

    .ai-general-feedback p {
        color: #555;
        line-height: 1.7;
        margin: 0;
    }

    .collapsible-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
        padding: 5px 0;
    }

    .collapsible-header:hover {
        opacity: 0.8;
    }

    .collapse-icon {
        font-size: 12px;
        transition: transform 0.3s ease;
    }

    .collapsible-header.collapsed .collapse-icon {
        transform: rotate(-90deg);
    }

    .collapsible-content {
        overflow: hidden;
        max-height: 2000px;
        transition: max-height 0.3s ease;
    }

    .collapsible-content.collapsed {
        max-height: 0;
    }

    .btn-save {
        background: #667eea;
        color: white;
        border: none;
        padding: 12px 25px;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-save:hover {
        background: #5a67d8;
        transform: scale(1.02);
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

    .action-buttons {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #eee;
    }
</style>

<div class="redaction-container">
    <!-- Panel Consignes -->
    <div class="consignes-panel">
        <h3>📋 <?php echo s($consignes->titre ?? get_string('consignes', 'redaction')); ?></h3>
        <div class="consignes-content">
            <?php echo format_text($consignes->consignes, $consignes->consignesformat ?? FORMAT_HTML); ?>
        </div>

        <?php if (!empty($consignes->criteres)): ?>
            <div class="criteres-panel">
                <h4>📝 <?php echo get_string('consignes_criteres', 'redaction'); ?></h4>
                <?php echo nl2br(s($consignes->criteres)); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($consignes->documents)): ?>
            <div class="mt-3">
                <strong>📎 <?php echo get_string('consignes_documents', 'redaction'); ?></strong>
                <div><?php echo nl2br(s($consignes->documents)); ?></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Status and Grade Display -->
    <?php if ($isgraded): ?>
        <div class="grade-display">
            <div><?php echo get_string('grade', 'redaction'); ?></div>
            <div class="grade-value"><?php echo number_format($submission->grade, 1); ?> / 20</div>
        </div>

        <?php
        // Parse AI criteria if available.
        $criteria = [];
        if ($aievaluation && !empty($aievaluation->criteria_json)) {
            $criteria = json_decode($aievaluation->criteria_json, true);
            if (!is_array($criteria)) {
                $criteria = [];
            }
        }
        ?>

        <?php if (!empty($criteria)): ?>
            <div class="ai-evaluation-detail">
                <div class="collapsible-header" onclick="toggleCollapsible(this)">
                    <h4 style="margin: 0;">📊 <?php echo get_string('ai_criteria_details', 'redaction'); ?></h4>
                    <span class="collapse-icon">▼</span>
                </div>
                <div class="collapsible-content">
                    <div class="ai-criteria-grid">
                        <?php foreach ($criteria as $criterion): ?>
                            <?php
                            $score = isset($criterion['score']) ? (float)$criterion['score'] : 0;
                            $max = isset($criterion['max']) ? (float)$criterion['max'] : 5;
                            $percentage = $max > 0 ? ($score / $max) * 100 : 0;

                            // Determine level class.
                            if ($percentage >= 80) {
                                $levelClass = 'excellent';
                                $levelText = get_string('level_excellent', 'redaction');
                            } elseif ($percentage >= 65) {
                                $levelClass = 'good';
                                $levelText = get_string('level_good', 'redaction');
                            } elseif ($percentage >= 50) {
                                $levelClass = 'medium';
                                $levelText = get_string('level_medium', 'redaction');
                            } else {
                                $levelClass = 'low';
                                $levelText = get_string('level_low', 'redaction');
                            }
                            ?>
                            <div class="ai-criterion-card <?php echo $levelClass; ?>">
                                <div class="criterion-header">
                                    <span class="criterion-name"><?php echo s($criterion['name'] ?? 'Critère'); ?></span>
                                    <span class="criterion-badge <?php echo $levelClass; ?>">
                                        <?php echo number_format($score, 1); ?>/<?php echo number_format($max, 0); ?>
                                        <span style="font-size: 11px; opacity: 0.9;">(<?php echo $levelText; ?>)</span>
                                    </span>
                                </div>
                                <div class="criterion-progress-bar">
                                    <div class="criterion-progress-fill <?php echo $levelClass; ?>"
                                         style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                                <?php if (!empty($criterion['comment'])): ?>
                                    <div class="criterion-comment">
                                        <?php echo nl2br(s($criterion['comment'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($aievaluation && !empty($aievaluation->parsed_feedback)): ?>
                        <div class="ai-general-feedback">
                            <h5>💬 <?php echo get_string('ai_general_feedback', 'redaction'); ?></h5>
                            <p><?php echo nl2br(s($aievaluation->parsed_feedback)); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif (!empty($submission->feedback)): ?>
            <div class="feedback-panel">
                <h4><?php echo get_string('feedback', 'redaction'); ?></h4>
                <?php echo format_text($submission->feedback, $submission->feedbackformat ?? FORMAT_HTML); ?>
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
        </div>
    <?php endif; ?>

    <!-- Formulaire de rédaction -->
    <div class="redaction-form">
        <form id="redaction-form" method="post" action="<?php echo $PAGE->url; ?>">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
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
                <label for="contenu_editor_text"><?php echo get_string('redaction_content', 'redaction'); ?></label>
                <?php if ($issubmitted): ?>
                    <div class="editor-container" style="padding: 15px; background: #f8f9fa;">
                        <?php echo format_text($submission->contenu ?? '', $submission->contenuformat ?? FORMAT_HTML); ?>
                    </div>
                <?php else: ?>
                    <div class="editor-container">
                        <?php
                        // Use Moodle's standard editor.
                        $editor = editors_get_preferred_editor($submission->contenuformat ?? FORMAT_HTML);
                        $editor->set_text($submission->contenu_editor['text'] ?? '');
                        $editor->use_editor('contenu_editor_text', $editoroptions, ['context' => $context]);
                        ?>
                        <textarea id="contenu_editor_text"
                                  name="contenu_editor[text]"
                                  rows="20"
                                  style="width: 100%;"><?php echo s($submission->contenu_editor['text'] ?? ''); ?></textarea>
                        <input type="hidden" name="contenu_editor[format]" value="<?php echo $submission->contenuformat ?? FORMAT_HTML; ?>">
                        <input type="hidden" name="contenu_editor[itemid]" value="<?php echo $submission->contenu_editor['itemid'] ?? 0; ?>">
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$issubmitted): ?>
                <div class="action-buttons">
                    <button type="submit" name="action" value="save" class="btn-save">
                        💾 <?php echo get_string('savechanges', 'moodle'); ?>
                    </button>
                    <button type="submit" name="action" value="submit" class="btn-submit" onclick="return confirm('<?php echo get_string('submit_confirm', 'redaction'); ?>');">
                        ✓ <?php echo get_string('submit_redaction', 'redaction'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
function toggleCollapsible(header) {
    header.classList.toggle('collapsed');
    const content = header.nextElementSibling;
    content.classList.toggle('collapsed');
}
</script>

<?php
echo $OUTPUT->footer();
