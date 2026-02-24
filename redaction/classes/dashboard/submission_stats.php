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
 * Submission statistics for teacher dashboard.
 *
 * Calculates progress metrics for student submissions.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\dashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Class for calculating submission statistics.
 */
class submission_stats {

    /** @var int The redaction instance ID */
    protected $redactionid;

    /** @var object The redaction instance record */
    protected $redaction;

    /** @var int The course ID */
    protected $courseid;

    /**
     * Constructor.
     *
     * @param int $redactionid The redaction instance ID
     */
    public function __construct(int $redactionid) {
        global $DB;

        $this->redactionid = $redactionid;
        $this->redaction = $DB->get_record('redaction', ['id' => $redactionid], '*', MUST_EXIST);
        $this->courseid = $this->redaction->course;
    }

    /**
     * Get all submission statistics.
     *
     * Results are cached for 5 minutes to reduce database load on the dashboard.
     *
     * @param bool $forcereload If true, bypass cache and recompute stats.
     * @return object Statistics object
     */
    public function get_stats(bool $forcereload = false): object {
        $cache = \cache::make('mod_redaction', 'dashboard_stats');
        $key = 'stats_' . $this->redactionid;

        if (!$forcereload) {
            $cached = $cache->get($key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $stats = $this->compute_stats();
        $cache->set($key, $stats);

        return $stats;
    }

    /**
     * Compute all submission statistics from database.
     *
     * @return object Statistics object
     */
    protected function compute_stats(): object {
        $stats = new \stdClass();

        // Get expected submissions count.
        $stats->total_expected = $this->get_expected_submission_count();

        // Get submission counts.
        $stats->drafts = $this->count_by_status(0);
        $stats->submitted = $this->count_by_status(1);
        $stats->graded = $this->count_graded();
        $stats->not_started = $stats->total_expected - $stats->drafts - $stats->submitted;
        if ($stats->not_started < 0) {
            $stats->not_started = 0;
        }

        // Calculate percentages.
        $total = $stats->total_expected > 0 ? $stats->total_expected : 1;
        $stats->submitted_percent = round(($stats->submitted / $total) * 100);
        $stats->graded_percent = round(($stats->graded / $total) * 100);
        $stats->draft_percent = round(($stats->drafts / $total) * 100);
        $stats->not_started_percent = round(($stats->not_started / $total) * 100);

        // Get grade statistics.
        $gradeStats = $this->get_grade_stats();
        $stats->average_grade = $gradeStats->average;
        $stats->min_grade = $gradeStats->min;
        $stats->max_grade = $gradeStats->max;
        $stats->grade_distribution = $gradeStats->distribution;

        // AI evaluation stats.
        $aiStats = $this->get_ai_evaluation_stats();
        $stats->ai_pending = $aiStats->pending;
        $stats->ai_completed = $aiStats->completed;
        $stats->ai_applied = $aiStats->applied;
        $stats->ai_failed = $aiStats->failed;

        return $stats;
    }

    /**
     * Invalidate the cached dashboard statistics for a redaction instance.
     *
     * Should be called whenever submissions or grades change (e.g. new submission,
     * grading, AI evaluation completion).
     *
     * @param int $redactionid The redaction instance ID
     */
    public static function invalidate_cache(int $redactionid): void {
        $cache = \cache::make('mod_redaction', 'dashboard_stats');
        $cache->delete('stats_' . $redactionid);
    }

    /**
     * Get the expected number of submissions.
     *
     * @return int
     */
    protected function get_expected_submission_count(): int {
        if ($this->redaction->group_submission) {
            // Group mode - count groups.
            $groups = groups_get_all_groups($this->courseid);
            return count($groups);
        } else {
            // Individual mode - count enrolled students with submit capability.
            $context = \context_course::instance($this->courseid);
            $users = get_enrolled_users($context, 'mod/redaction:submit');
            return count($users);
        }
    }

    /**
     * Count submissions by status.
     *
     * @param int $status 0=draft, 1=submitted
     * @return int
     */
    protected function count_by_status(int $status): int {
        global $DB;

        return $DB->count_records('redaction_submission', [
            'redactionid' => $this->redactionid,
            'status' => $status,
        ]);
    }

    /**
     * Count graded submissions.
     *
     * @return int
     */
    protected function count_graded(): int {
        global $DB;

        return $DB->count_records_select(
            'redaction_submission',
            'redactionid = ? AND grade IS NOT NULL',
            [$this->redactionid]
        );
    }

    /**
     * Get grade statistics.
     *
     * @return object
     */
    protected function get_grade_stats(): object {
        global $DB;

        $result = new \stdClass();
        $result->average = null;
        $result->min = null;
        $result->max = null;
        $result->distribution = [
            '0-4' => 0,
            '5-8' => 0,
            '9-12' => 0,
            '13-16' => 0,
            '17-20' => 0,
        ];

        // Get all grades.
        $grades = $DB->get_records_select(
            'redaction_submission',
            'redactionid = ? AND grade IS NOT NULL',
            [$this->redactionid],
            '',
            'grade'
        );

        if (empty($grades)) {
            return $result;
        }

        $gradeValues = array_column($grades, 'grade');
        $result->average = round(array_sum($gradeValues) / count($gradeValues), 2);
        $result->min = min($gradeValues);
        $result->max = max($gradeValues);

        // Calculate distribution.
        foreach ($gradeValues as $grade) {
            $grade = floatval($grade);
            if ($grade < 5) {
                $result->distribution['0-4']++;
            } else if ($grade < 9) {
                $result->distribution['5-8']++;
            } else if ($grade < 13) {
                $result->distribution['9-12']++;
            } else if ($grade < 17) {
                $result->distribution['13-16']++;
            } else {
                $result->distribution['17-20']++;
            }
        }

        return $result;
    }

    /**
     * Get AI evaluation statistics.
     *
     * @return object
     */
    protected function get_ai_evaluation_stats(): object {
        global $DB;

        $result = new \stdClass();
        $result->pending = 0;
        $result->completed = 0;
        $result->applied = 0;
        $result->failed = 0;

        $sql = "SELECT status, COUNT(*) as count
                FROM {redaction_ai_evaluations}
                WHERE redactionid = ?
                GROUP BY status";

        $counts = $DB->get_records_sql($sql, [$this->redactionid]);

        foreach ($counts as $row) {
            switch ($row->status) {
                case 'pending':
                case 'processing':
                    $result->pending += $row->count;
                    break;
                case 'completed':
                    $result->completed = $row->count;
                    break;
                case 'applied':
                    $result->applied = $row->count;
                    break;
                case 'failed':
                    $result->failed = $row->count;
                    break;
            }
        }

        return $result;
    }

    /**
     * Get submission list with details.
     *
     * @param string $filter Filter by status: 'all', 'submitted', 'graded', 'ungraded'
     * @return array
     */
    public function get_submissions_list(string $filter = 'all'): array {
        global $DB;

        $conditions = ['redactionid = ?'];
        $params = [$this->redactionid];

        switch ($filter) {
            case 'submitted':
                $conditions[] = 'status = 1';
                break;
            case 'graded':
                $conditions[] = 'grade IS NOT NULL';
                break;
            case 'ungraded':
                $conditions[] = 'status = 1 AND grade IS NULL';
                break;
        }

        $where = implode(' AND ', $conditions);

        $submissions = $DB->get_records_select(
            'redaction_submission',
            $where,
            $params,
            'timesubmitted DESC'
        );

        // Enrich with user/group info.
        $result = [];
        foreach ($submissions as $sub) {
            $item = new \stdClass();
            $item->id = $sub->id;
            $item->status = $sub->status;
            $item->grade = $sub->grade;
            $item->timesubmitted = $sub->timesubmitted;

            if ($this->redaction->group_submission && $sub->groupid > 0) {
                $group = groups_get_group($sub->groupid);
                $item->name = $group ? $group->name : get_string('unknowngroup', 'mod_redaction');
                $item->type = 'group';
            } else if ($sub->userid > 0) {
                $user = $DB->get_record('user', ['id' => $sub->userid], 'id, firstname, lastname');
                $item->name = $user ? fullname($user) : get_string('unknownuser', 'mod_redaction');
                $item->type = 'user';
            } else {
                $item->name = '-';
                $item->type = 'unknown';
            }

            // Get AI evaluation status.
            $aiEval = $DB->get_record('redaction_ai_evaluations', [
                'submissionid' => $sub->id,
            ], 'status', IGNORE_MULTIPLE);
            $item->ai_status = $aiEval ? $aiEval->status : null;

            $result[] = $item;
        }

        return $result;
    }
}
