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
 * External function: save the answer of the group to one subject.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Saves one answer of the group (autosave of the assigned subjects screen).
 */
class save_sujet_reponse extends sujets_base {

    /** @var int Longest accepted answer, in characters. */
    const MAX_LENGTH = 20000;

    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'sujetid' => new external_value(PARAM_INT, 'Subject ID'),
            'contenu' => new external_value(PARAM_RAW, 'Answer text (plain text)'),
        ]);
    }

    /**
     * Saves the answer, keeps a history version, and refuses a subject that is not the group's.
     *
     * @param int $cmid Course module ID.
     * @param int $sujetid Subject ID.
     * @param string $contenu Answer text.
     * @return array
     */
    public static function execute(int $cmid, int $sujetid, string $contenu): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'sujetid' => $sujetid,
            'contenu' => $contenu,
        ]);

        [$cm, $redaction, $context, $groupid, $submission] = self::contexte($params['cmid']);

        $sujets = \mod_redaction\sujets::de_soumission($redaction, $submission);
        if (!isset($sujets[$params['sujetid']])) {
            throw new \moodle_exception('sujets_hors_groupe', 'mod_redaction');
        }

        // Same lock as the submission: what is being corrected must not move under the corrector's feet,
        // and a save in flight must not meet the attempt count being written by the partner's submit.
        $factory = \core\lock\lock_config::get_lock_factory('mod_redaction');
        $lock = $factory->get_lock('redaction_submit_' . $submission->id, submit_sujets::LOCK_TIMEOUT);
        if (!$lock) {
            return ['success' => false, 'message' => get_string('sujets_verrou', 'mod_redaction')];
        }

        try {
            if (\mod_redaction\ai_evaluator::has_pending_evaluation($submission->id)) {
                return ['success' => false, 'message' => get_string('sujets_ecriture_bloquee', 'mod_redaction')];
            }
            // Request 16: a copy handed in, or past its deadline, is locked; read again under the lock.
            $submission = $DB->get_record('redaction_submission', ['id' => $submission->id], '*', MUST_EXIST);
            if ((int) $submission->status === 1) {
                return ['success' => false, 'message' => get_string('training_error_already_submitted', 'mod_redaction')];
            }
            if (redaction_deadline_passed($redaction, $submission)) {
                return ['success' => false, 'message' => get_string('training_error_deadline_passed', 'mod_redaction')];
            }

            $texte = \core_text::substr(str_replace("\r\n", "\n", $params['contenu']), 0, self::MAX_LENGTH);
            $now = time();
            $ligne = $DB->get_record('redaction_sujet_reponse', [
                'submissionid' => $submission->id,
                'sujetid' => $params['sujetid'],
            ]);
            if ($ligne && (string) $ligne->contenu === $texte) {
                return ['success' => true, 'message' => get_string('ajax:saved', 'mod_redaction')];
            }
            if ($ligne) {
                $ligne->contenu = $texte;
                $ligne->contenuformat = FORMAT_PLAIN;
                $ligne->userid = $USER->id;
                $ligne->timemodified = $now;
                $DB->update_record('redaction_sujet_reponse', $ligne);
            } else {
                $DB->insert_record('redaction_sujet_reponse', (object) [
                    'submissionid' => $submission->id,
                    'sujetid' => $params['sujetid'],
                    'contenu' => $texte,
                    'contenuformat' => FORMAT_PLAIN,
                    'userid' => $USER->id,
                    'timemodified' => $now,
                ]);
            }

            // History: the answers of every subject of the group, author recorded by saved_by.
            $reponses = $DB->get_records_menu('redaction_sujet_reponse', ['submissionid' => $submission->id], '', 'sujetid, contenu');
            $parts = [];
            foreach ($sujets as $sujet) {
                $parts[] = $sujet->titre . "\n" . ($reponses[$sujet->id] ?? '');
            }
            // Only the fields written here: the counters and the status belong to the submission, read fresh.
            $DB->update_record('redaction_submission', (object) [
                'id' => $submission->id,
                'contenu' => implode("\n\n", $parts),
                'contenuformat' => FORMAT_PLAIN,
                'timemodified' => $now,
            ]);
            redaction_save_history($DB->get_record('redaction_submission', ['id' => $submission->id], '*', MUST_EXIST), $USER->id);
        } finally {
            $lock->release();
        }

        return ['success' => true, 'message' => get_string('ajax:saved', 'mod_redaction')];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the answer was saved'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
        ]);
    }
}
