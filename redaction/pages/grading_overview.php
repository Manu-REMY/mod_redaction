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
 * Progression overview page (table view) for the grading interface.
 *
 * Expected variables in scope (set by grading.php which includes this file):
 *   $cm, $course, $redaction, $groupid, $renderer
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/redaction/lib.php');

$maxattempts = redaction_effective_max_attempts($redaction);

$overviewdata = new \mod_redaction\output\grading_overview_data(
    $cm->id,
    $redaction->id,
    $course->id,
    $groupid,
    $maxattempts
);

echo $renderer->render_from_template(
    'mod_redaction/grading_overview',
    $overviewdata->export_for_template($renderer)
);

$PAGE->requires->js_call_amd('mod_redaction/grading_overview', 'init', [[
    'cmid' => $cm->id,
]]);
