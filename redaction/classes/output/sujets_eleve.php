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
                'readonly' => $pending,
            ];
        }

        $groupe = $DB->get_field('groups', 'name', ['id' => $this->groupid]);

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
            'id, sujets_json, parsed_grade, langue_json, langue_points',
            0,
            1
        );
        if ($derniere) {
            $derniere = reset($derniere);
            $p = (float) ($derniere->langue_points ?? 0);
            $notes = [];
            foreach ((array) json_decode((string) $derniere->sujets_json, true) as $detail) {
                if (isset($detail['note'])) {
                    $notes[] = (float) $detail['note'];
                }
                $commentaires[] = [
                    'titre' => format_string($detail['titre'] ?? ''),
                    'hasnote' => isset($detail['note']),
                    'note' => isset($detail['note'])
                        ? format_float(\mod_redaction\notes_sujets::sur_echelle((float) $detail['note'], $p), 1, true, true) : '',
                    'sur' => format_float(\mod_redaction\notes_sujets::NOTE_MAX - $p, 1, true, true),
                    'commentaire' => (string) ($detail['commentaire'] ?? ''),
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
        }

        // The button stays usable on an empty answer: the server refuses it with a message.
        $bloque = !$check['allowed'] && $check['reason'] !== 'empty_answer';
        $raison = '';
        if ($bloque && !$pending) {
            $raison = get_string('training_error_' . $check['reason'], 'mod_redaction');
        }

        return [
            'cmid' => (int) $this->cm->id,
            'submissionid' => (int) $submission->id,
            'groupname' => $groupe === false ? '' : format_string($groupe),
            'items' => $items,
            'valide' => $valide,
            'seuil' => format_float($seuil, 0, true, true),
            'hasnote' => $note !== null,
            'note' => $note !== null ? format_float($note, 1, true, true) : '',
            'restants' => $restants,
            'pending' => $pending,
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
        ];
    }
}
