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
 * Teacher grading screen of the assigned subjects mode.
 *
 * Included from grading.php (view.php?page=grading), which has already checked the login and the
 * mod/redaction:grade capability. Without a group it lists the groups; with one it shows its detail.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use mod_redaction\output\grading_sujets;
use mod_redaction\sujets;

$groupid = optional_param('groupid', 0, PARAM_INT);
$groupes = redaction_get_grading_filter_groups($cm, $course->id);
if ($groupid && !isset($groupes[$groupid])) {
    throw new \moodle_exception('sujets_ens_groupe_invalide', 'mod_redaction');
}

$pageurl = grading_sujets::url($cm, $groupid);
$PAGE->set_url($pageurl);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    // An action always names its group: a form without one would otherwise be ignored without a word.
    if (!$groupid) {
        throw new \moodle_exception('sujets_ens_groupe_invalide', 'mod_redaction');
    }

    $action = required_param('action', PARAM_ALPHA);
    $note = $action === 'note' ? required_param('grade', PARAM_FLOAT) : null;
    [$message, $type] = sujets::traiter_action(
        $redaction,
        $context,
        $groupid,
        $action,
        [
            'grade' => $note,
            'feedback' => optional_param('feedback', '', PARAM_TEXT),
            'sujetid' => optional_param_array('sujetid', [], PARAM_INT),
            'confirme' => (bool) optional_param('confirme', 0, PARAM_BOOL),
        ]
    );
    redirect($pageurl, $message, null, $type);
}

$PAGE->set_title(format_string($redaction->name) . ' - ' . get_string('grading', 'redaction'));
$PAGE->add_body_class('mod_redaction-grading-sujets');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('grading', 'redaction'));
echo html_writer::link(
    new moodle_url('/mod/redaction/view.php', ['id' => $cm->id]),
    '← ' . get_string('back_to_home', 'redaction'),
    ['class' => 'btn btn-secondary mb-3']
);

$vue = new grading_sujets($redaction, $cm, $context, $groupid);
echo $OUTPUT->render_from_template(
    $groupid ? 'mod_redaction/grading_sujets_detail' : 'mod_redaction/grading_sujets_overview',
    $vue->export_for_template($OUTPUT)
);

echo $OUTPUT->footer();
exit;
