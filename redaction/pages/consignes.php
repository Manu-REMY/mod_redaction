<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Teacher instructions page for redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

// Define constant if not exists (for older Moodle versions).
if (!defined('EDITOR_UNLIMITED_FILES')) {
    define('EDITOR_UNLIMITED_FILES', -1);
}

// Get or create consignes record.
$consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
if (!$consignes) {
    redaction_create_consignes($redaction->id);
    $consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
}

$islocked = $consignes->locked;

// Editor options for rich text.
$editoroptions = [
    'maxfiles' => EDITOR_UNLIMITED_FILES,
    'noclean' => true,
    'context' => $context,
    'subdirs' => true,
    'maxbytes' => $CFG->maxbytes,
    'changeformat' => 0,
    'trusttext' => true,
];

// Prepare editor for consignes field.
$consignes = file_prepare_standard_editor(
    $consignes,
    'consignes',
    $editoroptions,
    $context,
    'mod_redaction',
    'consignes',
    $consignes->id
);

// Handle form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $action = optional_param('action', 'save', PARAM_ALPHA);

    // Lock/unlock can happen regardless of current lock state.
    if ($action === 'lock') {
        $newlocked = required_param('locked', PARAM_INT);
        // We need the original record without editor data for update.
        $updaterecord = $DB->get_record('redaction_consignes', ['id' => $consignes->id]);
        $updaterecord->locked = $newlocked;
        $updaterecord->timemodified = time();
        $DB->update_record('redaction_consignes', $updaterecord);
        redirect(new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'consignes']));
    }

    // Save only if not locked.
    if ($action === 'save' && !$islocked) {
        // Get form data.
        $consignes->titre = optional_param('titre', '', PARAM_TEXT);
        $consignes->criteres = optional_param('criteres', '', PARAM_RAW);
        $consignes->documents = optional_param('documents', '', PARAM_RAW);

        // Handle editor content - get from POST array.
        $editordata = optional_param_array('consignes_editor', [], PARAM_RAW);
        $consignes->consignes_editor = [
            'text' => $editordata['text'] ?? '',
            'format' => isset($editordata['format']) ? (int)$editordata['format'] : FORMAT_HTML,
            'itemid' => isset($editordata['itemid']) ? (int)$editordata['itemid'] : 0,
        ];

        // Save editor content.
        $consignes = file_postupdate_standard_editor(
            $consignes,
            'consignes',
            $editoroptions,
            $context,
            'mod_redaction',
            'consignes',
            $consignes->id
        );

        $consignes->timemodified = time();
        $DB->update_record('redaction_consignes', $consignes);

        // Reload with fresh editor data.
        $consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
        $consignes = file_prepare_standard_editor(
            $consignes,
            'consignes',
            $editoroptions,
            $context,
            'mod_redaction',
            'consignes',
            $consignes->id
        );

        \core\notification::success(get_string('changessaved', 'moodle'));
    }
}

