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

namespace mod_redaction\output;

defined('MOODLE_INTERNAL') || die();

use mod_redaction\langue;

/**
 * Context of the template langue_bloc: the language assessment of one correction, for the student or the teacher.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class langue_bloc {

    /**
     * Context of the template, from the assessment stored on an evaluation.
     *
     * Every value comes from the student's text or from the plugin: quotes are escaped by the template.
     *
     * @param string|null $languejson The langue_json field of an evaluation.
     * @param bool $pourenseignant Whether to show how many quotes of the AI were dropped.
     * @return array|null Null when the correction did not assess the language.
     */
    public static function donnees(?string $languejson, bool $pourenseignant): ?array {
        $langue = json_decode((string) $languejson, true);
        if (!is_array($langue) || !is_array($langue['paliers'] ?? null)) {
            return null;
        }

        $paliers = [];
        foreach (langue::DIMENSIONS as $dimension) {
            $sigle = (string) ($langue['paliers'][$dimension] ?? '');
            if (!array_key_exists($sigle, langue::PALIERS)) {
                return null;
            }
            $paliers[] = [
                'dimension' => get_string('langue_dim_' . $dimension, 'mod_redaction'),
                'mot' => get_string('langue_palier_' . strtolower($sigle), 'mod_redaction'),
                'sigle' => $sigle,
                'bas' => in_array($sigle, ['TI', 'I'], true),
            ];
        }

        $fautes = [];
        foreach ((array) ($langue['fautes'] ?? []) as $faute) {
            $fois = (int) ($faute['fois'] ?? 1);
            $fautes[] = [
                'cite' => get_string('langue_citation', 'mod_redaction', (string) ($faute['cite'] ?? '')),
                'correction' => (string) ($faute['correction'] ?? ''),
                'hastype' => !empty($faute['type']),
                'type' => !empty($faute['type']) ? get_string('langue_type_' . $faute['type'], 'mod_redaction') : '',
                'hasfois' => $fois > 1,
                'fois' => get_string('langue_fois', 'mod_redaction', $fois),
            ];
        }

        $remarques = [];
        foreach ((array) ($langue['remarques'] ?? []) as $remarque) {
            $remarques[] = [
                'cite' => get_string('langue_citation', 'mod_redaction', (string) ($remarque['cite'] ?? '')),
                'hastype' => !empty($remarque['type']),
                'type' => !empty($remarque['type']) ? get_string('langue_type_' . $remarque['type'], 'mod_redaction') : '',
            ];
        }

        $ecartees = (int) ($langue['ecartees'] ?? 0);
        return [
            'titre' => get_string('langue_titre', 'mod_redaction', (object) [
                'points' => format_float((float) ($langue['points'] ?? 0), 1, true, true),
                'sur' => format_float((float) ($langue['sur'] ?? 0), 1, true, true),
            ]),
            'paliers' => $paliers,
            'hasfautes' => !empty($fautes),
            'fautes' => $fautes,
            'hasremarques' => !empty($remarques),
            'remarques' => $remarques,
            'hasecartees' => $pourenseignant && $ecartees > 0,
            'ecartees' => $pourenseignant && $ecartees > 0 ? get_string('langue_ecartees', 'mod_redaction', $ecartees) : '',
        ];
    }
}
