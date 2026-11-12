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
 * Balanced and stable attribution of subjects to groups, or to students when the activity is individual
 * (assigned subjects mode).
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
     * Whether the subjects go to each student rather than to each group: the activity is not a group submission.
     *
     * @param \stdClass $redaction The activity record.
     * @return bool
     */
    public static function individuel(\stdClass $redaction): bool {
        return !empty($redaction->sujets_enabled) && empty($redaction->group_submission);
    }

    /**
     * Class of a student, from the names of the groups the student belongs to in the course.
     *
     * The class part of a work group ("4A-GRP03" gives "4A") first, then a group whose name starts with a digit
     * ("5C"), then the first name in alphabetical order. No group gives an empty string: the whole activity.
     * Pure function: it touches neither Moodle nor the database.
     *
     * @param string[] $noms Names of the groups of the student.
     * @return string
     */
    public static function classe_eleve(array $noms): string {
        $noms = array_values(array_unique(array_map('strval', $noms)));
        sort($noms, SORT_STRING);
        foreach ($noms as $nom) {
            if (preg_match('/^(.+)-GRP\d+$/', $nom, $m)) {
                return $m[1];
            }
        }
        foreach ($noms as $nom) {
            if (preg_match('/^\d/', $nom)) {
                return $nom;
            }
        }
        return $noms[0] ?? '';
    }

    /**
     * Subjects of a submission: of its student when the activity is individual, of its group otherwise.
     *
     * @param \stdClass $redaction The activity record.
     * @param \stdClass $submission The submission record (groupid, userid).
     * @return \stdClass[] Subject records, ordered by rank.
     * @throws \moodle_exception When there is no subject or the lock cannot be obtained.
     */
    public static function de_soumission(\stdClass $redaction, \stdClass $submission): array {
        if (self::individuel($redaction)) {
            return self::de_eleve($redaction, (int) $submission->userid);
        }
        return self::du_groupe($redaction, (int) $submission->groupid);
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

        // Subjects already given to the other groups of the same class.
        $autres = function () use ($DB, $redaction, $groupe, $classe): array {
            $attributions = [];
            foreach ($DB->get_records('groups', ['courseid' => $groupe->courseid], '', 'id, name') as $autre) {
                if ($autre->id == $groupe->id || self::classe_du_groupe($autre->name) !== $classe) {
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
            return $attributions;
        };

        return self::attribuer($redaction, $groupid, 0, 'redaction_sujets_' . $redaction->id . '_' . $classe, $autres);
    }

    /**
     * Subjects of a student in an individual activity, in the order of their rank. Reads the attribution,
     * or creates it on first call, balanced among the students of the same class.
     *
     * @param \stdClass $redaction The activity record (id, course).
     * @param int $userid User id.
     * @return \stdClass[] Subject records, ordered by rank.
     * @throws \moodle_exception When there is no subject or the lock cannot be obtained.
     */
    public static function de_eleve(\stdClass $redaction, int $userid): array {
        global $DB;

        $classes = self::classes_des_eleves((int) $redaction->course);
        $classe = $classes[$userid] ?? '';

        // Subjects already given to the other students of the same class.
        $autres = function () use ($DB, $redaction, $userid, $classes, $classe): array {
            $parEleve = [];
            foreach ($DB->get_records('redaction_sujet_eleve', ['redactionid' => $redaction->id], '', 'id, userid, sujetid') as $ligne) {
                if ((int) $ligne->userid !== $userid && ($classes[(int) $ligne->userid] ?? '') === $classe) {
                    $parEleve[(int) $ligne->userid][] = (int) $ligne->sujetid;
                }
            }
            return array_values($parEleve);
        };

        return self::attribuer($redaction, 0, $userid, 'redaction_sujets_eleve_' . $redaction->id . '_' . $classe, $autres);
    }

    /**
     * Class of each student of a course who belongs to a group, keyed by user id (see classe_eleve()).
     *
     * @param int $courseid Course id.
     * @return string[]
     */
    public static function classes_des_eleves(int $courseid): array {
        global $DB;

        $noms = [];
        $lignes = $DB->get_recordset_sql(
            'SELECT gm.id, gm.userid, g.name
               FROM {groups_members} gm
               JOIN {groups} g ON g.id = gm.groupid
              WHERE g.courseid = ?',
            [$courseid]
        );
        foreach ($lignes as $ligne) {
            $noms[(int) $ligne->userid][] = $ligne->name;
        }
        $lignes->close();

        return array_map([self::class, 'classe_eleve'], $noms);
    }

    /**
     * Reads the attribution of a group or a student, or creates it on first call.
     *
     * Creation is serialised per activity and class by a lock, and the attribution is read again once the
     * lock is held, so two simultaneous calls give the same subjects.
     *
     * @param \stdClass $redaction The activity record.
     * @param int $groupid Group id, 0 for a student.
     * @param int $userid User id, 0 for a group.
     * @param string $verrou Name of the lock of the class.
     * @param callable $autres Returns the subjects already given in the class, one list per group or student.
     * @return \stdClass[] Subject records, ordered by rank.
     * @throws \moodle_exception When there is no subject or the lock cannot be obtained.
     */
    protected static function attribuer(\stdClass $redaction, int $groupid, int $userid, string $verrou, callable $autres): array {
        global $DB;

        $existants = self::lire($redaction->id, $groupid, $userid);
        if ($existants) {
            return $existants;
        }

        $factory = \core\lock\lock_config::get_lock_factory('mod_redaction');
        $lock = $factory->get_lock($verrou, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('sujets_verrou', 'mod_redaction');
        }

        try {
            // Read again: another process may have written while we waited for the lock.
            $existants = self::lire($redaction->id, $groupid, $userid);
            if ($existants) {
                return $existants;
            }

            $sujets = $DB->get_records('redaction_sujet', ['redactionid' => $redaction->id], 'sortorder, id');
            if (!$sujets) {
                throw new \moodle_exception('sujets_aucun', 'mod_redaction');
            }

            $n = max(1, (int) ($redaction->sujets_par_groupe ?? 2));
            $choisis = self::choisir(array_keys($sujets), $autres(), $n, 'mt_rand');
            self::ecrire($redaction->id, $groupid, $userid, $choisis);
        } finally {
            $lock->release();
        }

        return self::lire($redaction->id, $groupid, $userid);
    }

    /**
     * Replaces the attribution of a group or a student, all or nothing.
     *
     * @param int $redactionid Activity id.
     * @param int $groupid Group id, 0 for a student.
     * @param int $userid User id, 0 for a group.
     * @param int[] $idsujets Subject ids, in the order of their rank.
     */
    protected static function ecrire(int $redactionid, int $groupid, int $userid, array $idsujets): void {
        global $DB;

        [$table, $champ, $id] = $userid ? ['redaction_sujet_eleve', 'userid', $userid] : ['redaction_sujet_groupe', 'groupid', $groupid];
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records($table, ['redactionid' => $redactionid, $champ => $id]);
            foreach (array_values($idsujets) as $rang => $sujetid) {
                $DB->insert_record($table, (object) [
                    'redactionid' => $redactionid,
                    $champ => $id,
                    'sujetid' => $sujetid,
                    'rang' => $rang + 1,
                    'timecreated' => time(),
                ]);
            }
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        $transaction->allow_commit();
    }

    /**
     * Replaces the subjects of a group, or of a student when the activity is individual (teacher action).
     *
     * The subjects must belong to the activity, be as many as the activity gives to each group and all
     * differ. Grades and evaluations are not touched. Answers of a removed subject stay in the table,
     * so that they reappear if the subject comes back. Serialised with the attribution by the same lock.
     *
     * @param \stdClass $redaction The activity record (id, course, sujets_par_groupe).
     * @param int $groupid Group id, 0 for a student.
     * @param int[] $idsujets Ids of the new subjects, in the order of their rank.
     * @param int $userid User id of the student, 0 for a group.
     * @throws \moodle_exception When the list is not valid or the lock cannot be obtained.
     */
    public static function changer(\stdClass $redaction, int $groupid, array $idsujets, int $userid = 0): void {
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

        if ($userid) {
            // The student must be enrolled in the course of the activity.
            if (!is_enrolled(\context_course::instance($redaction->course), $userid)) {
                throw new \moodle_exception('sujets_ens_eleve_invalide', 'mod_redaction');
            }
            $classes = self::classes_des_eleves((int) $redaction->course);
            $verrou = 'redaction_sujets_eleve_' . $redaction->id . '_' . ($classes[$userid] ?? '');
            $groupid = 0;
        } else {
            // The group must belong to the course of the activity.
            $groupe = $DB->get_record('groups', ['id' => $groupid, 'courseid' => $redaction->course], 'id, name', MUST_EXIST);
            $verrou = 'redaction_sujets_' . $redaction->id . '_' . self::classe_du_groupe($groupe->name);
        }

        $factory = \core\lock\lock_config::get_lock_factory('mod_redaction');
        $lock = $factory->get_lock($verrou, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('sujets_verrou', 'mod_redaction');
        }

        try {
            self::ecrire($redaction->id, $groupid, $userid, $idsujets);
        } finally {
            $lock->release();
        }
    }

    /**
     * Grade of the latest successful final correction of a submission, null when there is none (request 16).
     *
     * It does not depend on the current language setting: a final grade never vanishes when the setting changes.
     *
     * @param int $submissionid Submission id.
     * @return float|null
     */
    public static function note_finale_de(int $submissionid): ?float {
        global $DB;
        $finales = $DB->get_records_select('redaction_ai_evaluations',
            "submissionid = ? AND finale = 1 AND status IN ('completed', 'applied') AND parsed_grade IS NOT NULL",
            [$submissionid], 'id DESC', 'id, parsed_grade', 0, 1);
        return $finales ? (float) reset($finales)->parsed_grade : null;
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

        // Request 16: the final correction, or the teacher grade which wins.
        $submission->grade_enseignant = $note;
        $submission->grade = notes_sujets::note_finale(self::note_finale_de((int) $submission->id), $note);
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
     * Carries out a teacher action of the grading screen on one group, or one student when the activity is individual.
     *
     * The caller has checked the session key and the capability. A refusal comes back as an error
     * message, never as an exception, so that the screen can show it.
     *
     * @param \stdClass $redaction The activity record.
     * @param \context_module $context Module context.
     * @param int $groupid Group id, 0 for a student.
     * @param string $action note (set the teacher grade), retirer (remove it) or systemes (change the subjects).
     * @param array $donnees grade (float), feedback (string), sujetid (int[]), confirme (bool).
     * @param int $userid User id of the student, 0 for a group.
     * @return array [message, notification type]
     */
    public static function traiter_action(\stdClass $redaction, \context_module $context, int $groupid, string $action, array $donnees,
            int $userid = 0): array {
        global $DB, $USER;

        $submission = $DB->get_record('redaction_submission', $userid
            ? ['redactionid' => $redaction->id, 'groupid' => 0, 'userid' => $userid]
            : ['redactionid' => $redaction->id, 'groupid' => $groupid, 'userid' => 0]);

        try {
            if ($action === 'note' || $action === 'retirer') {
                if (!$submission) {
                    throw new \moodle_exception('sujets_ens_pas_de_travail' . ($userid ? '_eleve' : ''), 'mod_redaction');
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
            } else if ($action === 'rouvrir') {
                self::rouvrir($redaction, $submission, $donnees['date'] ?? null, $groupid, $userid);
                $message = get_string('sujets_ens_rouverte', 'mod_redaction');
            } else if ($action === 'relancer') {
                if (!$submission || (int) $submission->status !== 1 || \mod_redaction\output\grading_sujets::etat($submission,
                        $DB->get_records('redaction_ai_evaluations', ['submissionid' => $submission->id], '', 'id, status, finale')) !== 'soumise'
                        || !$DB->record_exists('redaction_ai_evaluations', ['submissionid' => $submission->id, 'finale' => 1])) {
                    throw new \moodle_exception('sujets_ens_relancer_impossible', 'mod_redaction');
                }
                ai_evaluator::queue_evaluation((int) $redaction->id, (int) $submission->id, (int) $submission->groupid,
                    (int) $submission->userid, true, true);
                $message = get_string('sujets_ens_relancee', 'mod_redaction');
            } else if ($action === 'systemes') {
                $adecrit = $submission && $DB->record_exists_select(
                    'redaction_sujet_reponse',
                    "submissionid = ? AND contenu IS NOT NULL AND contenu <> ''",
                    [$submission->id]
                );
                if ($adecrit && empty($donnees['confirme'])) {
                    throw new \moodle_exception('sujets_ens_changer_a_confirmer' . ($userid ? '_eleve' : ''), 'mod_redaction');
                }
                self::changer($redaction, $groupid, $donnees['sujetid'] ?? [], $userid);
                $message = get_string('sujets_ens_changes' . ($userid ? '_eleve' : ''), 'mod_redaction');
            } else {
                throw new \moodle_exception('sujets_ens_action_invalide', 'mod_redaction');
            }
        } catch (\moodle_exception $e) {
            return [$e->getMessage(), \core\output\notification::NOTIFY_ERROR];
        }

        return [$message, \core\output\notification::NOTIFY_SUCCESS];
    }

    /**
     * Reopens a copy handed in (teacher action, request 16): draft again, its final grade out of the gradebook until
     * the next final submission; the attempts and the history stay. Past the deadline a new date is required, kept as an
     * override of the student or the group, else the deadline task would hand the copy in again within minutes.
     *
     * @param \stdClass $redaction
     * @param \stdClass|false $submission
     * @param int|null $date New deadline (timestamp), required when the deadline is passed.
     * @param int $groupid Group of the copy, 0 for a student.
     * @param int $userid Student of the copy, 0 for a group.
     * @throws \moodle_exception When there is no copy, or a date is missing or in the past.
     */
    public static function rouvrir(\stdClass $redaction, $submission, ?int $date, int $groupid, int $userid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/redaction/lib.php');

        if (!$submission) {
            throw new \moodle_exception('sujets_ens_pas_de_travail' . ($userid ? '_eleve' : ''), 'mod_redaction');
        }
        if ($date !== null && $date <= time()) {
            throw new \moodle_exception('sujets_ens_rouvrir_date', 'mod_redaction');
        }
        if ($date === null && redaction_deadline_passed($redaction, $submission)) {
            throw new \moodle_exception('sujets_ens_rouvrir_date', 'mod_redaction');
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if ($date !== null) {
                $cle = $userid ? ['redactionid' => $redaction->id, 'userid' => $userid] : ['redactionid' => $redaction->id, 'groupid' => $groupid];
                $override = $DB->get_record('redaction_overrides', $cle);
                if ($override) {
                    $DB->update_record('redaction_overrides', (object) ['id' => $override->id, 'deadline_date' => $date, 'timemodified' => time()]);
                } else {
                    $DB->insert_record('redaction_overrides', (object) ($cle + ['deadline_date' => $date, 'sortorder' => 0,
                        'timecreated' => time(), 'timemodified' => time()]));
                }
            }
            $DB->update_record('redaction_submission', (object) [
                'id' => $submission->id, 'status' => 0, 'timesubmitted' => 0, 'timemodified' => time(),
                'grade' => $submission->grade_enseignant,
            ]);
            // Its finals no longer grade it: the next final submission will.
            $DB->set_field('redaction_ai_evaluations', 'finale', 0, ['submissionid' => $submission->id, 'finale' => 1]);
            redaction_update_grades($redaction);
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        $transaction->allow_commit();
    }

    /**
     * Subjects already attributed to a group or a student, ordered by rank. Never creates an attribution.
     *
     * @param int $redactionid Activity id.
     * @param int $groupid Group id, 0 for a student.
     * @param int $userid User id of the student, 0 for a group.
     * @return \stdClass[] Subject records (empty if none attributed yet).
     */
    public static function lire(int $redactionid, int $groupid, int $userid = 0): array {
        global $DB;

        [$table, $champ, $id] = $userid ? ['redaction_sujet_eleve', 'userid', $userid] : ['redaction_sujet_groupe', 'groupid', $groupid];
        return $DB->get_records_sql(
            "SELECT s.*
               FROM {{$table}} sg
               JOIN {redaction_sujet} s ON s.id = sg.sujetid
              WHERE sg.redactionid = :redactionid AND sg.$champ = :id
           ORDER BY sg.rang, sg.id",
            ['redactionid' => $redactionid, 'id' => $id]
        );
    }
}
