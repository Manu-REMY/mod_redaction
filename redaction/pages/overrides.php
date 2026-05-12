<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

$id = required_param('id', PARAM_INT); // Course module id.
$mode = optional_param('mode', 'user', PARAM_ALPHA);

if (!in_array($mode, ['user', 'group'], true)) {
    $mode = 'user';
}

$cm = get_coursemodule_from_id('redaction', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/redaction:manageoverrides', $context);

$PAGE->set_url('/mod/redaction/pages/overrides.php', ['id' => $cm->id, 'mode' => $mode]);
$PAGE->set_title(format_string($redaction->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
$instancedeadline = $correction && !empty($correction->deadline_date)
    ? (int) $correction->deadline_date : null;

// Load rows.
if ($mode === 'user') {
    $sql = "SELECT o.*, u.firstname, u.lastname
              FROM {redaction_overrides} o
              JOIN {user} u ON u.id = o.userid
             WHERE o.redactionid = :rid AND o.userid IS NOT NULL
          ORDER BY u.lastname, u.firstname";
    $rows = $DB->get_records_sql($sql, ['rid' => $redaction->id]);
    foreach ($rows as $row) {
        $row->_target_label = fullname((object) [
            'firstname' => $row->firstname,
            'lastname' => $row->lastname,
        ]);
    }
} else {
    $sql = "SELECT o.*, g.name AS groupname
              FROM {redaction_overrides} o
              JOIN {groups} g ON g.id = o.groupid
             WHERE o.redactionid = :rid AND o.groupid IS NOT NULL
          ORDER BY COALESCE(o.sortorder, 0), g.name";
    $rows = $DB->get_records_sql($sql, ['rid' => $redaction->id]);
    foreach ($rows as $row) {
        $row->_target_label = format_string($row->groupname);
    }
}

$renderable = new \mod_redaction\output\overrides_table($mode, $cm->id, $rows, $instancedeadline);
$heading = get_string($mode === 'user' ? 'useroverrides' : 'groupoverrides', 'mod_redaction');

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
echo $OUTPUT->render_from_template('mod_redaction/overrides_table', $renderable->export_for_template($OUTPUT));
echo $OUTPUT->footer();
