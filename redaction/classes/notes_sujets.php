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
 * Grades of the assigned subjects mode. Every method is pure: neither Moodle nor the database.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notes_sujets {
    /** @var float Highest grade an answer can get. */
    const NOTE_MAX = 20.0;

    /** @var int Value of Moodle's FORMAT_PLAIN: this class does not load Moodle. */
    const FORMAT_TEXTE = 2;

    /**
     * Grade of a submission: the average of its answers.
     *
     * @param array $notes Grade of each answer, null when an answer has none.
     * @return float|null The average, null when a grade is missing or there is none.
     */
    public static function note_soumission(array $notes): ?float {
        if (!$notes || in_array(null, $notes, true)) {
            return null;
        }
        return round(array_sum($notes) / count($notes), 2);
    }

    /**
     * Grade that counts for a submission: the teacher's when set, otherwise the best evaluation.
     *
     * @param array $evaluations Grade of each evaluation (numbers or database strings, null ignored).
     * @param float|null $enseignant Teacher grade, null when there is none.
     * @return float|null
     */
    public static function meilleure(array $evaluations, ?float $enseignant): ?float {
        if ($enseignant !== null) {
            return $enseignant;
        }
        $notes = array_filter($evaluations, fn($note) => $note !== null);
        return $notes ? (float) max(array_map('floatval', $notes)) : null;
    }

    /**
     * Grade of each user, from the members recorded on each evaluation.
     *
     * @param array $evaluations Evaluation id => grade.
     * @param array $membres Evaluation id => ids of the users it credits.
     * @param array $enseignant User id => teacher grade, which wins over the evaluations.
     * @return array User id => grade.
     */
    public static function pour_membres(array $evaluations, array $membres, array $enseignant): array {
        $notes = [];
        foreach ($membres as $evaluationid => $userids) {
            $note = $evaluations[$evaluationid] ?? null;
            if ($note === null) {
                continue;
            }
            foreach ($userids as $userid) {
                $notes[$userid] = max($notes[$userid] ?? 0.0, (float) $note);
            }
        }
        foreach ($enseignant as $userid => $note) {
            $notes[$userid] = (float) $note;
        }
        return $notes;
    }

    /**
     * Reads the AI answer for one subject.
     *
     * @param string $brut Raw text returned by the provider, JSON possibly inside a code fence.
     * @return array ['note' => float, 'commentaire' => string]
     * @throws \UnexpectedValueException On invalid JSON, a missing key or a grade outside 0-20.
     */
    public static function lire_reponse_ia(string $brut): array {
        $json = trim($brut);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $m)) {
            $json = trim($m[1]);
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('AI answer is not JSON');
        }

        $note = $data['note'] ?? null;
        if (!is_int($note) && !is_float($note) && !(is_string($note) && is_numeric($note))) {
            throw new \UnexpectedValueException('AI answer has no numeric note');
        }
        $note = (float) $note;
        if ($note < 0 || $note > self::NOTE_MAX) {
            throw new \UnexpectedValueException('AI note is outside 0-20');
        }

        $commentaire = $data['commentaire'] ?? null;
        if (!is_string($commentaire) || trim($commentaire) === '') {
            throw new \UnexpectedValueException('AI answer has no comment');
        }

        return ['note' => $note, 'commentaire' => trim($commentaire)];
    }

    /**
     * Plain text of a stored answer. An empty string means the student wrote nothing.
     *
     * Plain text keeps every character: "<5 cm" is not the start of a tag.
     *
     * @param string|null $contenu Stored answer.
     * @param int $format Its format, FORMAT_TEXTE for plain text, anything else is read as HTML.
     * @return string
     */
    public static function texte_reponse(?string $contenu, int $format): string {
        $texte = (string) $contenu;
        if ($format !== self::FORMAT_TEXTE) {
            $texte = preg_replace('~<(br|/p|/div|/li)\b[^>]*>~i', "\n", $texte);
            $texte = html_entity_decode(strip_tags($texte), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return trim(str_replace("\u{00A0}", ' ', $texte));
    }
}
