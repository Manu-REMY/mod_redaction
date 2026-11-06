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

    /** @var float Highest grade of an answer without both the part that steers (E1) and how it works (E2). */
    const NOTE_INCOMPLETE = 14.0;

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
     * @return array ['note' => float, 'note_ia' => float, 'commentaire' => string, 'attendus' => string[], 'erreurs' => string[]]
     *               note is note_ia, capped when the AI's own list says E1 or E2 is missing.
     * @throws \UnexpectedValueException On invalid JSON, a missing key, a missing list of expected points or a grade outside 0-20.
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
        if (is_string($commentaire)) {
            // The label of the answer key must never reach a student.
            $commentaire = trim(preg_replace('/\bsi absent\s*:\s*/iu', '', $commentaire));
        }
        if (!is_string($commentaire) || $commentaire === '') {
            throw new \UnexpectedValueException('AI answer has no comment');
        }

        // The cap and the "il manque" sentence depend on this list: without it, the correction fails and the attempt goes back.
        $attendus = self::reperes($data['attendus'] ?? null, 'E');
        if ($attendus === null) {
            throw new \UnexpectedValueException('AI answer has no list of expected points');
        }
        $complet = !array_diff(['E1', 'E2'], $attendus);

        return [
            'note' => $complet ? $note : min($note, self::NOTE_INCOMPLETE),
            'note_ia' => $note,
            'commentaire' => $commentaire,
            'attendus' => $attendus,
            'erreurs' => self::reperes($data['erreurs'] ?? null, 'X') ?? [],
        ];
    }

    /**
     * Marks such as E1 or X2 found in a list given by the AI.
     *
     * @param mixed $liste The list, as decoded from the AI answer.
     * @param string $lettre Letter of the marks.
     * @return string[]|null The marks, null when there is no list.
     */
    protected static function reperes($liste, string $lettre): ?array {
        if (!is_array($liste)) {
            return null;
        }
        $reperes = [];
        foreach ($liste as $element) {
            if (is_string($element) && preg_match_all('/\b' . $lettre . '\s*(\d+)\b/i', $element, $m)) {
                foreach ($m[1] as $numero) {
                    $reperes[] = $lettre . $numero;
                }
            }
        }
        return array_values(array_unique($reperes));
    }

    /**
     * Comment shown to the student: what the AI wrote, without anything about what is missing, then the plugin's own sentence.
     *
     * The AI is told not to say what is missing and sans_manque removes what it says all the same. This lowers the risk
     * that a comment gives the answer or contradicts the student's text, it does not rule it out: the filter is a list of wordings.
     * E1 is the part that steers and E2 how it works: an answer key is written that way in this mode.
     *
     * @param array $lu An answer read by lire_reponse_ia.
     * @param float $seuil Grade from which the work is validated: nothing is missing from there.
     * @param array $phrases Sentences for the keys fonctionnement, les_deux, precision, juste.
     * @return string
     */
    public static function commentaire_final(array $lu, float $seuil, array $phrases): string {
        $texte = self::sans_manque($lu['commentaire']);
        $ajout = '';
        if ($lu['note'] < $seuil) {
            $piece = in_array('E1', $lu['attendus'], true);
            $fonctionnement = in_array('E2', $lu['attendus'], true);
            if ($piece && $fonctionnement) {
                $ajout = $lu['erreurs'] && $texte !== '' ? '' : $phrases['precision'];
            } else if ($piece) {
                $ajout = $phrases['fonctionnement'];
            } else {
                // Not "the part is missing": the AI may have refused an answer that does name it, or marked it E2 alone.
                $ajout = $phrases['les_deux'];
            }
        } else if ($texte === '') {
            $ajout = $phrases['juste'];
        }

        return trim($texte . ' ' . $ajout);
    }

    /**
     * Removes what a comment says is missing: the end of a sentence after a separator, or else the whole sentence.
     *
     * "Tu ne dis pas que la machine se plie" is how an AI told not to say what is missing gives the answer all the same.
     * What is kept is checked again: a second "missing" may follow the first, and "En revanche." alone says nothing.
     *
     * @param string $commentaire
     * @return string
     */
    protected static function sans_manque(string $commentaire): string {
        $manque = '(?:(?:il\s+)?(?:te\s+|t[\'’])?manqu'
            . '|(?:(?:tu|il|elle|ta\s+\S+|ton\s+\S+)\s+)?n(?:e\s+|[\'’])(?:as?\s+pas\s+)?'
            . '(?:dis|dit|parl|mentionn|cit|expliqu|décri|précis|donn|indiqu|nomm|montr))';
        $liaison = '(?:mais|par\s+contre|en\s+revanche|cependant|toutefois|néanmoins|pourtant)';
        $gardees = [];
        foreach (preg_split('/(?<=[.!?…])\s+/u', $commentaire) as $phrase) {
            if (!preg_match('/(?<!\w)' . $manque . '/iu', $phrase)) {
                $gardees[] = $phrase;
            } else if (preg_match(
                '/^(.+?)(?:\s*[,;:]\s*(?:(?:et|' . $liaison . ')\s+)?|\s+(?:et|' . $liaison . ')\s+)' . $manque . '/iu',
                $phrase, $m)) {
                $debut = trim(preg_replace('/(?:^|[,;:\s])\s*' . $liaison . '$/iu', '', $m[1]));
                if ($debut !== '' && !preg_match('/(?<!\w)' . $manque . '/iu', $debut)) {
                    $gardees[] = $debut . '.';
                }
            }
        }
        return trim(implode(' ', $gardees));
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
