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

require_once($CFG->libdir . '/filelib.php');

// Define constant if not exists.
if (!defined('EDITOR_UNLIMITED_FILES')) {
    define('EDITOR_UNLIMITED_FILES', -1);
}

// Get or create correction record.
$correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
if (!$correction) {
    redaction_create_correction($redaction->id);
    $correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
}

// Get consignes to check if they exist.
$consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
$hasconsignes = $consignes && !empty($consignes->consignes) && $consignes->locked;

// Check if AI is enabled.
$aienabled = $redaction->ai_enabled;

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

// Prepare editor for modele_reponse field.
$correction = file_prepare_standard_editor(
    $correction,
    'modele_reponse',
    $editoroptions,
    $context,
    'mod_redaction',
    'modele_reponse',
    $correction->id
);

// Handle form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    // Get form data.
    $correction->grille_criteres = optional_param('grille_criteres', '', PARAM_RAW);
    $correction->ai_instructions = optional_param('ai_instructions', '', PARAM_RAW);

    // Handle dates.
    $submissiondate = optional_param('submission_date', '', PARAM_RAW);
    $deadlinedate = optional_param('deadline_date', '', PARAM_RAW);
    $correction->submission_date = !empty($submissiondate) ? strtotime($submissiondate) : null;
    $correction->deadline_date = !empty($deadlinedate) ? strtotime($deadlinedate) : null;

    // Handle editor content.
    $editordata = optional_param_array('modele_reponse_editor', [], PARAM_RAW);
    $correction->modele_reponse_editor = [
        'text' => $editordata['text'] ?? '',
        'format' => isset($editordata['format']) ? (int)$editordata['format'] : FORMAT_HTML,
        'itemid' => isset($editordata['itemid']) ? (int)$editordata['itemid'] : 0,
    ];

    // Save editor content.
    $correction = file_postupdate_standard_editor(
        $correction,
        'modele_reponse',
        $editoroptions,
        $context,
        'mod_redaction',
        'modele_reponse',
        $correction->id
    );

    $correction->timemodified = time();
    $DB->update_record('redaction_correction', $correction);

    // Reload with fresh editor data.
    $correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
    $correction = file_prepare_standard_editor(
        $correction,
        'modele_reponse',
        $editoroptions,
        $context,
        'mod_redaction',
        'modele_reponse',
        $correction->id
    );

    \core\notification::success(get_string('changessaved', 'moodle'));
}

