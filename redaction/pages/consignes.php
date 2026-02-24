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

// Pre-render editor HTML for template injection.
$editorhtml = '';
if (!$islocked) {
    ob_start();
    $editor = editors_get_preferred_editor($consignes->consignesformat ?? FORMAT_HTML);
    $editor->set_text($consignes->consignes_editor['text'] ?? '');
    $editor->use_editor('consignes_editor_text', $editoroptions, ['context' => $context]);
    echo '<textarea id="consignes_editor_text"
                    name="consignes_editor[text]"
                    rows="15"
                    style="width: 100%;">' . s($consignes->consignes_editor['text'] ?? '') . '</textarea>';
    echo '<input type="hidden" name="consignes_editor[format]" value="' . ($consignes->consignesformat ?? FORMAT_HTML) . '">';
    echo '<input type="hidden" name="consignes_editor[itemid]" value="' . ($consignes->consignes_editor['itemid'] ?? 0) . '">';
    $editorhtml = ob_get_clean();
}

// Build template data.
$templatedata = [
    'islocked' => (bool)$islocked,
    'formurl' => $PAGE->url->out(false),
    'sesskey' => sesskey(),
    'titre' => s($consignes->titre ?? ''),
    'hasconsignescontent' => (bool)$islocked,
    'consignescontent' => $islocked ? format_text($consignes->consignes ?? '', $consignes->consignesformat ?? FORMAT_HTML) : '',
    'editorhtml' => $editorhtml,
    'criteres' => s($consignes->criteres ?? ''),
    'documents' => s($consignes->documents ?? ''),
    'criteresplaceholder' => get_string('criteres_placeholder', 'redaction'),
];

// Render using the Output API.
/** @var \mod_redaction\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_redaction');
echo $renderer->render_consignes($templatedata);

echo $OUTPUT->footer();
