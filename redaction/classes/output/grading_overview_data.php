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
 * Renderable + templatable data for the grading progression overview table.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use renderer_base;
use templatable;
use moodle_url;

class grading_overview_data implements renderable, templatable {

    /** @var int */
    protected $cmid;
    /** @var int */
    protected $redactionid;
    /** @var int */
    protected $courseid;
    /** @var int 0 = no filter */
    protected $groupid;
    /** @var int */
    protected $maxattempts;

    public function __construct(int $cmid, int $redactionid, int $courseid, int $groupid, int $maxattempts) {
        $this->cmid = $cmid;
        $this->redactionid = $redactionid;
        $this->courseid = $courseid;
        $this->groupid = $groupid;
        $this->maxattempts = $maxattempts;
    }

    public function export_for_template(renderer_base $output): array {
        global $DB;

        $redaction = $DB->get_record('redaction', ['id' => $this->redactionid], '*', MUST_EXIST);
        $isgroupmode = (bool) $redaction->group_submission;

        $headers = [];
        for ($i = 1; $i <= $this->maxattempts; $i++) {
            $headers[] = ['label' => get_string('overview_attempt_header', 'redaction', $i)];
        }

        $rows = $isgroupmode ? $this->build_group_rows() : $this->build_student_rows();

        return [
            'headers' => $headers,
            'rows' => $rows,
            'has_rows' => !empty($rows),
            'isgroupmode' => $isgroupmode,
            'cmid' => $this->cmid,
            'maxattempts' => $this->maxattempts,
        ];
    }

    /**
     * One row per student in the activity (filtered by group).
     *
     * @return array
     */
    protected function build_student_rows(): array {
        global $DB;
        require_once($GLOBALS['CFG']->dirroot . '/mod/redaction/lib.php');

        $coursecontext = \context_course::instance($this->courseid);
        $users = get_enrolled_users(
            $coursecontext,
            'mod/redaction:submit',
            $this->groupid,
            'u.id, u.firstname, u.lastname',
            'u.lastname, u.firstname'
        );

        if (empty($users)) {
            return [];
        }

        $userids = array_keys($users);
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_QM);

        // Fetch every submission's id for these users in this redaction.
        $sql = 'SELECT id, userid FROM {redaction_submission}
                 WHERE redactionid = ? AND groupid = 0 AND userid ' . $insql;
        $submissions = $DB->get_records_sql($sql, array_merge([$this->redactionid], $inparams));
        $submissionByUser = [];
        $submissionIds = [];
        foreach ($submissions as $s) {
            $submissionByUser[$s->userid] = $s->id;
            $submissionIds[] = $s->id;
        }

        // Fetch all relevant evaluations in one query.
        $evalsBySubmission = $this->load_evaluations_by_submission($submissionIds);

