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
 * Teacher screens of the assigned subjects mode: overview of the groups, detail of one group.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\output;

defined('MOODLE_INTERNAL') || die();

use mod_redaction\notes_sujets;
use mod_redaction\sujets;
use renderable;
use renderer_base;
use templatable;

/**
 * Data of the teacher screens. With no group it is the overview (key groupes), with a group its detail.
 * In an individual activity the rows and the detail are those of the students.
 *
 * Student texts are plain text and are only ever exported as such: the templates escape them.
 */
class grading_sujets implements renderable, templatable {

    /** @var int Seconds after which a correction still pending is flagged. */
    const ATTENTE_LONGUE = 600;

    /** @var \stdClass */
    protected $redaction;
    /** @var \stdClass */
    protected $cm;
    /** @var \context_module */
    protected $context;
    /** @var int 0 for the overview. */
    protected $groupid;
    /** @var int Student of the detail in an individual activity, 0 for the overview. */
    protected $userid;

    /**
     * Constructor.
     *
     * @param \stdClass $redaction Activity record.
     * @param \stdClass $cm Course module.
     * @param \context_module $context Module context.
     * @param int $groupid Group id, 0 for the overview.
     * @param int $userid Student id in an individual activity, 0 for the overview.
     */
    public function __construct(\stdClass $redaction, \stdClass $cm, \context_module $context, int $groupid = 0, int $userid = 0) {
        $this->redaction = $redaction;
        $this->cm = $cm;
        $this->context = $context;
        $this->groupid = $groupid;
        $this->userid = $userid;
    }

    /**
     * Exports the data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        if (sujets::individuel($this->redaction)) {
            return $this->userid ? $this->detail() : $this->apercu();
        }
        return $this->groupid ? $this->detail() : $this->apercu();
    }

    /**
     * URL of the grading page.
     *
     * @param int $groupid Group id, 0 for the overview.
     * @param int $userid Student id in an individual activity, 0 for the overview.
     * @return \moodle_url
     */
    public static function url(\stdClass $cm, int $groupid = 0, int $userid = 0): \moodle_url {
        $params = ['id' => $cm->id, 'page' => 'grading'];
        if ($groupid) {
            $params['groupid'] = $groupid;
        }
        if ($userid) {
            $params['userid'] = $userid;
        }
        return new \moodle_url('/mod/redaction/view.php', $params);
    }

    /**
     * Submission of a group or a student, null if never opened.
     *
     * @param int $groupid Group id, 0 for a student.
     * @param int $userid Student id, 0 for a group.
     * @return \stdClass|null
     */
    protected function soumission(int $groupid, int $userid = 0): ?\stdClass {
        global $DB;
        $submission = $DB->get_record('redaction_submission', [
            'redactionid' => $this->redaction->id, 'groupid' => $userid ? 0 : $groupid, 'userid' => $userid,
        ]);
        return $submission ?: null;
    }

    /**
     * Students who can submit, sorted by class then name, keyed by user id.
     *
     * @return \stdClass[] Records with the user fields and classe.
     */
    protected function eleves(): array {
        $classes = sujets::classes_des_eleves((int) $this->redaction->course);
        $eleves = get_enrolled_users($this->context, 'mod/redaction:submit', 0, 'u.*', 'u.lastname, u.firstname');
        foreach ($eleves as $eleve) {
            $eleve->classe = $classes[(int) $eleve->id] ?? '';
        }
        // Stable sort: within a class the order of the names stays.
        uasort($eleves, fn($a, $b) => strnatcasecmp($a->classe, $b->classe));
        return $eleves;
    }

    /**
     * Name of a student shown to the teacher, with the class.
     *
     * @param \stdClass $eleve Record from eleves().
     * @return string
     */
    protected function nom_eleve(\stdClass $eleve): string {
        return $eleve->classe === '' ? fullname($eleve) : fullname($eleve) . ' (' . format_string($eleve->classe) . ')';
    }

