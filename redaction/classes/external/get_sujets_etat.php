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
 * External function: current answers and correction state of the group (light polling of the subjects screen).
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

/**
 * Gives the answers the group has saved and whether a correction is running, so that a page left open
 * next to a partner's shows what the partner writes.
 */
class get_sujets_etat extends sujets_base {

    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    /**
     * Reads the answers of the group of the current user.
     *
     * @param int $cmid Course module ID.
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        [$cm, $redaction, $context, $groupid, $submission] = self::contexte($params['cmid']);

        $reponses = [];
        foreach ($DB->get_records('redaction_sujet_reponse', ['submissionid' => $submission->id], '', 'sujetid, contenu') as $r) {
            $reponses[] = ['sujetid' => (int) $r->sujetid, 'contenu' => (string) $r->contenu];
        }

        return [
            'pending' => \mod_redaction\ai_evaluator::has_pending_evaluation($submission->id),
            'reponses' => $reponses,
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'pending' => new external_value(PARAM_BOOL, 'Whether a correction is running'),
            'reponses' => new external_multiple_structure(new external_single_structure([
                'sujetid' => new external_value(PARAM_INT, 'Subject ID'),
                'contenu' => new external_value(PARAM_RAW, 'Saved answer'),
            ])),
        ]);
    }
}
