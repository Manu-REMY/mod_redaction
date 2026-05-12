<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

$id = required_param('id', PARAM_INT); // cmid.
$overrideid = required_param('overrideid', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

$cm = get_coursemodule_from_id('redaction', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/redaction:manageoverrides', $context);
require_sesskey();

$override = $DB->get_record('redaction_overrides',
    ['id' => $overrideid, 'redactionid' => $redaction->id], '*', MUST_EXIST);
$mode = $override->userid ? 'user' : 'group';
$listurl = new \moodle_url('/mod/redaction/pages/overrides.php', ['id' => $cm->id, 'mode' => $mode]);

if ($confirm) {
    require_sesskey();
    $DB->delete_records('redaction_overrides', ['id' => $override->id]);

    $eventclass = $mode === 'user'
        ? \mod_redaction\event\user_override_deleted::class
        : \mod_redaction\event\group_override_deleted::class;
    $event = $eventclass::create([
        'objectid' => (int) $override->id,
        'context' => $context,
        'other' => [
            'redactionid' => (int) $redaction->id,
            'userid' => $override->userid,
            'groupid' => $override->groupid,
        ],
    ]);
    $event->trigger();

    redirect($listurl, get_string('override_deleted', 'mod_redaction'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->set_url('/mod/redaction/pages/override_delete.php',
    ['id' => $cm->id, 'overrideid' => $overrideid, 'sesskey' => sesskey()]);
$PAGE->set_title(format_string($redaction->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

if ($mode === 'user') {
    $target = $DB->get_record('user', ['id' => $override->userid], 'id, firstname, lastname');
    $label = fullname($target);
    $msg = get_string('override_confirm_delete_user', 'mod_redaction', $label);
} else {
    $target = $DB->get_record('groups', ['id' => $override->groupid], 'id, name');
    $label = format_string($target->name);
    $msg = get_string('override_confirm_delete_group', 'mod_redaction', $label);
}

$confirmurl = new \moodle_url('/mod/redaction/pages/override_delete.php', [
    'id' => $cm->id,
    'overrideid' => $overrideid,
    'sesskey' => sesskey(),
    'confirm' => 1,
]);

echo $OUTPUT->header();
echo $OUTPUT->confirm($msg, $confirmurl, $listurl);
echo $OUTPUT->footer();
