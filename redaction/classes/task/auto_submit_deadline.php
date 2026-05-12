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

        // Walk all open drafts; per-draft we compute the effective deadline (taking overrides into account).
        $sql = "SELECT s.id AS submissionid, s.userid, s.groupid, s.contenu,
                       r.id AS redactionid, r.name, r.course, r.group_submission, r.ai_enabled
                  FROM {redaction_submission} s
                  JOIN {redaction} r ON r.id = s.redactionid
                 WHERE s.status = 0";
        $drafts = $DB->get_recordset_sql($sql);

        $totalprocessed = 0;
        $totalerrors = 0;
        $totalevaluations = 0;
        $redactionsseen = [];

        foreach ($drafts as $draft) {
            $redaction = (object) [
                'id' => (int) $draft->redactionid,
                'name' => $draft->name,
                'course' => (int) $draft->course,
                'group_submission' => (int) $draft->group_submission,
                'ai_enabled' => (int) $draft->ai_enabled,
            ];

            $effective = redaction_get_effective_deadline(
                $redaction,
                (int) $draft->userid,
                (int) $draft->groupid
            );

            if (empty($effective) || $effective > $now) {
                continue;
            }

            // Skip empty drafts entirely: avoids ghost submissions in dashboard.
            if (empty(trim(strip_tags($draft->contenu ?? '')))) {
                $identifier = $draft->groupid ? "group {$draft->groupid}" : "user {$draft->userid}";
                mtrace("  Skipped empty draft for {$identifier} on redaction {$redaction->id}");
                continue;
            }

            try {
                $update = (object) [
                    'id' => (int) $draft->submissionid,
                    'status' => 1,
                    'timesubmitted' => $effective,
                    'timemodified' => $now,
                ];
                $DB->update_record('redaction_submission', $update);
                $identifier = $draft->groupid ? "group {$draft->groupid}" : "user {$draft->userid}";
                mtrace("  Auto-submitted {$identifier} on redaction {$redaction->id} (deadline " . date('Y-m-d H:i:s', $effective) . ")");

                if ($redaction->ai_enabled) {
                    $submission = $DB->get_record('redaction_submission', ['id' => (int) $draft->submissionid]);
                    $this->trigger_ai_evaluation($submission);
                    $totalevaluations++;
                }

                $totalprocessed++;
                $redactionsseen[$redaction->id] = $redaction;
            } catch (\Exception $e) {
                mtrace("  ERROR on draft {$draft->submissionid}: " . $e->getMessage());
                $totalerrors++;
            }
        }
        $drafts->close();

        // Catch-up evaluations for any submitted drafts that lack one.
        foreach ($redactionsseen as $r) {
            if (!empty($r->ai_enabled)) {
                $totalevaluations += $this->queue_missing_evaluations($r);
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
