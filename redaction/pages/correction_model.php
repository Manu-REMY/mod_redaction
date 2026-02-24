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

// Pre-render editor HTML for template injection.
ob_start();
$editor = editors_get_preferred_editor($correction->modele_reponseformat ?? FORMAT_HTML);
$editor->set_text($correction->modele_reponse_editor['text'] ?? '');
$editor->use_editor('modele_reponse_editor_text', $editoroptions, ['context' => $context]);
echo '<textarea id="modele_reponse_editor_text"
                name="modele_reponse_editor[text]"
                rows="15"
                style="width: 100%;">' . s($correction->modele_reponse_editor['text'] ?? '') . '</textarea>';
echo '<input type="hidden" name="modele_reponse_editor[format]" value="' . ($correction->modele_reponseformat ?? FORMAT_HTML) . '">';
echo '<input type="hidden" name="modele_reponse_editor[itemid]" value="' . ($correction->modele_reponse_editor['itemid'] ?? 0) . '">';
$editorhtml = ob_get_clean();

// Build template data.
$templatedata = [
    'aienabled' => (bool)$aienabled,
    'aiprovider' => ucfirst($redaction->ai_provider ?? ''),
    'hasconsignes' => (bool)$hasconsignes,
    'formurl' => $PAGE->url->out(false),
    'sesskey' => sesskey(),
    'submissiondatevalue' => $correction->submission_date ? date('Y-m-d\TH:i', $correction->submission_date) : '',
    'deadlinedatevalue' => $correction->deadline_date ? date('Y-m-d\TH:i', $correction->deadline_date) : '',
    'editorhtml' => $editorhtml,
    'grillecriteresjson' => s($correction->grille_criteres ?? ''),
    'aiinstructions' => s($correction->ai_instructions ?? ''),
    'cmid' => $cm->id,
    'wwwroot' => $CFG->wwwroot,
];

// Render using the Output API.
/** @var \mod_redaction\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_redaction');
echo $renderer->render_correction_model($templatedata);

// Initialise the criteria editor AMD module.
$jsparams = [
    'cmid' => $cm->id,
    'sesskey' => sesskey(),
    'wwwroot' => $CFG->wwwroot,
    'aienabled' => (bool)$aienabled,
    'hasconsignes' => (bool)$hasconsignes,
    'strings' => [
        'criterion_name_placeholder' => get_string('criterion_name_placeholder', 'redaction'),
        'criterion_description_placeholder' => get_string('criterion_description_placeholder', 'redaction'),
        'remove_criterion' => get_string('remove_criterion', 'redaction'),
        'weight_ok' => get_string('weight_ok', 'redaction', '{$a}'),
        'weight_warning_under' => get_string('weight_warning_under', 'redaction', '{$a}'),
        'weight_warning_over' => get_string('weight_warning_over', 'redaction', '{$a}'),
        'ai_generate_confirm_overwrite' => get_string('ai_generate_confirm_overwrite', 'redaction'),
        'ai_generate_success' => get_string('ai_generate_success', 'redaction'),
        'ai_request_failed' => get_string('ai_request_failed', 'redaction'),
    ],
];
$PAGE->requires->js_call_amd('mod_redaction/criteria_editor', 'init', [$jsparams]);

echo $OUTPUT->footer();