        $rows = [];
        foreach ($users as $user) {
            $sid = $submissionByUser[$user->id] ?? null;
            $evals = ($sid && isset($evalsBySubmission[$sid])) ? $evalsBySubmission[$sid] : [];
            $rows[] = [
                'name' => fullname($user),
                'cells' => $this->build_cells($evals, $sid),
            ];
        }
        return $rows;
    }

    /**
     * One row per group when group_submission=true.
     *
     * @return array
     */
    protected function build_group_rows(): array {
        global $DB;

        $groups = ($this->groupid > 0)
            ? [$this->groupid => $DB->get_record('groups', ['id' => $this->groupid], '*', MUST_EXIST)]
            : groups_get_all_groups($this->courseid);

        if (empty($groups)) {
            return [];
        }

        $groupids = array_keys($groups);
        [$insql, $inparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_QM);

        $sql = 'SELECT id, groupid FROM {redaction_submission}
                 WHERE redactionid = ? AND userid = 0 AND groupid ' . $insql;
        $submissions = $DB->get_records_sql($sql, array_merge([$this->redactionid], $inparams));
        $submissionByGroup = [];
        $submissionIds = [];
        foreach ($submissions as $s) {
            $submissionByGroup[$s->groupid] = $s->id;
            $submissionIds[] = $s->id;
        }

        $evalsBySubmission = $this->load_evaluations_by_submission($submissionIds);

        $rows = [];
        foreach ($groups as $group) {
            $sid = $submissionByGroup[$group->id] ?? null;
            $evals = ($sid && isset($evalsBySubmission[$sid])) ? $evalsBySubmission[$sid] : [];
            $rows[] = [
                'name' => format_string($group->name),
                'cells' => $this->build_cells($evals, $sid),
            ];
        }
        return $rows;
    }

    /**
     * Load evaluations for the given submission IDs grouped by submissionid,
     * ordered chronologically (oldest first).
     *
     * @param array $submissionIds
     * @return array map [submissionid => evaluation[]]
     */
    protected function load_evaluations_by_submission(array $submissionIds): array {
        global $DB;
        if (empty($submissionIds)) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($submissionIds, SQL_PARAMS_QM);
        $sql = 'SELECT id, submissionid, status, parsed_grade, criteria_json, timecreated
                  FROM {redaction_ai_evaluations}
                 WHERE submissionid ' . $insql . '
              ORDER BY submissionid, timecreated ASC, id ASC';
        $rows = $DB->get_records_sql($sql, $inparams);
        $map = [];
        foreach ($rows as $r) {
            $map[$r->submissionid][] = $r;
        }
        return $map;
    }

    /**
     * Build $maxattempts cells for one row.
     *
     * Returns an array of cell descriptors. Empty cells are filled with hasattempt=false.
     * The last non-empty cell is flagged is_latest=true and embeds criteria mini-bars.
     *
     * @param array $evals chronological evaluations
     * @param int|null $submissionId
     * @return array cells
     */
    protected function build_cells(array $evals, ?int $submissionId): array {
        $cells = [];
        $latestIndex = -1;
        for ($i = 0; $i < count($evals); $i++) {
            $latestIndex = $i;
        }

        for ($i = 0; $i < $this->maxattempts; $i++) {
            if (!isset($evals[$i])) {
                $cells[] = ['hasattempt' => false];
                continue;
            }
            $eval = $evals[$i];
            $isLatest = ($i === $latestIndex);
            $cells[] = $this->build_cell($eval, $isLatest, $submissionId);
        }
        return $cells;
    }

    /**
     * Build a single cell descriptor.
     *
     * @param object $eval
     * @param bool $isLatest
     * @param int|null $submissionId
     * @return array
     */
    protected function build_cell(object $eval, bool $isLatest, ?int $submissionId): array {
        $grade = $eval->parsed_grade !== null ? (float) $eval->parsed_grade : null;
        $level = $this->level_for_grade($grade);
        $statusicon = $this->status_icon($eval->status);

        $detailurl = '';
        if ($submissionId !== null) {
            $detailurl = (new moodle_url('/mod/redaction/view.php', [
                'id' => $this->cmid,
                'page' => 'grading',
                'tab' => 'detail',
                'itemid' => $submissionId,
            ]))->out(false);
        }

        $cell = [
            'hasattempt' => true,
            'grade' => $grade !== null ? number_format($grade, 1) : '—',
            'levelclass' => $level,
            'statusicon' => $statusicon,
            'detailurl' => $detailurl,
            'is_latest' => $isLatest,
        ];

        if ($isLatest && !empty($eval->criteria_json)) {
            $cell['criteria'] = $this->parse_criteria_minibar($eval->criteria_json);
            $cell['has_criteria'] = !empty($cell['criteria']);
        } else {
            $cell['criteria'] = [];
            $cell['has_criteria'] = false;
        }
        return $cell;
    }

    /**
     * Map a grade /20 to a level class.
     */
    protected function level_for_grade(?float $grade): string {
        if ($grade === null) {
            return 'unknown';
        }
        $percent = ($grade / 20) * 100;
        if ($percent >= 80) {
            return 'excellent';
        }
        if ($percent >= 65) {
            return 'good';
        }
        if ($percent >= 50) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Map an evaluation status to an emoji icon and an aria label.
     */
    protected function status_icon(string $status): string {
        switch ($status) {
            case 'completed': return '&#x2705;';
            case 'applied': return '&#x2713;';
            case 'pending':
            case 'processing': return '&#x23F3;';
            case 'failed': return '&#x26A0;&#xFE0F;';
            default: return '';
        }
    }

    /**
     * Parse criteria_json to mini-bar descriptors.
     */
    protected function parse_criteria_minibar(string $json): array {
        $parsed = json_decode($json, true);
        if (!is_array($parsed)) {
            return [];
        }
        $bars = [];
        foreach ($parsed as $crit) {
            $score = isset($crit['score']) ? (float) $crit['score'] : 0;
            $max = isset($crit['max']) && $crit['max'] > 0 ? (float) $crit['max'] : 5;
            $percent = max(0, min(100, ($score / $max) * 100));
            $level = $this->level_for_grade($score / $max * 20);
            $bars[] = [
                'name' => $crit['name'] ?? '',
                'score' => number_format($score, 1),
                'max' => number_format($max, 0),
                'percentage' => round($percent),
                'levelclass' => $level,
            ];
        }
        return $bars;
    }
}
