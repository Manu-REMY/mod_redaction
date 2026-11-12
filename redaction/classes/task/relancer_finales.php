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
 * Scheduled task that retries the failed final corrections (assigned subjects mode).
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\task;

/**
 * Retries the failed final corrections of the assigned subjects mode (request 16).
 *
 * A copy handed in must get its grade: each run queues a new final correction (a new row, so the history of the
 * failures stays readable, 429 refusals included) for every locked copy whose finals have all failed, up to
 * RELANCES_MAX. An error that will not go away by itself is not retried: the teacher sees an alert and can retry.
 */
class relancer_finales extends \core\task\scheduled_task {

    /** @var int Failed finals after which a copy is no longer retried by itself. */
    const RELANCES_MAX = 12;

    /** @var string[] Language keys of the errors a retry cannot fix. */
    const ERREURS_DEFINITIVES = ['sujets_corrige_vide', 'sujets_aucun'];

    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_relancer_finales', 'mod_redaction');
    }

    /**
     * Whether an error message is one a retry cannot fix.
     *
     * @param string|null $message Error kept on the evaluation.
     * @return bool
     */
    public static function definitive(?string $message): bool {
        foreach (self::ERREURS_DEFINITIVES as $cle) {
            if ((string) $message === get_string($cle, 'mod_redaction')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Queues a new final correction for each locked copy whose finals have all failed, within the limits.
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/redaction/lib.php');

        $copies = $DB->get_records_sql(
            "SELECT DISTINCT s.id, s.redactionid, s.groupid, s.userid
               FROM {redaction_submission} s
               JOIN {redaction} r ON r.id = s.redactionid AND r.sujets_enabled = 1
               JOIN {redaction_ai_evaluations} e ON e.submissionid = s.id AND e.finale = 1
              WHERE s.status = 1"
        );
        $relancees = 0;
        foreach ($copies as $copie) {
            $finales = $DB->get_records('redaction_ai_evaluations', ['submissionid' => $copie->id, 'finale' => 1], 'id DESC',
                'id, status, error_message');
            $echecs = array_filter($finales, fn($f) => $f->status === 'failed');
            if (count($echecs) !== count($finales) || count($echecs) >= self::RELANCES_MAX) {
                continue;
            }
            if (self::definitive(reset($finales)->error_message)) {
                continue;
            }
            \mod_redaction\ai_evaluator::queue_evaluation((int) $copie->redactionid, (int) $copie->id, (int) $copie->groupid,
                (int) $copie->userid, true, true);
            $relancees++;
        }
        mtrace("Failed final corrections retried: {$relancees}");
    }
}