// Page setup.
$PAGE->set_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'consignes']);
$PAGE->set_title(format_string($redaction->name) . ' - ' . get_string('consignes', 'redaction'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('consignes', 'redaction'));

// Back button.
$homeurl = new moodle_url('/mod/redaction/view.php', ['id' => $cm->id]);
echo html_writer::link($homeurl, '← ' . get_string('back_to_home', 'redaction'), ['class' => 'btn btn-secondary mb-3']);

?>

<style>
    .consignes-form {
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
        margin-bottom: 10px;
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

    .lock-status {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .lock-status.locked {
        background: #f8d7da;
        color: #721c24;
    }

    .lock-status.unlocked {
        background: #d4edda;
        color: #155724;
    }

    .btn-lock {
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-lock.lock {
        background: #e53e3e;
        color: white;
    }

    .btn-lock.unlock {
        background: #48bb78;
        color: white;
    }

    .btn-lock:hover {
        transform: scale(1.02);
    }

    .btn-save {
        padding: 12px 30px;
        background: #667eea;
        color: white;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-save:hover {
        background: #5a67d8;
        transform: scale(1.02);
    }

    .btn-save:disabled {
        background: #ccc;
        cursor: not-allowed;
        transform: none;
    }

    .editor-container {
        border: 1px solid #ddd;
        border-radius: 8px;
        overflow: hidden;
    }

    .editor-container .editor_atto_wrap {
        border: none !important;
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

<div class="consignes-form">
    <?php if ($islocked): ?>
        <div class="lock-status locked">
            🔒 <?php echo get_string('consignes_locked', 'redaction'); ?>
        </div>
    <?php else: ?>
        <div class="lock-status unlocked">
            🔓 <?php echo get_string('consignes_unlocked', 'redaction'); ?>
        </div>
    <?php endif; ?>

    <form id="consignes-form" method="post" action="<?php echo $PAGE->url; ?>">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="save">

        <div class="form-group">
            <label for="titre"><?php echo get_string('consignes_title', 'redaction'); ?></label>
            <input type="text"
                   id="titre"
                   name="titre"
                   class="form-control"
                   value="<?php echo s($consignes->titre ?? ''); ?>"
                   <?php echo $islocked ? 'readonly' : ''; ?>
                   placeholder="<?php echo get_string('consignes_title', 'redaction'); ?>">
        </div>

        <div class="form-group">
            <label for="consignes_editor"><?php echo get_string('consignes_content', 'redaction'); ?></label>
            <div class="help-text"><?php echo get_string('consignes_content_help', 'redaction'); ?></div>
            <?php if ($islocked): ?>
                <div class="editor-container" style="padding: 15px; background: #f8f9fa;">
                    <?php echo format_text($consignes->consignes ?? '', $consignes->consignesformat ?? FORMAT_HTML); ?>
                </div>
            <?php else: ?>
                <div class="editor-container">
                    <?php
                    // Use Moodle's standard editor.
                    $editor = editors_get_preferred_editor($consignes->consignesformat ?? FORMAT_HTML);
                    $editor->set_text($consignes->consignes_editor['text'] ?? '');
                    $editor->use_editor('consignes_editor_text', $editoroptions, ['context' => $context]);
                    ?>
                    <textarea id="consignes_editor_text"
                              name="consignes_editor[text]"
                              rows="15"
                              style="width: 100%;"><?php echo s($consignes->consignes_editor['text'] ?? ''); ?></textarea>
                    <input type="hidden" name="consignes_editor[format]" value="<?php echo $consignes->consignesformat ?? FORMAT_HTML; ?>">
                    <input type="hidden" name="consignes_editor[itemid]" value="<?php echo $consignes->consignes_editor['itemid'] ?? 0; ?>">
                </div>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="criteres"><?php echo get_string('consignes_criteres', 'redaction'); ?></label>
            <div class="help-text"><?php echo get_string('consignes_criteres_help', 'redaction'); ?></div>
            <textarea id="criteres"
                      name="criteres"
                      class="form-control"
                      rows="6"
                      <?php echo $islocked ? 'readonly' : ''; ?>
                      placeholder="- Critère 1&#10;- Critère 2&#10;- Critère 3"><?php echo s($consignes->criteres ?? ''); ?></textarea>
        </div>

        <div class="form-group">
            <label for="documents"><?php echo get_string('consignes_documents', 'redaction'); ?></label>
            <div class="help-text"><?php echo get_string('consignes_documents_help', 'redaction'); ?></div>
            <textarea id="documents"
                      name="documents"
                      class="form-control"
                      rows="4"
                      <?php echo $islocked ? 'readonly' : ''; ?>
                      placeholder="https://exemple.com/ressource"><?php echo s($consignes->documents ?? ''); ?></textarea>
        </div>

        <?php if (!$islocked): ?>
            <div class="action-buttons">
                <button type="submit" class="btn-save">
                    💾 <?php echo get_string('savechanges', 'moodle'); ?>
                </button>
                <input type="hidden" name="locked" value="1">
                <button type="submit" name="action" value="lock" class="btn-lock lock" onclick="return confirm('<?php echo get_string('confirm_lock', 'redaction'); ?>');">
                    🔒 <?php echo get_string('lock_consignes', 'redaction'); ?>
                </button>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($islocked): ?>
        <form method="post" action="<?php echo $PAGE->url; ?>" class="mt-4">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="lock">
            <input type="hidden" name="locked" value="0">
            <div class="action-buttons">
                <div></div>
                <button type="submit" class="btn-lock unlock">
                    🔓 <?php echo get_string('unlock_consignes', 'redaction'); ?>
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php
echo $OUTPUT->footer();
