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
 * Teacher page to edit the subjects of the assigned subjects mode.
 *
 * Included from view.php (page=sujets), which has already checked the login
 * and the mod/redaction:editconsignes capability.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if (empty($redaction->sujets_enabled)) {
    throw new \moodle_exception('sujets_disabled', 'redaction');
}

// Number of subject rows offered (existing ones plus an empty one, within the cap).
$maxsujets = 8;
$pageurl = new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'sujets']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $ids = optional_param_array('sujetid', [], PARAM_INT);
    $titres = optional_param_array('titre', [], PARAM_TEXT);
    $contenus = optional_param_array('contenu', [], PARAM_RAW);
    $corriges = optional_param_array('corrige', [], PARAM_RAW);
    $lexiques = optional_param_array('lexique', [], PARAM_TEXT);
    $deletes = optional_param_array('delete', [], PARAM_INT);

    $now = time();
    $order = 0;
    $refused = [];
    foreach ($ids as $index => $sujetid) {
        $titre = trim($titres[$index] ?? '');
        $contenu = trim($contenus[$index] ?? '');
        $corrige = trim($corriges[$index] ?? '');
        $lexique = trim($lexiques[$index] ?? '');

        if ($sujetid && !empty($deletes[$index])) {
            $own = $DB->get_record('redaction_sujet', ['id' => $sujetid, 'redactionid' => $redaction->id]);
            if ($own && !redaction_delete_sujet($redaction->id, $sujetid)) {
                $refused[] = $own->titre;
                $order++;
                $DB->set_field('redaction_sujet', 'sortorder', $order, ['id' => $sujetid]);
            }
            continue;
        }
        if ($titre === '' && $contenu === '' && $corrige === '') {
            continue;
        }
        $order++;
        $record = (object) [
            'sortorder' => $order,
            'titre' => $titre,
            'contenu' => clean_text($contenu, FORMAT_HTML),
            'contenuformat' => FORMAT_HTML,
            'corrige' => $corrige,
            'lexique' => $lexique,
            'timemodified' => $now,
        ];
        if ($sujetid && $DB->record_exists('redaction_sujet', ['id' => $sujetid, 'redactionid' => $redaction->id])) {
            $record->id = $sujetid;
            $DB->update_record('redaction_sujet', $record);
        } else if ($DB->count_records('redaction_sujet', ['redactionid' => $redaction->id]) < $maxsujets) {
            $record->redactionid = $redaction->id;
            $record->timecreated = $now;
            $DB->insert_record('redaction_sujet', $record);
        }
    }

    if ($refused) {
        redirect(
            $pageurl,
            get_string('sujets_delete_refused', 'redaction', implode(', ', array_map('format_string', $refused))),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }
    redirect($pageurl, get_string('sujets_saved', 'redaction'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$rows = [];
$number = 0;
foreach ($DB->get_records('redaction_sujet', ['redactionid' => $redaction->id], 'sortorder, id') as $sujet) {
    $number++;
    $rows[] = [
        'index' => $number - 1,
        'number' => $number,
        'id' => (int) $sujet->id,
        'titre' => $sujet->titre,
        'contenu' => $sujet->contenu,
        'corrige' => $sujet->corrige,
        'lexique' => (string) ($sujet->lexique ?? ''),
        'isexisting' => true,
    ];
}
if ($number < $maxsujets) {
    $rows[] = [
        'index' => $number,
        'number' => $number + 1,
        'id' => 0,
        'titre' => '',
        'contenu' => '',
        'corrige' => '',
        'lexique' => '',
        'isexisting' => false,
    ];
}

$data = [
    'actionurl' => $pageurl->out(false),
    'sesskey' => sesskey(),
    'rows' => $rows,
    'backurl' => (new moodle_url('/mod/redaction/view.php', ['id' => $cm->id]))->out(false),
];

$PAGE->set_url($pageurl);
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('sujets_title', 'redaction'));
echo $OUTPUT->render_from_template('mod_redaction/sujets_edit', $data);
echo $OUTPUT->footer();
