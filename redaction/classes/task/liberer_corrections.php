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
 * Scheduled task that releases the corrections left running by a killed process.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\task;

/**
 * Fails, every minute, the corrections of the assigned subjects mode whose process is gone (17/11/2026).
 */
class liberer_corrections extends \core\task\scheduled_task {

    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_liberer_corrections', 'mod_redaction');
    }

    /**
     * Fails the corrections left running.
     */
    public function execute() {
        $liberees = \mod_redaction\ai_evaluator::liberer_bloquees();
        mtrace("Corrections left running by a killed process, released: {$liberees}");
    }
}
