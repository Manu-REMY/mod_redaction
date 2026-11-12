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
 * Student screen of the assigned subjects mode.
 *
 * Included by pages/redaction.php, which provides $redaction, $cm, $context and the usual Moodle globals.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// An individual activity has one submission per student, with no group: the subjects are the student's own.
$individuel = \mod_redaction\sujets::individuel($redaction);
$usergroup = $individuel ? 0 : redaction_get_user_group($cm, $USER->id);

$PAGE->set_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'redaction']);
$PAGE->set_title(format_string($redaction->name) . ' - ' . get_string('redaction', 'redaction'));

if (!$individuel && $usergroup == 0) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('group_required', 'redaction'), 'error');
    echo $OUTPUT->footer();
    exit;
}

$submission = redaction_get_or_create_submission($redaction, $usergroup, $USER->id);

$PAGE->requires->js_call_amd('mod_redaction/sujets', 'init', [[
    'cmid' => (int) $cm->id,
    'submissionid' => (int) $submission->id,
    'phrases' => (int) ($redaction->sujets_phrases ?? 0),
]]);

$screen = new \mod_redaction\output\sujets_eleve($redaction, $cm, $context, $submission, (int) $usergroup);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('redaction', 'redaction'));
echo $OUTPUT->render_from_template('mod_redaction/sujets_eleve', $screen->export_for_template($OUTPUT));
echo $OUTPUT->footer();
