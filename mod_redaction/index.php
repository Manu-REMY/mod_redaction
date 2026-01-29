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
 * Display information about all the redaction activities in a course.
 *
 * @package    mod_redaction
 * @copyright  2025 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/redaction/lib.php');

$id = required_param('id', PARAM_INT); // Course ID.

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_course_login($course);

$PAGE->set_url('/mod/redaction/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

// Trigger course module instance list viewed event.
$event = \core\event\course_module_instance_list_viewed::create([
    'context' => context_course::instance($course->id),
]);
$event->add_record_snapshot('course', $course);
$event->trigger();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_redaction'));

// Get all redaction activities in this course.
$redactions = get_all_instances_in_course('redaction', $course);

if (empty($redactions)) {
    notice(get_string('noredactions', 'mod_redaction'), new moodle_url('/course/view.php', ['id' => $course->id]));
}

// Prepare table.
$usesections = course_format_uses_sections($course->format);

$table = new html_table();
$table->attributes['class'] = 'generaltable mod_index';

if ($usesections) {
    $strsectionname = get_string('sectionname', 'format_' . $course->format);
    $table->head = [$strsectionname, get_string('name'), get_string('description')];
    $table->align = ['center', 'left', 'left'];
} else {
    $table->head = [get_string('name'), get_string('description')];
    $table->align = ['left', 'left'];
}

foreach ($redactions as $redaction) {
    $attributes = [];
    if (!$redaction->visible) {
        $attributes['class'] = 'dimmed';
    }

    $link = html_writer::link(
        new moodle_url('/mod/redaction/view.php', ['id' => $redaction->coursemodule]),
        format_string($redaction->name),
        $attributes
    );

    $description = format_module_intro('redaction', $redaction, $redaction->coursemodule);

    if ($usesections) {
        $table->data[] = [get_section_name($course, $redaction->section), $link, $description];
    } else {
        $table->data[] = [$link, $description];
    }
}

echo html_writer::table($table);
echo $OUTPUT->footer();
