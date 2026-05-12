<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use mod_redaction\form\override_form;

$id = required_param('id', PARAM_INT); // cmid.
$overrideid = optional_param('overrideid', 0, PARAM_INT);
$mode = optional_param('mode', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('redaction', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/redaction:manageoverrides', $context);

$existing = null;
if ($overrideid) {
    $existing = $DB->get_record('redaction_overrides', ['id' => $overrideid, 'redactionid' => $redaction->id], '*', MUST_EXIST);
    $mode = $existing->userid ? 'user' : 'group';
}
if (!in_array($mode, ['user', 'group'], true)) {
    throw new \moodle_exception('invalidparameter', 'debug', '', 'mode');
}

$PAGE->set_url('/mod/redaction/pages/override_edit.php', ['id' => $cm->id, 'overrideid' => $overrideid, 'mode' => $mode]);
$PAGE->set_title(format_string($redaction->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Build custom data lists.
$userlist = [];
$grouplist = [];
if ($mode === 'user') {
    $users = get_enrolled_users($context, 'mod/redaction:submit', 0, 'u.id, u.firstname, u.lastname',
        'u.lastname, u.firstname');
    foreach ($users as $u) {
        $userlist[$u->id] = fullname($u);
    }
} else {
    foreach (groups_get_all_groups($course->id) as $g) {
        $grouplist[$g->id] = format_string($g->name);
    }
}

$mform = new override_form(null, [
    'mode' => $mode,
    'cmid' => $cm->id,
    'redactionid' => (int) $redaction->id,
    'context' => $context,
    'existing' => $existing,
    'userlist' => $userlist,
    'grouplist' => $grouplist,
    'groupmodewarning' => ($mode === 'user' && (int) $redaction->group_submission === 1),
]);

$listurl = new \moodle_url('/mod/redaction/pages/overrides.php', ['id' => $cm->id, 'mode' => $mode]);

if ($mform->is_cancelled()) {
    redirect($listurl);
}

if ($data = $mform->get_data()) {
    $now = time();
    $record = (object) [
        'redactionid' => (int) $redaction->id,
        'deadline_date' => !empty($data->deadline_date) ? (int) $data->deadline_date : null,
        'timemodified' => $now,
    ];

    if ($mode === 'user') {
        $record->userid = (int) $data->userid;
        $record->groupid = null;
    } else {
        $record->groupid = (int) $data->groupid;
        $record->userid = null;
        $record->sortorder = (int) ($data->sortorder ?? 0);
    }

    if ($existing) {
        $record->id = (int) $existing->id;
        $DB->update_record('redaction_overrides', $record);
        $eventclass = $mode === 'user'
            ? \mod_redaction\event\user_override_updated::class
            : \mod_redaction\event\group_override_updated::class;
        $message = get_string('override_updated', 'mod_redaction');
    } else {
        $record->timecreated = $now;
        $record->id = $DB->insert_record('redaction_overrides', $record);
        $eventclass = $mode === 'user'
            ? \mod_redaction\event\user_override_created::class
            : \mod_redaction\event\group_override_created::class;
        $message = get_string('override_created', 'mod_redaction');
    }

    $event = $eventclass::create([
        'objectid' => $record->id,
        'context' => $context,
        'other' => [
            'redactionid' => (int) $redaction->id,
            'userid' => $record->userid,
            'groupid' => $record->groupid,
        ],
    ]);
    $event->trigger();

    redirect($listurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($existing ? 'editoverride' : ($mode === 'user' ? 'adduseroverride' : 'addgroupoverride'), 'mod_redaction'));
$mform->display();
echo $OUTPUT->footer();
