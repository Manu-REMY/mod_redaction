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
 * Scheduled task for automatic submission at deadline.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Task to automatically submit drafts when deadline passes.
 */
class auto_submit_deadline extends \core\task\scheduled_task {

    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_auto_submit_deadline', 'redaction');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $now = time();
        mtrace('Starting auto-submit deadline task at ' . userdate($now));

        // Find all draft submissions where deadline has passed.
        $sql = "SELECT s.*, c.deadline_date, r.ai_enabled, r.group_submission
                FROM {redaction_submission} s
                JOIN {redaction_correction} c ON c.redactionid = s.redactionid
                JOIN {redaction} r ON r.id = s.redactionid
                WHERE c.deadline_date IS NOT NULL
                  AND c.deadline_date <= :now
                  AND s.status = 0
                  AND s.contenu IS NOT NULL
                  AND s.contenu != ''";

        $drafts = $DB->get_records_sql($sql, ['now' => $now]);

        $count = 0;
        $errors = 0;

        foreach ($drafts as $submission) {
            try {
                $this->process_submission($submission);
                $count++;
                mtrace("  Auto-submitted submission ID {$submission->id} for redaction {$submission->redactionid}");
            } catch (\Exception $e) {
                $errors++;
                mtrace("  ERROR processing submission ID {$submission->id}: " . $e->getMessage());
            }
        }

        mtrace("Auto-submit deadline task completed: {$count} submissions processed, {$errors} errors.");
    }

    /**
     * Process a single submission.
     *
     * @param object $submission The submission record with additional fields
     */
    protected function process_submission($submission) {
        global $DB;

        // Determine the user ID for history.
        $savedby = $submission->userid;
        if ($submission->group_submission && $submission->groupid > 0 && $savedby == 0) {
            // For group submissions, get first group member as saved_by.
            $members = groups_get_members($submission->groupid, 'u.id', 'u.id ASC');
            if (!empty($members)) {
                $savedby = reset($members)->id;
            }
        }

        // Use admin user (2) if no user found.
        if (empty($savedby)) {
            $savedby = 2;
        }

        // 1. Save to history before submission.
        require_once(__DIR__ . '/../../lib.php');
        redaction_save_history($submission, $savedby);

        // 2. Update submission status.
        $submission->status = 1; // Submitted.
        // Use deadline time as submission time.
        $submission->timesubmitted = $submission->deadline_date;
        $submission->timemodified = time();

        $DB->update_record('redaction_submission', $submission);

        // 3. Trigger AI evaluation if enabled.
        if ($submission->ai_enabled) {
            $this->trigger_ai_evaluation($submission);
        }
    }

    /**
     * Trigger AI evaluation for the submission.
     *
     * @param object $submission
     */
    protected function trigger_ai_evaluation($submission) {
        require_once(__DIR__ . '/../ai_evaluator.php');

        try {
            \mod_redaction\ai_evaluator::queue_evaluation(
                $submission->redactionid,
                $submission->id,
                $submission->groupid,
                $submission->userid
            );
            mtrace("    Queued AI evaluation for submission ID {$submission->id}");
        } catch (\Exception $e) {
            mtrace("    WARNING: Could not queue AI evaluation: " . $e->getMessage());
            // Don't fail the submission if AI evaluation fails.
        }
    }
}