    /**
     * What goes wrong in a list of evaluations (newest first): the last one failed, or one pending for too long.
     *
     * @param \stdClass[] $evaluations Evaluations, newest first.
     * @return string Empty when nothing is wrong.
     */
    protected function alerte(array $evaluations): string {
        $messages = [];
        // The latest settled evaluation decides: a correction still running must not hide the failure before it.
        foreach ($evaluations as $evaluation) {
            if (in_array($evaluation->status, ['pending', 'processing'], true)) {
                continue;
            }
            if ($evaluation->status === 'failed') {
                $messages[] = get_string('sujets_ens_alerte_echec', 'mod_redaction');
            }
            break;
        }
        foreach ($evaluations as $evaluation) {
            if (in_array($evaluation->status, ['pending', 'processing'], true)
                    && time() - $evaluation->timecreated > self::ATTENTE_LONGUE) {
                $messages[] = get_string('sujets_ens_alerte_attente', 'mod_redaction', format_time(time() - $evaluation->timecreated));
                break;
            }
        }
        return implode(' ', $messages);
    }

    /**
     * Evaluations of a submission, newest first.
     *
     * @param int $submissionid Submission id.
     * @return \stdClass[]
     */
    protected function evaluations(int $submissionid): array {
        global $DB;
        return $DB->get_records('redaction_ai_evaluations', ['submissionid' => $submissionid], 'timecreated DESC, id DESC');
    }

    /**
     * A grade written for display.
     *
     * @param float|string $note Grade out of 20.
     * @return string
     */
    protected function note($note): string {
        return format_float((float) $note, 1, true, true);
    }

