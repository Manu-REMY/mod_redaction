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

// An individual activity is graded student by student, a group activity group by group.
$individuel = sujets::individuel($redaction);
$groupid = $individuel ? 0 : optional_param('groupid', 0, PARAM_INT);
$userid = $individuel ? optional_param('userid', 0, PARAM_INT) : 0;
if ($groupid) {
    $groupes = redaction_get_grading_filter_groups($cm, $course->id);
    if (!isset($groupes[$groupid])) {
        throw new \moodle_exception('sujets_ens_groupe_invalide', 'mod_redaction');
    }
}
if ($userid && !is_enrolled($context, $userid, 'mod/redaction:submit')) {
    throw new \moodle_exception('sujets_ens_eleve_invalide', 'mod_redaction');
}

$pageurl = grading_sujets::url($cm, $groupid, $userid);
$PAGE->set_url($pageurl);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    // An action always names its group or student: a form without one would otherwise be ignored without a word.
    if (!$groupid && !$userid) {
        throw new \moodle_exception($individuel ? 'sujets_ens_eleve_invalide' : 'sujets_ens_groupe_invalide', 'mod_redaction');
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
            // New deadline when reopening a copy past its date (request 16), in the teacher's time zone.
            'date' => ($d = \DateTime::createFromFormat('Y-m-d\TH:i', optional_param('date', '', PARAM_TEXT),
                \core_date::get_user_timezone_object())) ? $d->getTimestamp() : null,
        ],
        $userid
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

$vue = new grading_sujets($redaction, $cm, $context, $groupid, $userid);
echo $OUTPUT->render_from_template(
    ($groupid || $userid) ? 'mod_redaction/grading_sujets_detail' : 'mod_redaction/grading_sujets_overview',
    $vue->export_for_template($OUTPUT)
);

echo $OUTPUT->footer();
exit;
