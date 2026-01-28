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

// Get or create consignes record.
$consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
if (!$consignes) {
    redaction_create_consignes($redaction->id);
    $consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
}

$islocked = $consignes->locked;

// Page setup.
$PAGE->set_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'consignes']);
$PAGE->set_title(format_string($redaction->name) . ' - ' . get_string('consignes', 'redaction'));

// Initialize autosave JS.
$PAGE->requires->js_call_amd('mod_redaction/autosave', 'init', [
    'cmid' => $cm->id,
    'page' => 'consignes',
    'interval' => $redaction->autosave_interval * 1000,
    'formSelector' => '#consignes-form'
]);

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

    .editor-container {
        border: 1px solid #ddd;
        border-radius: 8px;
        overflow: hidden;
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

    <form id="consignes-form" method="post" action="">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="id" value="<?php echo $cm->id; ?>">
        <input type="hidden" name="page" value="consignes">

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
            <label for="consignes_content"><?php echo get_string('consignes_content', 'redaction'); ?></label>
            <div class="help-text"><?php echo get_string('consignes_content_help', 'redaction'); ?></div>
            <div class="editor-container">
                <?php
                $editoroptions = [
                    'maxfiles' => EDITOR_UNLIMITED_FILES,
                    'noclean' => true,
                    'context' => $context,
                    'subdirs' => true
                ];
                $consignes = file_prepare_standard_editor(
                    $consignes,
                    'consignes',
                    $editoroptions,
                    $context,
                    'mod_redaction',
                    'consignes',
                    $consignes->id
                );
                echo $OUTPUT->render(
                    new \core_form\output\editor_weka(
                        'consignes_editor',
                        'consignes',
                        $consignes->consignes ?? '',
                        $consignes->consignesformat ?? FORMAT_HTML,
                        $context,
                        $editoroptions
                    )
                );
                ?>
                <textarea id="consignes_content"
                          name="consignes"
                          class="form-control"
                          rows="10"
                          <?php echo $islocked ? 'readonly' : ''; ?>
                          style="min-height: 200px;"><?php echo s($consignes->consignes ?? ''); ?></textarea>
            </div>
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
            <div class="d-flex justify-content-between mt-4">
                <button type="button"
                        class="btn-lock lock"
                        onclick="toggleLock(1)">
                    🔒 <?php echo get_string('lock_consignes', 'redaction'); ?>
                </button>
            </div>
        <?php else: ?>
            <div class="d-flex justify-content-between mt-4">
                <button type="button"
                        class="btn-lock unlock"
                        onclick="toggleLock(0)">
                    🔓 <?php echo get_string('unlock_consignes', 'redaction'); ?>
                </button>
            </div>
        <?php endif; ?>
    </form>
</div>

<div id="autosave-status" class="autosave-status"></div>

<script>
function toggleLock(lockValue) {
    const form = document.getElementById('consignes-form');
    const formData = new FormData(form);
    formData.append('action', 'lock');
    formData.append('locked', lockValue);

    fetch('<?php echo $CFG->wwwroot; ?>/mod/redaction/ajax/autosave.php', {
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