// Page setup.
$PAGE->set_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'correction']);
$PAGE->set_title(format_string($redaction->name) . ' - ' . get_string('correction_model', 'redaction'));

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

    .section-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: 600;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .section-header .btn-generate {
        background: rgba(255,255,255,0.2);
        color: white;
        border: 1px solid rgba(255,255,255,0.4);
        padding: 6px 15px;
        border-radius: 6px;
        font-size: 13px;
        cursor: pointer;
        transition: all 0.3s;
    }

    .section-header .btn-generate:hover {
        background: rgba(255,255,255,0.3);
    }

    .section-header .btn-generate:disabled {
        opacity: 0.5;
        cursor: not-allowed;
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

    .criteria-editor {
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 15px;
        background: #f8f9fa;
    }

    .criteria-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .criterion-row {
        display: grid;
        grid-template-columns: 1fr 1.5fr 80px 40px;
        gap: 10px;
        align-items: start;
        background: white;
        padding: 12px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        transition: box-shadow 0.2s;
    }

    .criterion-row:hover {
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .criterion-row input,
    .criterion-row textarea {
        width: 100%;
        padding: 8px 10px;
        border: 1px solid #ddd;
        border-radius: 6px;
        font-size: 13px;
        transition: border-color 0.2s;
    }

    .criterion-row input:focus,
    .criterion-row textarea:focus {
        border-color: #667eea;
        outline: none;
        box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
    }

    .criterion-row textarea {
        min-height: 36px;
        resize: vertical;
    }

    .criterion-weight-input {
        text-align: center;
        font-weight: 600;
    }

    .btn-remove-criterion {
        background: none;
        border: none;
        color: #e53e3e;
        cursor: pointer;
        font-size: 18px;
        padding: 6px;
        border-radius: 6px;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .btn-remove-criterion:hover {
        background: #fed7d7;
    }

    .criteria-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid #e2e8f0;
    }

    .btn-add-criterion {
        background: #667eea;
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .btn-add-criterion:hover {
        background: #5a67d8;
    }

    .criteria-total {
        font-weight: 600;
        font-size: 14px;
        padding: 6px 14px;
        border-radius: 20px;
    }

    .criteria-total.ok {
        background: #c6f6d5;
        color: #276749;
    }

    .criteria-total.warning {
        background: #fed7d7;
        color: #9b2c2c;
    }

    .criteria-json-details {
        margin-top: 10px;
    }

    .criteria-json-details summary {
        cursor: pointer;
        font-size: 12px;
        color: #666;
    }

    @media (max-width: 768px) {
        .criterion-row {
            grid-template-columns: 1fr;
        }
    }

    .editor-container {
        border: 1px solid #ddd;
        border-radius: 8px;
        overflow: hidden;
    }

    .btn-save {
        background: #667eea;
        color: white;
        border: none;
        padding: 12px 30px;
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

    .action-buttons {
        display: flex;
        justify-content: flex-end;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #eee;
    }

    .ai-generate-section {
        background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        color: white;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 25px;
        text-align: center;
    }

    .ai-generate-section h4 {
        margin-bottom: 10px;
    }

    .ai-generate-section p {
        margin-bottom: 15px;
        opacity: 0.9;
    }

    .btn-ai-generate {
        background: white;
        color: #38a169;
        border: none;
        padding: 12px 30px;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-ai-generate:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }

    .btn-ai-generate:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }

    .btn-ai-generate .spinner {
        display: none;
        margin-right: 8px;
    }

    .btn-ai-generate.loading .spinner {
        display: inline-block;
    }

    .btn-ai-generate.loading .btn-text {
        opacity: 0.7;
    }

    .warning-box {
        background: #fff3cd;
        border: 1px solid #ffeeba;
        color: #856404;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
</style>

<div class="correction-form">
    <?php if ($aienabled): ?>
        <div class="ai-status enabled">
            ✅ <?php echo get_string('ai_enabled', 'redaction'); ?>
            (<?php echo ucfirst($redaction->ai_provider); ?>)
        </div>

        <?php if ($hasconsignes): ?>
            <!-- AI Generate Section -->
            <div class="ai-generate-section">
                <h4>🤖 <?php echo get_string('ai_generate_criteria', 'redaction'); ?></h4>
                <p><?php echo get_string('ai_generate_criteria_help', 'redaction'); ?></p>
                <button type="button" id="btn-generate-ai" class="btn-ai-generate" onclick="generateWithAI()">
                    <span class="spinner">⏳</span>
                    <span class="btn-text">✨ <?php echo get_string('ai_generate_button', 'redaction'); ?></span>
                </button>
            </div>
        <?php else: ?>
            <div class="warning-box">
                ⚠️ <?php echo get_string('ai_generate_need_consignes', 'redaction'); ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="ai-status disabled">
            ⚠️ <?php echo get_string('ai_disabled_warning', 'redaction'); ?>
        </div>
    <?php endif; ?>

    <form id="correction-form" method="post" action="<?php echo $PAGE->url; ?>">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

        <!-- Dates Section -->
        <div class="section-header">
            <span>📅 <?php echo get_string('dates_section', 'redaction'); ?></span>
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
            <span>📝 <?php echo get_string('modele_reponse', 'redaction'); ?></span>
        </div>

        <div class="form-group">
            <label for="modele_reponse_editor_text"><?php echo get_string('modele_reponse', 'redaction'); ?></label>
            <div class="help-text"><?php echo get_string('modele_reponse_help', 'redaction'); ?></div>
            <div class="editor-container">
                <?php
                // Use Moodle's standard editor.
                $editor = editors_get_preferred_editor($correction->modele_reponseformat ?? FORMAT_HTML);
                $editor->set_text($correction->modele_reponse_editor['text'] ?? '');
                $editor->use_editor('modele_reponse_editor_text', $editoroptions, ['context' => $context]);
                ?>
                <textarea id="modele_reponse_editor_text"
                          name="modele_reponse_editor[text]"
                          rows="15"
                          style="width: 100%;"><?php echo s($correction->modele_reponse_editor['text'] ?? ''); ?></textarea>
                <input type="hidden" name="modele_reponse_editor[format]" value="<?php echo $correction->modele_reponseformat ?? FORMAT_HTML; ?>">
                <input type="hidden" name="modele_reponse_editor[itemid]" value="<?php echo $correction->modele_reponse_editor['itemid'] ?? 0; ?>">
            </div>
        </div>

        <!-- Criteria Grid Section -->
        <div class="section-header">
            <span>📊 <?php echo get_string('grille_criteres', 'redaction'); ?></span>
        </div>

        <div class="form-group">
            <label><?php echo get_string('grille_criteres', 'redaction'); ?></label>
            <div class="help-text"><?php echo get_string('grille_criteres_visual_help', 'redaction'); ?></div>

            <!-- Hidden field for JSON storage -->
            <input type="hidden" id="grille_criteres" name="grille_criteres"
                   value="<?php echo s($correction->grille_criteres ?? ''); ?>">

            <!-- Visual criteria editor -->
            <div class="criteria-editor" id="criteria-editor">
                <div class="criteria-list" id="criteria-list">
                    <!-- Criterion rows rendered by JS -->
                </div>

                <div class="criteria-footer">
                    <button type="button" class="btn-add-criterion" onclick="addCriterion()">
                        + <?php echo get_string('add_criterion', 'redaction'); ?>
                    </button>
                    <div class="criteria-total" id="criteria-total">
                        <span id="total-weight-text"></span>
                    </div>
                </div>
            </div>

            <!-- Collapsible raw JSON (advanced) -->
            <details class="criteria-json-details">
                <summary><?php echo get_string('show_json', 'redaction'); ?></summary>
                <textarea id="grille_criteres_raw" class="form-control" rows="6" readonly
                          style="font-family: monospace; font-size: 12px; margin-top: 8px;"></textarea>
            </details>
        </div>

        <!-- AI Instructions Section -->
        <?php if ($aienabled): ?>
        <div class="section-header">
            <span>🤖 <?php echo get_string('ai_instructions', 'redaction'); ?></span>
        </div>

        <div class="form-group">
            <label for="ai_instructions"><?php echo get_string('ai_instructions', 'redaction'); ?></label>
            <div class="help-text"><?php echo get_string('ai_instructions_help', 'redaction'); ?></div>
            <textarea id="ai_instructions"
                      name="ai_instructions"
                      class="form-control"
                      rows="10"
                      placeholder="<?php echo get_string('ai_instructions_placeholder', 'redaction'); ?>"><?php echo s($correction->ai_instructions ?? ''); ?></textarea>
        </div>
        <?php endif; ?>

        <div class="action-buttons">
            <button type="submit" class="btn-save">
                💾 <?php echo get_string('savechanges', 'moodle'); ?>
            </button>
        </div>
    </form>
</div>

<script>
// ===== Visual Criteria Editor =====
let criteriaData = [];

function initCriteriaEditor() {
    const hiddenField = document.getElementById('grille_criteres');
    const raw = hiddenField.value.trim();

    if (raw) {
        try {
            const parsed = JSON.parse(raw);
            if (Array.isArray(parsed)) {
                criteriaData = parsed.map(c => ({
                    name: c.name || '',
                    description: c.description || '',
                    weight: parseInt(c.weight) || 0
                }));
            }
        } catch (e) {
            console.warn('Could not parse existing criteria JSON:', e);
        }
    }

    // Add defaults if empty.
    if (criteriaData.length === 0) {
        criteriaData = [
            { name: '', description: '', weight: 5 }
        ];
    }

    renderCriteria();
}

function renderCriteria() {
    const container = document.getElementById('criteria-list');
    container.innerHTML = '';

    criteriaData.forEach((criterion, index) => {
        const row = document.createElement('div');
        row.className = 'criterion-row';
        row.innerHTML =
            '<div>' +
                '<input type="text" placeholder="<?php echo addslashes_js(get_string('criterion_name_placeholder', 'redaction')); ?>"' +
                ' value="' + escapeHtml(criterion.name) + '"' +
                ' onchange="updateCriterion(' + index + ', \'name\', this.value)"' +
                ' oninput="updateCriterion(' + index + ', \'name\', this.value)">' +
            '</div>' +
            '<div>' +
                '<textarea placeholder="<?php echo addslashes_js(get_string('criterion_description_placeholder', 'redaction')); ?>"' +
                ' onchange="updateCriterion(' + index + ', \'description\', this.value)"' +
                ' oninput="updateCriterion(' + index + ', \'description\', this.value)"' +
                ' rows="1">' + escapeHtml(criterion.description) + '</textarea>' +
            '</div>' +
            '<div>' +
                '<input type="number" min="0" max="20" step="1"' +
                ' class="criterion-weight-input"' +
                ' value="' + criterion.weight + '"' +
                ' onchange="updateCriterion(' + index + ', \'weight\', parseInt(this.value) || 0)"' +
                ' oninput="updateCriterion(' + index + ', \'weight\', parseInt(this.value) || 0)">' +
            '</div>' +
            '<div>' +
                '<button type="button" class="btn-remove-criterion" onclick="removeCriterion(' + index + ')" title="<?php echo addslashes_js(get_string('remove_criterion', 'redaction')); ?>">' +
                    '&times;' +
                '</button>' +
            '</div>';
        container.appendChild(row);
    });

    updateTotalWeight();
    syncToHiddenField();
}

function addCriterion() {
    criteriaData.push({ name: '', description: '', weight: 5 });
    renderCriteria();
    // Focus the new name input.
    const rows = document.querySelectorAll('.criterion-row');
    if (rows.length > 0) {
        const lastRow = rows[rows.length - 1];
        const input = lastRow.querySelector('input[type="text"]');
        if (input) input.focus();
    }
}

function removeCriterion(index) {
    if (criteriaData.length <= 1) return;
    criteriaData.splice(index, 1);
    renderCriteria();
}

function updateCriterion(index, field, value) {
    if (criteriaData[index]) {
        criteriaData[index][field] = value;
        if (field === 'weight') {
            updateTotalWeight();
        }
        syncToHiddenField();
    }
}

function updateTotalWeight() {
    const total = criteriaData.reduce((sum, c) => sum + (parseInt(c.weight) || 0), 0);
    const totalEl = document.getElementById('criteria-total');
    const textEl = document.getElementById('total-weight-text');

    if (total === 20) {
        totalEl.className = 'criteria-total ok';
        textEl.textContent = '<?php echo addslashes_js(get_string('weight_ok', 'redaction', '{TOTAL}')); ?>'.replace('{TOTAL}', total);
    } else if (total < 20) {
        totalEl.className = 'criteria-total warning';
        textEl.textContent = '<?php echo addslashes_js(get_string('weight_warning_under', 'redaction', '{TOTAL}')); ?>'.replace('{TOTAL}', total);
    } else {
        totalEl.className = 'criteria-total warning';
        textEl.textContent = '<?php echo addslashes_js(get_string('weight_warning_over', 'redaction', '{TOTAL}')); ?>'.replace('{TOTAL}', total);
    }
}

function syncToHiddenField() {
    // Only sync criteria that have a name.
    const validCriteria = criteriaData.filter(c => c.name.trim() !== '');
    const json = JSON.stringify(validCriteria, null, 2);
    document.getElementById('grille_criteres').value = json;

    // Update raw JSON view if open.
    const rawTextarea = document.getElementById('grille_criteres_raw');
    if (rawTextarea) {
        rawTextarea.value = json;
    }
}

function loadCriteriaFromJson(jsonStr) {
    try {
        const parsed = JSON.parse(jsonStr);
        if (Array.isArray(parsed)) {
            criteriaData = parsed.map(c => ({
                name: c.name || '',
                description: c.description || '',
                weight: parseInt(c.weight) || 0
            }));
            renderCriteria();
            return true;
        }
    } catch (e) {
        console.error('Invalid JSON:', e);
    }
    return false;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(text || ''));
    return div.innerHTML;
}

// Initialize on page load.
document.addEventListener('DOMContentLoaded', initCriteriaEditor);

<?php if ($aienabled && $hasconsignes): ?>
// ===== AI Generation =====
function generateWithAI() {
    const btn = document.getElementById('btn-generate-ai');
    const hiddenField = document.getElementById('grille_criteres');
    const aiInstructions = document.getElementById('ai_instructions');

    // Check if fields already have content.
    if (hiddenField.value.trim() || (aiInstructions && aiInstructions.value.trim())) {
        if (!confirm('<?php echo addslashes_js(get_string('ai_generate_confirm_overwrite', 'redaction')); ?>')) {
            return;
        }
    }

    // Disable button and show loading.
    btn.disabled = true;
    btn.classList.add('loading');

    const formData = new FormData();
    formData.append('id', '<?php echo $cm->id; ?>');
    formData.append('sesskey', '<?php echo sesskey(); ?>');

    fetch('<?php echo $CFG->wwwroot; ?>/mod/redaction/ajax/generate_criteria.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Load criteria into visual editor.
            loadCriteriaFromJson(data.grille_criteres);

            // Fill AI instructions.
            if (aiInstructions) {
                aiInstructions.value = data.ai_instructions;
            }

            alert('<?php echo addslashes_js(get_string('ai_generate_success', 'redaction')); ?>');
        } else {
            alert(data.message || '<?php echo addslashes_js(get_string('ai_request_failed', 'redaction')); ?>');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('<?php echo addslashes_js(get_string('ai_request_failed', 'redaction')); ?>');
    })
    .finally(() => {
        btn.disabled = false;
        btn.classList.remove('loading');
    });
}
<?php endif; ?>
</script>

<?php
echo $OUTPUT->footer();
