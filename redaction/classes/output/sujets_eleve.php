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
 * Renderable + templatable data of the student screen of the assigned subjects mode.
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

/**
 * Everything the student screen shows: the same state for every member of the group.
 *
 * The expected answer (corrige) is never read here: only the statement is exported.
 */
class sujets_eleve implements renderable, templatable {

    /** @var \stdClass */
    protected $redaction;
    /** @var \stdClass */
    protected $cm;
    /** @var \context_module */
    protected $context;
    /** @var \stdClass */
    protected $submission;
    /** @var int */
    protected $groupid;

    /**
     * Constructor.
     *
     * @param \stdClass $redaction Activity record.
     * @param \stdClass $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $submission Submission of the group.
     * @param int $groupid Group id.
     */
    public function __construct(\stdClass $redaction, \stdClass $cm, \context_module $context, \stdClass $submission, int $groupid) {
        $this->redaction = $redaction;
        $this->cm = $cm;
        $this->context = $context;
        $this->submission = $submission;
        $this->groupid = $groupid;
    }

    /**
     * Exports the data for the template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output): array {
        global $DB, $USER;

        $redaction = $this->redaction;
        $submission = $this->submission;

        $sujets = \mod_redaction\sujets::de_soumission($redaction, $submission);
        $reponses = $DB->get_records_menu('redaction_sujet_reponse', ['submissionid' => $submission->id], '', 'sujetid, contenu');

        $check = redaction_can_submit_attempt($redaction, $submission);
        // The attempts check comes before the pending one: on the last attempt the reason is the maximum.
        $pending = $check['reason'] === 'evaluation_pending' || \mod_redaction\ai_evaluator::has_pending_evaluation($submission->id);
        // The latest by id: two evaluations can share the same second.
        $dernier = $DB->get_records('redaction_ai_evaluations', ['submissionid' => $submission->id], 'id DESC', 'id, status', 0, 1);
        $echec = $dernier && reset($dernier)->status === 'failed';

        $seuil = (float) $redaction->sujets_seuil;
        // The grade is the current user's own: a member who joined later has none, whatever the group got.
        $mesnotes = redaction_get_user_grades_sujets($redaction, $USER->id);
        $note = isset($mesnotes[$USER->id]) && $mesnotes[$USER->id]->rawgrade !== null ? (float) $mesnotes[$USER->id]->rawgrade : null;
        $valide = $note !== null && $note >= $seuil;

        // The comment of the teacher is shown while the teacher grade stands.
        $commentaireprof = $submission->grade_enseignant !== null ? trim((string) $submission->feedback) : '';

        $maxeffective = redaction_effective_max_attempts($redaction);
        $restants = max(0, $maxeffective - (int) $submission->training_count);

        // Request 16: state of the copy, its deadline, the final version.
        $toutes = $DB->get_records('redaction_ai_evaluations', ['submissionid' => $submission->id], 'id DESC', 'id, status, finale');
        $etat = grading_sujets::etat($submission, $toutes);
        $rendue = (int) $submission->status === 1;
        $datelimite = redaction_get_effective_deadline($redaction, (int) $submission->userid, (int) $submission->groupid);
        $tard = redaction_deadline_passed($redaction, $submission);
        $finalepossible = !$rendue && !$tard;

        $items = [];
        $numero = 0;
        foreach ($sujets as $sujet) {
            $numero++;
            $items[] = [
                'id' => (int) $sujet->id,
                'number' => $numero,
                'titre' => format_string($sujet->titre),
                'contenu' => format_text($sujet->contenu ?? '', FORMAT_HTML, ['context' => $this->context]),
                'reponse' => (string) ($reponses[$sujet->id] ?? ''),
                'readonly' => $pending || $rendue || $tard,
            ];
        }

        $groupe = $DB->get_field('groups', 'name', ['id' => $this->groupid]);
        $phrases = (int) ($redaction->sujets_phrases ?? 0);

        // Comments of the last counted correction, one per subject, on the scale left to the content.
        $commentaires = [];
        $langue = null;
        $detailtentative = '';
        $empeche = false;
        $derniere = $DB->get_records_select(
            'redaction_ai_evaluations',
            'submissionid = ? AND status = ?',
            [$submission->id, 'applied'],
            'timecreated DESC, id DESC',
            'id, sujets_json, parsed_grade, langue_json, langue_points, finale',
            0,
            1
        );
        $indicatif = null;
        // The feedback window (17/11/2026): what works, what is missing, what to do next, by subject.
        $va = [];
        $manque = [];
        $prochain = [];
        $pistes = [];
        $attendustrouves = 0;
        $attendustotal = 0;
        $compteur = true;
        if ($derniere) {
            $derniere = reset($derniere);
            $p = (float) ($derniere->langue_points ?? 0);
            $notes = [];
            foreach ((array) json_decode((string) $derniere->sujets_json, true) as $detail) {
                if (isset($detail['note'])) {
                    $notes[] = (float) $detail['note'];
                }
                // Request 16: the three parts; a correction made before them has its single comment in "juste".
                $juste = (string) ($detail['juste'] ?? ($detail['commentaire'] ?? ''));
                $apreciser = (string) ($detail['a_preciser'] ?? '');
                $piste = (string) ($detail['piste'] ?? '');
                $titre = format_string($detail['titre'] ?? '');
                if ($juste !== '') {
                    $va[] = ['titre' => $titre, 'texte' => $juste];
                }
                if (!empty($detail['vide'])) {
                    $manque[] = ['titre' => $titre, 'texte' => get_string('sujets_se_vide', 'mod_redaction')];
                } else if ($apreciser !== '') {
                    $manque[] = ['titre' => $titre, 'texte' => $apreciser];
                }
                if ($piste !== '') {
                    $prochain[] = ['titre' => $titre, 'texte' => $piste];
                    $pistes[(int) ($detail['sujetid'] ?? 0)] = $piste;
                }
                // Counted, never named; a correction made before the count was kept has no counter.
                if (isset($detail['attendus'], $detail['attendus_total']) && is_array($detail['attendus'])) {
                    $attendustrouves += min(count($detail['attendus']), (int) $detail['attendus_total']);
                    $attendustotal += (int) $detail['attendus_total'];
                } else {
                    $compteur = false;
                }
                $commentaires[] = [
                    'titre' => $titre,
                    'hasnote' => isset($detail['note']),
                    'note' => isset($detail['note'])
                        ? format_float(\mod_redaction\notes_sujets::sur_echelle((float) $detail['note'], $p), 1, true, true) : '',
                    'sur' => format_float(\mod_redaction\notes_sujets::NOTE_MAX - $p, 1, true, true),
                    'commentaire' => (string) ($detail['commentaire'] ?? ''),
                    'vide' => !empty($detail['vide']),
                    'hasjuste' => $juste !== '',
                    'juste' => $juste,
                    'hasapreciser' => $apreciser !== '',
                    'a_preciser' => $apreciser,
                    'haspiste' => $piste !== '',
                    'piste' => $piste,
                ];
            }
            $langue = langue_bloc::donnees($derniere->langue_json, false);
            if ($langue !== null && $derniere->parsed_grade !== null) {
                $points = (float) (json_decode($derniere->langue_json, true)['points'] ?? 0);
                $total = (float) $derniere->parsed_grade;
                $detailtentative = get_string('sujets_tentative_detail', 'mod_redaction', (object) [
                    'fond' => format_float($total - $points, 1, true, true),
                    'surfond' => format_float(\mod_redaction\notes_sujets::NOTE_MAX - $p, 1, true, true),
                    'langue' => format_float($points, 1, true, true),
                    'surlangue' => format_float($p, 1, true, true),
                    'total' => format_float($total, 1, true, true),
                ]);
                $empeche = \mod_redaction\notes_sujets::juste_sauf_langue($notes, $total, $seuil);
            }
            // Before the final version, the grade of the last feedback is only an indication.
            if (empty($derniere->finale) && $derniere->parsed_grade !== null) {
                $indicatif = (float) $derniere->parsed_grade;
            }
        }

        // The button stays usable on an empty answer: the server refuses it with a message.
        $bloque = !$check['allowed'] && $check['reason'] !== 'empty_answer';
        $raison = '';
        if ($bloque && !$pending) {
            $raison = get_string('training_error_' . $check['reason'], 'mod_redaction');
        }

        // Handed in, its final not graded yet (running, or failed and waiting for a retry): the final version is being
        // corrected; nothing about an attempt to submit again, no indication from an attempt (final review).
        $enattente = $rendue && $note === null && array_filter($toutes, fn($e) => !empty($e->finale));
        if ($enattente) {
            $echec = false;
            $indicatif = null;
        }

        // Proposal C (17/11/2026): the steps write, feedback, final version; the final locked until a feedback came back.
        $retourspossibles = redaction_retours_possibles($redaction);
        $verrou = $finalepossible && $retourspossibles && !redaction_retour_recu($submission);
        $aecrit = (bool) array_filter($reponses, fn($contenu) => trim(strip_tags((string) $contenu)) !== '');
        $atteint = $indicatif !== null && $indicatif >= $seuil;
        if ($rendue) {
            $etape = 4;
        } else if (!$aecrit) {
            $etape = 1;
        } else if ($retourspossibles && !$atteint && $restants > 0) {
            $etape = 2;
        } else {
            $etape = 3;
        }
        $etapes = [];
        foreach (['ecrire', 'retour', 'finale'] as $i => $cle) {
            $numero = $i + 1;
            $etapes[] = [
                'numero' => $numero,
                'libelle' => get_string('sujets_se_etape_' . $cle, 'mod_redaction'),
                'fait' => $numero < $etape,
                'encours' => $numero === $etape,
                'avenir' => $numero > $etape,
            ];
        }
        $resultat = $this->resultat($toutes, $derniere, [
            'pending' => $pending || $enattente, 'indicatif' => $indicatif, 'atteint' => $atteint, 'note' => $note,
            'valide' => $valide, 'seuil' => $seuil, 'redaction' => $redaction, 'numero' => (int) $submission->training_count,
            'va' => $va, 'manque' => $manque, 'prochain' => $prochain, 'empeche' => $empeche, 'langue' => $langue,
            'compteur' => $compteur && $attendustotal > 0, 'trouves' => $attendustrouves, 'total' => $attendustotal,
            'canameliorer' => !$rendue && !$tard && $restants > 0, 'restants' => $restants,
            'canremettre' => $finalepossible && !$verrou,
        ]);
        // Idea 1: the lead of each subject stays under its answer while the copy can change.
        $avecpiste = !$rendue && !$tard && $derniere && empty($derniere->finale);
        foreach ($items as $k => $item) {
            $piste = $avecpiste ? ($pistes[$item['id']] ?? '') : '';
            $items[$k]['haspiste'] = $piste !== '';
            $items[$k]['piste'] = $piste;
        }

        $utilises = min((int) $submission->training_count, $maxeffective);
        $pastilles = array_map(fn($i) => ['utilise' => $i < $utilises], range(0, $maxeffective - 1));

        return [
            'cmid' => (int) $this->cm->id,
            'submissionid' => (int) $submission->id,
            'groupname' => $groupe === false ? '' : format_string($groupe),
            'hasphrases' => $phrases > 0,
            'phrases' => $phrases,
            'items' => $items,
            'valide' => $valide,
            'seuil' => format_float($seuil, 0, true, true),
            'niveau' => \mod_redaction\notes_sujets::niveau($redaction),
            'hasnote' => $note !== null,
            'note' => $note !== null ? format_float($note, 1, true, true) : '',
            'restants' => $restants,
            'pending' => $pending,
            'estimation' => \mod_redaction\ai_evaluator::duree_estimee((int) $redaction->id),
            'ecoule' => $pending ? \mod_redaction\ai_evaluator::attente_ecoulee((int) $submission->id) : 0,
            'echec' => (bool) $echec && !$pending,
            'disabled' => $bloque,
            'raison' => $raison,
            'hascommentaireprof' => $commentaireprof !== '',
            'commentaireprof' => $commentaireprof,
            'hascommentaires' => !empty($commentaires),
            'commentaires' => $commentaires,
            'haslangue' => $langue !== null,
            'langue' => $langue,
            'hasdetailtentative' => $detailtentative !== '',
            'detailtentative' => $detailtentative,
            'empeche' => $empeche,
            // Request 16: the progress panel.
            'etat' => $etat,
            'etatlibelle' => get_string('sujets_se_etat_' . ($enattente ? 'correction' : $etat), 'mod_redaction'),
            'hasdatelimite' => !empty($datelimite),
            'datelimite' => !empty($datelimite) ? userdate($datelimite, get_string('strftimedatetimeshort', 'langconfig')) : '',
            'tard' => $tard && !$rendue,
            'rendue' => $rendue,
            'dateremise' => $rendue && $submission->timesubmitted ? userdate((int) $submission->timesubmitted, get_string('strftimedatetimeshort', 'langconfig')) : '',
            'hasindicatif' => $note === null && $indicatif !== null,
            'indicatif' => $indicatif !== null ? format_float($indicatif, 1, true, true) : '',
            'atteint' => $atteint,
            'numeroretour' => (int) $submission->training_count,
            'max' => $maxeffective,
            'canessai' => !$rendue && !$tard,
            'canfinale' => $finalepossible,
            'finaledisabled' => !$finalepossible || $pending || $verrou,
            'etape' => $etape,
            'etapes' => $etapes,
            'pastilles' => $pastilles,
            'utilises' => $utilises,
            'retourplein' => $etape <= 2,
            'finaleplein' => $etape === 3 && !$verrou,
            'verrou' => $verrou,
            'enfile' => $pending && $toutes && reset($toutes)->status === 'pending',
            'hasresultat' => $resultat !== null,
            'resultat' => $resultat,
            // Idea 2: the threshold is reached on the last feedback and the copy can be handed in.
            'proposerremise' => $atteint && $finalepossible && !$verrou && !$pending && $note === null,
            'bandeauremise' => $atteint ? get_string('sujets_bandeau_seuil', 'mod_redaction', format_float($indicatif, 1, true, true)) : '',
        ];
    }

    /**
     * What the feedback window shows about the latest correction, null while one runs or before any.
     *
     * @param array $toutes Evaluations of the copy, latest first: id, status, finale.
     * @param \stdClass|array $derniere Latest applied evaluation (empty when none).
     * @param array $d What export_for_template computed: pending, indicatif, atteint, note, valide, seuil, redaction,
     *                 numero, va, manque, prochain, empeche, langue, compteur, trouves, total, canameliorer, restants,
     *                 canremettre.
     * @return array|null
     */
    protected function resultat(array $toutes, $derniere, array $d): ?array {
        $plusrecente = $toutes ? reset($toutes) : null;
        if (!$plusrecente || $d['pending']) {
            return null;
        }
        $f = fn(float $x) => format_float($x, 1, true, true);
        $base = [
            'evaluationid' => (int) $plusrecente->id,
            'estretour' => false, 'estseuil' => false, 'estfinale' => false, 'estechec' => false, 'ok' => false,
            'ligne' => '', 'sousligne' => '',
            'hasva' => !empty($d['va']), 'va' => $d['va'],
            'hasmanque' => !empty($d['manque']), 'manque' => $d['manque'],
            'hasprochain' => !empty($d['prochain']), 'prochain' => $d['prochain'],
            'empeche' => $d['empeche'],
            'hascompteur' => $d['compteur'],
            'compteur' => $d['compteur'] ? get_string('sujets_res_trouves', 'mod_redaction',
                (object) ['trouves' => $d['trouves'], 'total' => $d['total']]) : '',
            'cases' => $d['compteur'] ? array_map(fn($i) => ['trouve' => $i < $d['trouves']], range(0, $d['total'] - 1)) : [],
            'hasfautes' => !empty($d['langue']['fautes']),
            'fautes' => $d['langue']['fautes'] ?? [],
            'hasprogresser' => !empty($d['langue']['progresser']),
            'progresser' => $d['langue']['progresser'] ?? [],
            'canameliorer' => false, 'ameliorer' => '', 'canremettre' => false,
        ];

        if ($plusrecente->status === 'failed' && empty($plusrecente->finale)) {
            return ['estechec' => true, 'titre' => get_string('sujets_res_echec_titre', 'mod_redaction'),
                'ligne' => get_string('sujets_res_echec', 'mod_redaction'),
                'hasva' => false, 'hasmanque' => false, 'hasprochain' => false, 'hascompteur' => false,
                'hasfautes' => false, 'hasprogresser' => false, 'empeche' => false] + $base;
        }
        if ($plusrecente->status !== 'applied' || !$derniere || (int) $derniere->id !== (int) $plusrecente->id) {
            return null;
        }
        $niveau = \mod_redaction\notes_sujets::niveau($d['redaction']);
        if (!empty($derniere->finale)) {
            if ($d['note'] === null) {
                return null;
            }
            return ['estfinale' => true, 'ok' => $d['valide'],
                'titre' => get_string('sujets_res_finale_titre', 'mod_redaction'),
                'ligne' => get_string('sujets_se_note', 'mod_redaction', $f($d['note'])),
                'sousligne' => get_string($d['valide'] ? 'sujets_valide' : 'sujets_pas_valide', 'mod_redaction', $niveau)] + $base;
        }
        if ($d['indicatif'] === null) {
            return null;
        }
        $titre = get_string('sujets_se_retour_titre', 'mod_redaction', $d['numero']);
        if ($d['atteint']) {
            return ['estseuil' => true, 'ok' => true, 'titre' => $titre,
                'ligne' => get_string('sujets_res_seuil', 'mod_redaction', $f($d['indicatif'])),
                'sousligne' => get_string('sujets_se_atteint', 'mod_redaction', $niveau),
                'canameliorer' => $d['canameliorer'],
                'ameliorer' => get_string('sujets_res_ameliorer', 'mod_redaction', $d['restants']),
                'canremettre' => $d['canremettre']] + $base;
        }
        $ecart = max(0.1, round($d['seuil'] - $d['indicatif'], 1));
        return ['estretour' => true, 'titre' => $titre,
            'ligne' => get_string('sujets_res_note_ko', 'mod_redaction',
                (object) ['note' => $f($d['indicatif']), 'seuil' => format_float($d['seuil'], 0, true, true)]),
            'sousligne' => get_string($ecart < 2 ? 'sujets_res_encore_un' : 'sujets_res_encore', 'mod_redaction', $f($ecart))] + $base;
    }
}
