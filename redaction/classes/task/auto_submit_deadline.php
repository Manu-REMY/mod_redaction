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
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/redaction/lib.php');

        $now = time();
        mtrace('Starting auto-submit deadline task...');

        // Find all redactions with passed deadlines.
        $sql = "SELECT r.id, r.name, r.course, r.group_submission, r.ai_enabled,
                       c.deadline_date
                FROM {redaction} r
                JOIN {redaction_correction} c ON c.redactionid = r.id
                WHERE c.deadline_date IS NOT NULL
                  AND c.deadline_date > 0
                  AND c.deadline_date <= :now";

        $redactions = $DB->get_records_sql($sql, ['now' => $now]);

        if (empty($redactions)) {
            mtrace('No redactions with passed deadlines found.');
            mtrace('Auto-submit deadline task completed. Processed: 0, Errors: 0');
            return;
        }

        $totalprocessed = 0;
        $totalerrors = 0;
        $totalevaluations = 0;

        foreach ($redactions as $redaction) {
            // Use date() instead of userdate() to avoid IntlTimeZone dependency.
            $deadlinestr = date('Y-m-d H:i:s', $redaction->deadline_date);
            mtrace("  Processing redaction ID {$redaction->id} (deadline: {$deadlinestr})...");

            // Get all draft submissions (status = 0) for this redaction.
            $drafts = $DB->get_records('redaction_submission', [
                'redactionid' => $redaction->id,
                'status' => 0
            ]);

            if (!empty($drafts)) {
                mtrace("    Found " . count($drafts) . " draft(s) to auto-submit");

                foreach ($drafts as $draft) {
                    try {
                        // Skip empty drafts entirely: no auto-submit, no lock.
                        // Otherwise these become "submitted but ungraded" ghosts that pollute
                        // the dashboard and require manual cleanup by the teacher.
                        if (empty(trim(strip_tags($draft->contenu ?? '')))) {
                            $identifier = $draft->groupid ? "group {$draft->groupid}" : "user {$draft->userid}";
                            mtrace("      Skipped empty draft for {$identifier}");
                            continue;
                        }

                        // Update to submitted status.
                        $draft->status = 1; // Submitted.
                        $draft->timesubmitted = $redaction->deadline_date;
                        $draft->timemodified = $now;

                        $DB->update_record('redaction_submission', $draft);

                        // Log the auto-submission.
                        $identifier = $draft->groupid ? "group {$draft->groupid}" : "user {$draft->userid}";
                        mtrace("      Auto-submitted {$identifier}");

                        // Skip history save in cron to avoid PHP extension issues.
                        // History is already saved when student submits manually.

                        // Trigger AI evaluation if enabled.
                        if ($redaction->ai_enabled) {
                            $this->trigger_ai_evaluation($draft);
                            $totalevaluations++;
                        }

                        $totalprocessed++;
                    } catch (\Exception $e) {
                        mtrace("      ERROR: " . $e->getMessage());
                        $totalerrors++;
                    }
                }
            } else {
                mtrace("    No draft submissions found.");
            }

            // Check for submitted submissions without AI evaluation (catch-up mechanism).
            if ($redaction->ai_enabled) {
                $evaluated = $this->queue_missing_evaluations($redaction);
                $totalevaluations += $evaluated;
            }
        }

        mtrace("Auto-submit deadline task completed. Processed: {$totalprocessed}, AI evaluations queued: {$totalevaluations}, Errors: {$totalerrors}");
    }

    /**
     * Queue AI evaluations for submitted submissions that don't have one yet.
     *
     * @param object $redaction The redaction instance
     * @return int Number of evaluations queued
     */
    protected function queue_missing_evaluations($redaction) {
        global $DB;

        // Find submitted submissions with content but no AI evaluation.
        $sql = "SELECT s.*
                FROM {redaction_submission} s
                LEFT JOIN {redaction_ai_evaluations} e ON e.submissionid = s.id
                WHERE s.redactionid = :redactionid
                  AND s.status = 1
                  AND s.contenu IS NOT NULL
                  AND s.contenu != ''
                  AND e.id IS NULL";

        $submissions = $DB->get_records_sql($sql, ['redactionid' => $redaction->id]);

        if (empty($submissions)) {
            return 0;
        }

        mtrace("    Found " . count($submissions) . " submitted submission(s) without AI evaluation");

        $count = 0;
        foreach ($submissions as $submission) {
            try {
                $this->trigger_ai_evaluation($submission);
                $identifier = $submission->groupid ? "group {$submission->groupid}" : "user {$submission->userid}";
                mtrace("      Queued AI evaluation for {$identifier}");
                $count++;
            } catch (\Exception $e) {
                mtrace("      ERROR queueing evaluation: " . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Get the user ID to use for history saved_by field.
     *
     * @param object $submission The submission record
     * @param bool $groupsubmission Whether this is a group submission
     * @return int User ID
     */
    protected function get_savedby_user($submission, $groupsubmission) {
        if ($submission->userid > 0) {
            return $submission->userid;
        }

        if ($groupsubmission && $submission->groupid > 0) {
            // For group submissions, get first group member.
            $members = groups_get_members($submission->groupid, 'u.id', 'u.id ASC');
            if (!empty($members)) {
                return reset($members)->id;
            }
        }

        // Fallback to admin user.
        return 2;
    }

    /**
     * Trigger AI evaluation for the submission.
     *
     * @param object $submission
     */
    protected function trigger_ai_evaluation($submission) {
        global $CFG;

        $evaluatorfile = $CFG->dirroot . '/mod/redaction/classes/ai_evaluator.php';
        if (!file_exists($evaluatorfile)) {
            return;
        }

        require_once($evaluatorfile);

        try {
            \mod_redaction\ai_evaluator::queue_evaluation(
                $submission->redactionid,
                $submission->id,
                $submission->groupid,
                $submission->userid
            );
            mtrace("        AI evaluation queued.");
        } catch (\Exception $e) {
            mtrace("        WARNING: Could not queue AI evaluation: " . $e->getMessage());
        }
    }
}
