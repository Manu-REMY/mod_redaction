<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Teacher correction model page for redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Get or create correction record.
$correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
if (!$correction) {
    redaction_create_correction($redaction->id);
    $correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
}

// Check if AI is enabled.
$aienabled = $redaction->ai_enabled;

// Page setup.
$PAGE->set_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'correction']);
$PAGE->set_title(format_string($redaction->name) . ' - ' . get_string('correction_model', 'redaction'));

// Initialize autosave JS.
$PAGE->requires->js_call_amd('mod_redaction/autosave', 'init', [
    'cmid' => $cm->id,
    'page' => 'correction',
    'interval' => $redaction->autosave_interval * 1000,
    'formSelector' => '#correction-form'
]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('correction_model', 'redaction'));

// Back button.
$homeurl = new moodle_url('/mod/redaction/view.php', ['id' => $cm->id]);
echo html_writer::link($homeurl, '← ' . get_string('back_to_home', 'redaction'), ['class' => 'btn btn-secondary mb-3']);

?>

<style>
    .correction-form {
        max-width: 900px;
        margin: 20px auto;
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

    .form-group .help-text {
        font-size: 13px;
        color: #666;
        margin-top: 5px;
    }

    .form-control {
        width: 100%;
        padding: 10px 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 15px;
        transition: border-color 0.3s;
    }

    .form-control:focus {
        border-color: #667eea;
        outline: none;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .ai-status {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .ai-status.enabled {
        background: #d4edda;
        color: #155724;
    }

    .ai-status.disabled {
        background: #f8d7da;
        color: #721c24;
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

    .section-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: 600;
    }

    .dates-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    @media (max-width: 768px) {
        .dates-container {
            grid-template-columns: 1fr;
        }
    }

    .criteria-grid-container {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        margin-top: 10px;
    }

    .criteria-grid-container code {
        display: block;
        background: #e9ecef;
        padding: 10px;
        border-radius: 5px;
        font-size: 12px;
        margin-top: 10px;
    }
</style>

<div class="correction-form">
    <?php if ($aienabled): ?>
        <div class="ai-status enabled">
            ✅ <?php echo get_string('ai_enabled', 'redaction'); ?>
            (<?php echo ucfirst($redaction->ai_provider); ?>)
        </div>
    <?php else: ?>
        <div class="ai-status disabled">
            ⚠️ <?php echo get_string('ai_disabled_warning', 'redaction'); ?>
        </div>
    <?php endif; ?>

    <form id="correction-form" method="post" action="">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="id" value="<?php echo $cm->id; ?>">
        <input type="hidden" name="page" value="correction">

        <!-- Dates Section -->
        <div class="section-header">
            📅 <?php echo get_string('dates_section', 'redaction'); ?>
        </div>

        <div class="dates-container">
            <div class="form-group">
                <label for="submission_date"><?php echo get_string('submission_date', 'redaction'); ?></label>
                <input type="datetime-local"
                       id="submission_date"
                       name="submission_date"
                       class="form-control"
                       value="<?php echo $correction->submission_date ? date('Y-m-d\TH:i', $correction->submission_date) : ''; ?>">
            </div>

            <div class="form-group">
                <label for="deadline_date"><?php echo get_string('deadline_date', 'redaction'); ?></label>
                <input type="datetime-local"
                       id="deadline_date"
                       name="deadline_date"
                       class="form-control"
                       value="<?php echo $correction->deadline_date ? date('Y-m-d\TH:i', $correction->deadline_date) : ''; ?>">
            </div>
        </div>

        <!-- Answer Model Section -->
        <div class="section-header">
            📝 <?php echo get_string('modele_reponse', 'redaction'); ?>
        </div>

        <div class="form-group">
            <label for="modele_reponse"><?php echo get_string('modele_reponse', 'redaction'); ?></label>
            <div class="help-text"><?php echo get_string('modele_reponse_help', 'redaction'); ?></div>
            <textarea id="modele_reponse"
                      name="modele_reponse"
                      class="form-control"
                      rows="10"
                      style="min-height: 200px;"
                      placeholder="<?php echo get_string('modele_reponse_placeholder', 'redaction'); ?>"><?php echo s($correction->modele_reponse ?? ''); ?></textarea>
        </div>

        <!-- Criteria Grid Section -->
        <div class="section-header">
            📊 <?php echo get_string('grille_criteres', 'redaction'); ?>
        </div>

        <div class="form-group">
            <label for="grille_criteres"><?php echo get_string('grille_criteres', 'redaction'); ?></label>
            <div class="help-text"><?php echo get_string('grille_criteres_help', 'redaction'); ?></div>
            <textarea id="grille_criteres"
                      name="grille_criteres"
                      class="form-control"
                      rows="8"
                      placeholder="<?php echo get_string('grille_criteres_placeholder', 'redaction'); ?>"><?php echo s($correction->grille_criteres ?? ''); ?></textarea>
            <div class="criteria-grid-container">
                <small><?php echo get_string('grille_criteres_example', 'redaction'); ?></small>
                <code>[
  {"name": "Pertinence", "weight": 5, "description": "Réponse pertinente au sujet"},
  {"name": "Structure", "weight": 5, "description": "Organisation logique"},
  {"name": "Expression", "weight": 5, "description": "Qualité de l'expression écrite"},
  {"name": "Argumentation", "weight": 5, "description": "Qualité des arguments"}
]</code>
            </div>
        </div>

        <!-- AI Instructions Section -->
        <?php if ($aienabled): ?>
        <div class="section-header">
            🤖 <?php echo get_string('ai_instructions', 'redaction'); ?>
        </div>

        <div class="form-group">
            <label for="ai_instructions"><?php echo get_string('ai_instructions', 'redaction'); ?></label>
            <div class="help-text"><?php echo get_string('ai_instructions_help', 'redaction'); ?></div>
            <textarea id="ai_instructions"
                      name="ai_instructions"
                      class="form-control"
                      rows="8"
                      placeholder="<?php echo get_string('ai_instructions_placeholder', 'redaction'); ?>"><?php echo s($correction->ai_instructions ?? ''); ?></textarea>
        </div>
        <?php endif; ?>

        <div class="d-flex justify-content-end mt-4">
            <span id="save-indicator" class="text-muted me-3" style="line-height: 38px;"></span>
        </div>
    </form>
</div>

<div id="autosave-status" class="autosave-status"></div>

<?php
echo $OUTPUT->footer();
