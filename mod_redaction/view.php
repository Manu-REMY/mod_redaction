<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Main view page for redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = optional_param('id', 0, PARAM_INT); // Course module ID.
$a = optional_param('a', 0, PARAM_INT);  // Redaction instance ID.
$page = optional_param('page', '', PARAM_ALPHA); // Page: consignes, redaction, correction, grading.

$cm = false;
$redaction = false;
$course = false;

if ($id) {
    $cm = get_coursemodule_from_id('redaction', $id, 0, false, IGNORE_MISSING);
    if ($cm) {
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);
    } else {
        // If CM lookup failed, maybe id was actually the instance id?
        $a = $id;
        $id = 0;
    }
}

if ($a) {
    $redaction = $DB->get_record('redaction', ['id' => $a], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $redaction->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('redaction', $redaction->id, $course->id, false, MUST_EXIST);
}

if (!$cm) {
    throw new \moodle_exception('missingidandcmid', 'redaction');
}

require_login($course, true, $cm);

$context = context_module::instance($cm->id);

require_capability('mod/redaction:view', $context);

// Trigger module viewed event.
$event = \mod_redaction\event\course_module_viewed::create([
    'objectid' => $redaction->id,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('redaction', $redaction);
$event->trigger();

// Completion tracking.
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Page setup.
$PAGE->set_url('/mod/redaction/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($redaction->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Check user capabilities.
$caneditconsignes = has_capability('mod/redaction:editconsignes', $context);
$cangrade = has_capability('mod/redaction:grade', $context);
$cansubmit = has_capability('mod/redaction:submit', $context);
$canviewhistory = has_capability('mod/redaction:viewhistory', $context);

// Get user's group if student.
$usergroup = 0;
if ($cansubmit && !$caneditconsignes) {
    $usergroup = redaction_get_user_group($cm, $USER->id);
}

// Determine which view to show.
if ($page !== '') {
    switch ($page) {
        case 'consignes':
            // Teacher instructions page.
            require_capability('mod/redaction:editconsignes', $context);
            require_once(__DIR__ . '/pages/consignes.php');
            exit;

        case 'redaction':
            // Student writing page.
            require_capability('mod/redaction:submit', $context);
            require_once(__DIR__ . '/pages/redaction.php');
            exit;

        case 'correction':
            // Teacher correction model page.
            require_capability('mod/redaction:editconsignes', $context);
            require_once(__DIR__ . '/pages/correction_model.php');
            exit;

        case 'grading':
            // Grading interface.
            require_capability('mod/redaction:grade', $context);
            require_once(__DIR__ . '/grading.php');
            exit;

        default:
            throw new \moodle_exception('invalidpage', 'redaction');
    }
}

// Show home page.
echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($redaction->name));

// Include home page template.
require_once(__DIR__ . '/pages/home.php');

echo $OUTPUT->footer();