    /**
     * Overview: one row per group.
     *
     * @return array
     */
    protected function apercu(): array {
        global $DB;

        $redaction = $this->redaction;
        $seuil = (float) $redaction->sujets_seuil;
        $max = redaction_effective_max_attempts($redaction);

        // Three queries for the whole list, not three per group.
        $individuel = sujets::individuel($redaction);
        $soumissions = [];
        $conditions = $individuel ? ['redactionid' => $redaction->id, 'groupid' => 0] : ['redactionid' => $redaction->id, 'userid' => 0];
        foreach ($DB->get_records('redaction_submission', $conditions) as $s) {
            $soumissions[$individuel ? $s->userid : $s->groupid] = $s;
        }
        $evaluations = [];
        if ($soumissions) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_column($soumissions, 'id'));
            foreach ($DB->get_records_select('redaction_ai_evaluations', "submissionid $insql", $inparams, 'timecreated DESC, id DESC',
                    'id, submissionid, status, timecreated') as $e) {
                $evaluations[$e->submissionid][] = $e;
            }
        }
        $titres = [];
        [$table, $champ] = $individuel ? ['redaction_sujet_eleve', 'userid'] : ['redaction_sujet_groupe', 'groupid'];
        foreach ($DB->get_records_sql(
            "SELECT sg.id, sg.$champ AS cible, s.titre
               FROM {{$table}} sg
               JOIN {redaction_sujet} s ON s.id = sg.sujetid
              WHERE sg.redactionid = ?
           ORDER BY sg.rang, sg.id",
            [$redaction->id]
        ) as $t) {
            $titres[$t->cible][] = format_string($t->titre);
        }

        // One row per student in an individual activity, per group otherwise.
        $cibles = [];
        if ($individuel) {
            foreach ($this->eleves() as $eleve) {
                $cibles[] = ['id' => (int) $eleve->id, 'nom' => $this->nom_eleve($eleve), 'url' => self::url($this->cm, 0, $eleve->id)];
            }
        } else {
            foreach (redaction_get_grading_filter_groups($this->cm, $redaction->course) as $groupe) {
                $cibles[] = ['id' => (int) $groupe->id, 'nom' => format_string($groupe->name), 'url' => self::url($this->cm, $groupe->id)];
            }
        }

        $lignes = [];
        foreach ($cibles as $cible) {
            $attribues = $titres[$cible['id']] ?? [];
            $submission = $soumissions[$cible['id']] ?? null;
            $note = $submission ? $submission->grade : null;
            $alerte = $submission ? $this->alerte($evaluations[$submission->id] ?? []) : '';
            $lignes[] = [
                'groupid' => $cible['id'],
                'nom' => $cible['nom'],
                'url' => $cible['url']->out(false),
                'hassystemes' => !empty($attribues),
                'systemes' => implode(' / ', $attribues),
                'used' => $submission ? (int) $submission->training_count : 0,
                'max' => $max,
                'hasnote' => $note !== null,
                'note' => $note !== null ? $this->note($note) : '',
                'prof' => $submission && $submission->grade_enseignant !== null,
                'valide' => $note !== null && (float) $note >= $seuil,
                'pasvalide' => $note !== null && (float) $note < $seuil,
                'aucunenote' => $note === null,
                'hasalerte' => $alerte !== '',
                'alerte' => $alerte,
            ];
        }

        return [
            'cmid' => (int) $this->cm->id,
            'seuil' => $this->note($seuil),
            'individuel' => $individuel,
            'groupes' => $lignes,
            'hasgroupes' => !empty($lignes),
        ];
    }

    /**
     * Detail of one group, or of one student in an individual activity.
     *
     * @return array
     */
    protected function detail(): array {
        global $DB;

        $redaction = $this->redaction;
        $individuel = sujets::individuel($redaction);
        if ($individuel) {
            $eleves = $this->eleves();
            if (!isset($eleves[$this->userid])) {
                throw new \moodle_exception('sujets_ens_eleve_invalide', 'mod_redaction');
            }
            $nom = $this->nom_eleve($eleves[$this->userid]);
            $attribues = sujets::lire($redaction->id, 0, $this->userid);
            $submission = $this->soumission(0, $this->userid);
            $action = self::url($this->cm, 0, $this->userid);
        } else {
            $groupe = $DB->get_record('groups', ['id' => $this->groupid, 'courseid' => $redaction->course], '*', MUST_EXIST);
            $nom = format_string($groupe->name);
            $attribues = sujets::lire($redaction->id, $groupe->id);
            $submission = $this->soumission($groupe->id);
            $action = self::url($this->cm, $groupe->id);
        }
        $seuil = (float) $redaction->sujets_seuil;

        $reponses = $submission
            ? $DB->get_records('redaction_sujet_reponse', ['submissionid' => $submission->id], '', 'sujetid, contenu, contenuformat')
            : [];

        $systemes = [];
        $adecrit = false;
        foreach ($attribues as $sujet) {
            $texte = isset($reponses[$sujet->id])
                ? notes_sujets::texte_reponse($reponses[$sujet->id]->contenu, (int) $reponses[$sujet->id]->contenuformat)
                : '';
            $adecrit = $adecrit || $texte !== '';
            $systemes[] = [
                'numero' => count($systemes) + 1,
                'titre' => format_string($sujet->titre),
                'contenu' => format_text($sujet->contenu ?? '', FORMAT_HTML, ['context' => $this->context]),
                'corrige' => notes_sujets::texte_reponse($sujet->corrige, notes_sujets::FORMAT_TEXTE),
                'hasreponse' => $texte !== '',
                'reponse' => $texte,
            ];
        }

        $evaluations = $submission ? $this->evaluations($submission->id) : [];
        // Only the attempts that count get a number: a failed correction gave its attempt back, and a correction
        // graded with another language setting no longer counts.
        $reglage = notes_sujets::reglage_langue($redaction);
        $compte = fn($e) => $e->status !== 'failed' && (in_array($e->status, ['pending', 'processing'], true)
            || notes_sujets::compte($e->langue_points ?? null, $reglage));
        $numero = count(array_filter($evaluations, $compte));
        // The text each correction was given: the last saved version at the time it was queued.
        $versions = $submission
            ? array_values($DB->get_records('redaction_history', ['submissionid' => $submission->id], 'timecreated, id', 'id, contenu, timecreated'))
            : [];
        $liste = [];
        foreach ($evaluations as $evaluation) {
            $enattente = in_array($evaluation->status, ['pending', 'processing'], true);
            $age = time() - $evaluation->timecreated;
            // Subjects on the scale left to the content when the language was assessed.
            $p = (float) ($evaluation->langue_points ?? 0);
            $details = [];
            foreach ((array) json_decode((string) $evaluation->sujets_json, true) as $detail) {
                $details[] = [
                    'titre' => format_string($detail['titre'] ?? ''),
                    'hasnote' => isset($detail['note']),
                    'note' => isset($detail['note']) ? $this->note(notes_sujets::sur_echelle((float) $detail['note'], $p)) : '',
                    'sur' => $this->note(notes_sujets::NOTE_MAX - $p),
                    'commentaire' => (string) ($detail['commentaire'] ?? ''),
                ];
            }
            $langue = langue_bloc::donnees($evaluation->langue_json ?? null, true);
            $texte = '';
            foreach ($versions as $version) {
                if ((int) $version->timecreated <= (int) $evaluation->timecreated) {
                    $texte = (string) $version->contenu;
                }
            }
            $liste[] = [
                'hastexte' => trim($texte) !== '',
                'texte' => $texte,
                'id' => (int) $evaluation->id,
                'hasnumero' => $compte($evaluation),
                'numero' => $compte($evaluation) ? $numero-- : 0,
                'necompteplus' => $evaluation->status !== 'failed' && !$compte($evaluation),
                'date' => userdate($evaluation->timecreated),
                'statut' => get_string('sujets_ens_statut_' . ($evaluation->status === 'failed' ? 'failed'
                    : ($enattente ? 'pending' : 'done')), 'mod_redaction'),
                'ispending' => $enattente,
                'isfailed' => $evaluation->status === 'failed',
                'age' => $enattente ? format_time($age) : '',
                'longue' => $enattente && $age > self::ATTENTE_LONGUE,
                'hasnote' => $evaluation->parsed_grade !== null,
                'note' => $evaluation->parsed_grade !== null ? $this->note($evaluation->parsed_grade) : '',
                'error' => (string) $evaluation->error_message,
                'haserror' => !empty($evaluation->error_message),
                'hasdetails' => !empty($details),
                'details' => $details,
                'haslangue' => $langue !== null,
                'langue' => $langue,
            ];
        }

        // All the subjects of the activity, to choose the new ones.
        $tous = $DB->get_records('redaction_sujet', ['redactionid' => $redaction->id], 'sortorder, id', 'id, titre');
        $choix = [];
        $n = max(1, (int) $redaction->sujets_par_groupe);
        $courants = array_values(array_map(fn($s) => (int) $s->id, $attribues));
        for ($rang = 1; $rang <= $n; $rang++) {
            $options = [];
            foreach ($tous as $sujet) {
                $options[] = [
                    'id' => (int) $sujet->id,
                    'titre' => format_string($sujet->titre),
                    'selected' => ($courants[$rang - 1] ?? 0) === (int) $sujet->id,
                ];
            }
            $choix[] = ['rang' => $rang, 'options' => $options];
        }

        $membres = [];
        if (!$individuel) {
            foreach (groups_get_members($groupe->id, 'u.*', 'u.lastname, u.firstname') as $membre) {
                $membres[] = ['nom' => fullname($membre)];
            }
        }

        $note = $submission ? $submission->grade : null;
        $alerte = $this->alerte($evaluations);

        return [
            'cmid' => (int) $this->cm->id,
            'groupid' => $individuel ? 0 : (int) $groupe->id,
            'individuel' => $individuel,
            'nom' => $nom,
            'retour' => self::url($this->cm)->out(false),
            'action' => $action->out(false),
            'sesskey' => sesskey(),
            'membres' => $membres,
            'hasmembres' => !empty($membres),
            'hassystemes' => !empty($systemes),
            'systemes' => $systemes,
            'hassubmission' => $submission !== null,
            'used' => $submission ? (int) $submission->training_count : 0,
            'max' => redaction_effective_max_attempts($redaction),
            'hasnote' => $note !== null,
            'note' => $note !== null ? $this->note($note) : '',
            'seuil' => $this->note($seuil),
            'valide' => $note !== null && (float) $note >= $seuil,
            'pasvalide' => $note !== null && (float) $note < $seuil,
            'aucunenote' => $note === null,
            'prof' => $submission && $submission->grade_enseignant !== null,
            // Not localised: a number input shows nothing for "12,5".
            'noteprof' => $submission && $submission->grade_enseignant !== null ? format_float((float) $submission->grade_enseignant, 2, false, true) : '',
            'commentaireprof' => $submission && $submission->grade_enseignant !== null ? trim((string) $submission->feedback) : '',
            'hasalerte' => $alerte !== '',
            'alerte' => $alerte,
            'hasevaluations' => !empty($liste),
            'evaluations' => $liste,
            'choix' => $choix,
            'confirmer' => $adecrit,
        ];
    }
}
