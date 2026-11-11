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

namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

/**
 * Balanced and stable attribution of subjects to groups (assigned subjects mode).
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sujets {
    /** @var int Seconds to wait for the attribution lock. */
    const LOCK_TIMEOUT = 10;

    /**
     * Picks $n distinct subjects: the least attributed first, avoiding a pair already given in the class.
     *
     * Pure function: it touches neither Moodle nor the database.
     *
     * @param int[] $idsujets Ids of the available subjects.
     * @param array $attributions Pairs already given in the class, each an array of subject ids.
     * @param int $n Number of subjects wanted.
     * @param callable $hasard Random function with the signature of mt_rand($min, $max).
     * @return int[] The chosen subject ids (all of them if there are fewer than $n).
     */
    public static function choisir(array $idsujets, array $attributions, int $n, callable $hasard): array {
        $idsujets = array_values(array_unique($idsujets));
        if (count($idsujets) <= $n) {
            return $idsujets;
        }

        $compte = array_fill_keys($idsujets, 0);
        $paires = [];
        foreach ($attributions as $paire) {
            foreach ($paire as $id) {
                if (isset($compte[$id])) {
                    $compte[$id]++;
                }
            }
            $paires[] = array_values($paire);
        }

        $choisis = [];
        while (count($choisis) < $n) {
            $candidats = array_values(array_diff($idsujets, $choisis));
            $min = min(array_intersect_key($compte, array_flip($candidats)));
            $moins = array_values(array_filter($candidats, fn($id) => $compte[$id] === $min));

            // Among the least attributed, prefer those that do not repeat a pair already given.
            $neufs = array_values(array_filter($moins, function ($id) use ($choisis, $paires) {
                foreach ($choisis as $deja) {
                    foreach ($paires as $paire) {
                        if (in_array($id, $paire) && in_array($deja, $paire)) {
                            return false;
                        }
                    }
                }
                return true;
            }));
            $pool = $neufs ?: $moins;
            $choisis[] = $pool[$hasard(0, count($pool) - 1)];
        }
        return $choisis;
    }

    /**
     * Class name of a group: "4A-GRP01" gives "4A", any other name is returned whole.
     *
     * @param string $nomgroupe Group name.
     * @return string
     */
    public static function classe_du_groupe(string $nomgroupe): string {
        if (preg_match('/^(.+)-GRP\d+$/', $nomgroupe, $m)) {
            return $m[1];
        }
        return $nomgroupe;
    }

    /**
     * Subjects of a group, in the order of their rank. Reads the attribution, or creates it on first call.
     *
     * Creation is serialised per activity and class by a lock, and the group is read again once the
     * lock is held, so two simultaneous calls give the same two subjects.
     *
     * @param \stdClass $redaction The activity record (id, course).
     * @param int $groupid Group id.
     * @return \stdClass[] Subject records, ordered by rank.
     * @throws \moodle_exception When there is no subject or the lock cannot be obtained.
     */
    public static function du_groupe(\stdClass $redaction, int $groupid): array {
        global $DB;

        $groupe = $DB->get_record('groups', ['id' => $groupid], 'id, courseid, name', MUST_EXIST);
        $classe = self::classe_du_groupe($groupe->name);

        $existants = self::lire($redaction->id, $groupid);
        if ($existants) {
            return $existants;
        }

        $factory = \core\lock\lock_config::get_lock_factory('mod_redaction');
        $lock = $factory->get_lock('redaction_sujets_' . $redaction->id . '_' . $classe, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('sujets_verrou', 'mod_redaction');
        }

        try {
            // Read again: another process may have written while we waited for the lock.
            $existants = self::lire($redaction->id, $groupid);
            if ($existants) {
                return $existants;
            }

            $sujets = $DB->get_records('redaction_sujet', ['redactionid' => $redaction->id], 'sortorder, id');
            if (!$sujets) {
                throw new \moodle_exception('sujets_aucun', 'mod_redaction');
            }

            // Pairs already given to the other groups of the same class.
            $attributions = [];
            foreach ($DB->get_records('groups', ['courseid' => $groupe->courseid], '', 'id, name') as $autre) {
                if ($autre->id == $groupid || self::classe_du_groupe($autre->name) !== $classe) {
                    continue;
                }
                $ids = $DB->get_fieldset_select(
                    'redaction_sujet_groupe',
                    'sujetid',
                    'redactionid = ? AND groupid = ?',
                    [$redaction->id, $autre->id]
                );
                if ($ids) {
                    $attributions[] = array_map('intval', $ids);
                }
            }

            $n = max(1, (int) ($redaction->sujets_par_groupe ?? 2));
            $choisis = self::choisir(array_keys($sujets), $attributions, $n, 'mt_rand');

            $transaction = $DB->start_delegated_transaction();
            try {
                foreach ($choisis as $rang => $sujetid) {
                    $DB->insert_record('redaction_sujet_groupe', (object) [
                        'redactionid' => $redaction->id,
                        'groupid' => $groupid,
                        'sujetid' => $sujetid,
                        'rang' => $rang + 1,
                        'timecreated' => time(),
                    ]);
                }
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        return self::lire($redaction->id, $groupid);
    }

    /**
     * Replaces the subjects of a group (teacher action).
     *
     * The subjects must belong to the activity, be as many as the activity gives to each group and all
     * differ. Grades and evaluations are not touched. Answers of a removed subject stay in the table,
     * so that they reappear if the subject comes back. Serialised with the attribution by the same lock.
     *
     * @param \stdClass $redaction The activity record (id, course, sujets_par_groupe).
     * @param int $groupid Group id.
     * @param int[] $idsujets Ids of the new subjects, in the order of their rank.
     * @throws \moodle_exception When the list is not valid or the lock cannot be obtained.
     */
    public static function changer(\stdClass $redaction, int $groupid, array $idsujets): void {
        global $DB;

        $idsujets = array_values(array_map('intval', $idsujets));
        $n = max(1, (int) ($redaction->sujets_par_groupe ?? 2));
        if (count($idsujets) !== $n || count(array_unique($idsujets)) !== $n) {
            throw new \moodle_exception('sujets_changer_invalide', 'mod_redaction', '', $n);
        }
        [$insql, $params] = $DB->get_in_or_equal($idsujets, SQL_PARAMS_NAMED);
        $params['redactionid'] = $redaction->id;
        if ($DB->count_records_select('redaction_sujet', "redactionid = :redactionid AND id $insql", $params) !== $n) {
            throw new \moodle_exception('sujets_changer_invalide', 'mod_redaction', '', $n);
        }

        // The group must belong to the course of the activity.
        $groupe = $DB->get_record('groups', ['id' => $groupid, 'courseid' => $redaction->course], 'id, name', MUST_EXIST);
        $classe = self::classe_du_groupe($groupe->name);

        $factory = \core\lock\lock_config::get_lock_factory('mod_redaction');
        $lock = $factory->get_lock('redaction_sujets_' . $redaction->id . '_' . $classe, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('sujets_verrou', 'mod_redaction');
        }

        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $DB->delete_records('redaction_sujet_groupe', ['redactionid' => $redaction->id, 'groupid' => $groupid]);
                foreach ($idsujets as $rang => $sujetid) {
                    $DB->insert_record('redaction_sujet_groupe', (object) [
                        'redactionid' => $redaction->id,
                        'groupid' => $groupid,
                        'sujetid' => $sujetid,
                        'rang' => $rang + 1,
                        'timecreated' => time(),
                    ]);
                }
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }

    /**
     * Sets or removes the teacher grade of a submission (teacher action).
     *
     * The teacher grade wins over the AI until it is removed (null). The grade of the submission is then
     * worked out again, the gradebook updated, and timemodified bumped: the class life tool polls it.
     *
     * @param \stdClass $redaction The activity record.
     * @param \stdClass $submission The submission record.
     * @param float|null $note Grade out of 20, null to remove the teacher grade.
     * @param string|null $commentaire Teacher comment, replaced when given (null leaves it as it is).
     * @throws \moodle_exception When the grade is outside 0-20.
     */
    public static function noter(\stdClass $redaction, \stdClass $submission, ?float $note, ?string $commentaire = null): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/redaction/lib.php');

        if ($note !== null && ($note < 0 || $note > notes_sujets::NOTE_MAX)) {
            throw new \moodle_exception('grade_invalid', 'mod_redaction');
        }

        // Only the evaluations graded with the current language setting count.
        $evaluations = $DB->get_fieldset_select(
            'redaction_ai_evaluations',
            'parsed_grade',
            "submissionid = ? AND status IN ('completed', 'applied') AND langue_points = ?",
            [$submission->id, notes_sujets::reglage_langue($redaction)]
        );

        $submission->grade_enseignant = $note;
        $submission->grade = notes_sujets::meilleure($evaluations, $note);
        if ($commentaire !== null) {
            // The form shows the current comment: an empty one clears it.
            $submission->feedback = trim($commentaire);
        }
        $submission->timemodified = time();

        // The grade of the submission and the gradebook go together.
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record('redaction_submission', $submission);
            $resultat = redaction_update_grades($redaction);

            // A locked grade item is the teacher's choice, not a failure.
            if (in_array($resultat, [GRADE_UPDATE_FAILED, GRADE_UPDATE_MULTIPLE], true)) {
                throw new \moodle_exception('sujets_carnet_echec', 'mod_redaction');
            }
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        $transaction->allow_commit();
    }

    /**
     * Carries out a teacher action of the grading screen on one group.
     *
     * The caller has checked the session key and the capability. A refusal comes back as an error
     * message, never as an exception, so that the screen can show it.
     *
     * @param \stdClass $redaction The activity record.
     * @param \context_module $context Module context.
     * @param int $groupid Group id.
     * @param string $action note (set the teacher grade), retirer (remove it) or systemes (change the subjects).
     * @param array $donnees grade (float), feedback (string), sujetid (int[]), confirme (bool).
     * @return array [message, notification type]
     */
    public static function traiter_action(\stdClass $redaction, \context_module $context, int $groupid, string $action, array $donnees): array {
        global $DB, $USER;

        $submission = $DB->get_record('redaction_submission', ['redactionid' => $redaction->id, 'groupid' => $groupid, 'userid' => 0]);

        try {
            if ($action === 'note' || $action === 'retirer') {
                if (!$submission) {
                    throw new \moodle_exception('sujets_ens_pas_de_travail', 'mod_redaction');
                }
                $ancienne = $submission->grade_enseignant;
                $note = $action === 'note' ? (float) $donnees['grade'] : null;
                self::noter($redaction, $submission, $note, $action === 'note' ? ($donnees['feedback'] ?? '') : null);

                \mod_redaction\event\grade_updated::create([
                    'objectid' => $submission->id,
                    'context' => $context,
                    'userid' => $USER->id,
                    'other' => ['oldgrade' => $ancienne ?? 'none', 'newgrade' => $note ?? 'none'],
                ])->trigger();
                $message = get_string($action === 'note' ? 'grade_saved' : 'sujets_ens_note_retiree', 'mod_redaction');
            } else if ($action === 'systemes') {
                $adecrit = $submission && $DB->record_exists_select(
                    'redaction_sujet_reponse',
                    "submissionid = ? AND contenu IS NOT NULL AND contenu <> ''",
                    [$submission->id]
                );
                if ($adecrit && empty($donnees['confirme'])) {
                    throw new \moodle_exception('sujets_ens_changer_a_confirmer', 'mod_redaction');
                }
                self::changer($redaction, $groupid, $donnees['sujetid'] ?? []);
                $message = get_string('sujets_ens_changes', 'mod_redaction');
            } else {
                throw new \moodle_exception('sujets_ens_action_invalide', 'mod_redaction');
            }
        } catch (\moodle_exception $e) {
            return [$e->getMessage(), \core\output\notification::NOTIFY_ERROR];
        }

        return [$message, \core\output\notification::NOTIFY_SUCCESS];
    }

    /**
     * Subjects already attributed to a group, ordered by rank. Never creates an attribution.
     *
     * @param int $redactionid Activity id.
     * @param int $groupid Group id.
     * @return \stdClass[] Subject records (empty if none attributed yet).
     */
    public static function lire(int $redactionid, int $groupid): array {
        global $DB;

        return $DB->get_records_sql(
            'SELECT s.*
               FROM {redaction_sujet_groupe} sg
               JOIN {redaction_sujet} s ON s.id = sg.sujetid
              WHERE sg.redactionid = :redactionid AND sg.groupid = :groupid
           ORDER BY sg.rang, sg.id',
            ['redactionid' => $redactionid, 'groupid' => $groupid]
        );
    }
}
