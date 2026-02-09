<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX handler for checking submission similarity.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/mod/redaction/lib.php');

$cmid = required_param('id', PARAM_INT);
$submissionid = required_param('submissionid', PARAM_INT);

$cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
require_sesskey();

$context = context_module::instance($cm->id);
require_capability('mod/redaction:grade', $context);

header('Content-Type: application/json');

try {
    $results = \mod_redaction\plagiarism_checker::check_submission($redaction->id, $submissionid);
    $threshold = \mod_redaction\plagiarism_checker::get_threshold();

    // Add alert flag based on threshold.
    foreach ($results as &$result) {
        $result['alert'] = ($result['similarity_percent'] >= $threshold);
    }

    echo json_encode([
        'success' => true,
        'threshold' => $threshold,
        'results' => $results,
    ]);
} catch (\Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
