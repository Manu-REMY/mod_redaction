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

    /** @var string[] Words of an answer key that give nothing away: the vocabulary of the question and of the grading. */
    const RACINES_COMMUNES = ['acces', 'accep', 'absen', 'alors', 'aussi', 'avec', 'avion', 'engin', 'machi', 'bonus', 'cela', 'comme', 'dans', 'dire', 'dirig', 'direc',
        'erreu', 'expli', 'faire', 'fait', 'faux', 'fauss', 'idee', 'juste', 'mais', 'meme', 'manqu', 'note', 'notes', 'partie', 'parti', 'piece',
        'peut', 'plus', 'pour', 'quand', 'rien', 'role', 'sans', 'seul', 'seule', 'sont', 'syste', 'tourn', 'tout', 'vaut'];

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
        // To the tenth, as shown: the grade that counts is the grade the student sees.
        return round(array_sum($notes) / count($notes), 1);
    }

    /**
     * Grade of a submission with its language part: the subjects on 20 - P, plus the language points.
     *
     * @param array $notes Grade of each answer out of 20, null when an answer has none.
     * @param float|null $pointslangue Points of the language part, null when the language is not assessed.
     * @param float $p Points of the language part in the grade out of 20.
     * @return float|null Rounded to the tenth, null when a grade is missing or there is none.
     */
    public static function note_totale(array $notes, ?float $pointslangue, float $p): ?float {
        if ($pointslangue === null) {
            return self::note_soumission($notes);
        }
        if (!$notes || in_array(null, $notes, true)) {
            return null;
        }
        // To the tenth, as shown: 11.97 shown as 12 must validate a threshold of 12.
        return round(array_sum($notes) / count($notes) * (self::NOTE_MAX - $p) / self::NOTE_MAX + $pointslangue, 1);
    }

    /**
     * Grade of a subject on the scale left to the content: out of 20 - P.
     *
     * @param float $note Grade out of 20.
     * @param float $p Points of the language part, 0 when it is not assessed.
     * @return float
     */
    public static function sur_echelle(float $note, float $p): float {
        return round($note * (self::NOTE_MAX - $p) / self::NOTE_MAX, 2);
    }

    /**
     * Whether every explanation reaches the threshold but the total, with the language, does not.
     *
     * @param array $notes Grade of each answer out of 20.
     * @param float $total Grade of the attempt.
     * @param float $seuil Grade from which the work is validated.
     * @return bool
     */
    public static function juste_sauf_langue(array $notes, float $total, float $seuil): bool {
        if (!$notes || $total >= $seuil) {
            return false;
        }
        foreach ($notes as $note) {
            if ($note === null || (float) $note < $seuil) {
                return false;
            }
        }
        return true;
    }

    /**
     * Language setting of an activity: its points, 0 when the language is not assessed.
     *
     * @param \stdClass $redaction Activity record.
     * @return float
     */
    public static function reglage_langue(\stdClass $redaction): float {
        return empty($redaction->langue_enabled) ? 0.0 : (float) $redaction->langue_points;
    }

    /**
     * Whether an evaluation counts: it was graded with the current language setting.
     *
     * An evaluation from before the language part (null) never counts.
     *
     * @param mixed $languepoints Setting recorded on the evaluation, as read from the database.
     * @param float $reglage Current setting of the activity, from reglage_langue().
     * @return bool
     */
    public static function compte($languepoints, float $reglage): bool {
        return $languepoints !== null && abs((float) $languepoints - $reglage) < 0.005;
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

        // Three parts (request 16), or the single comment of the corrections made before.
        $parties = [];
        foreach (['juste', 'a_preciser', 'piste', 'commentaire'] as $cle) {
            $valeur = $data[$cle] ?? '';
            // The label of the answer key must never reach a student.
            $parties[$cle] = is_string($valeur) ? trim(preg_replace('/\bsi absent\s*:\s*/iu', '', $valeur)) : '';
        }
        if ($parties['juste'] === '') {
            $parties['juste'] = $parties['commentaire'];
        }
        // An answer with nothing right may leave "juste" empty: one part at least is enough.
        if ($parties['juste'] === '' && $parties['a_preciser'] === '' && $parties['piste'] === '') {
            throw new \UnexpectedValueException('AI answer has no comment');
        }
        $commentaire = self::bout_a_bout([$parties['juste'], $parties['a_preciser'], $parties['piste']]);

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
            'juste' => $parties['juste'],
            'a_preciser' => $parties['a_preciser'],
            'piste' => $parties['piste'],
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
     * @param string $corrige Answer key of the subject: under the threshold, its words the student did not write cannot be shown.
     * @param string $reponse Answer of the student.
     * @param string $titre Title of the subject: naming the system gives nothing away.
     * @return string
     */
    public static function commentaire_final(array $lu, float $seuil, array $phrases, string $corrige = '', string $reponse = '',
            string $titre = ''): string {
        $lu += ['juste' => $lu['commentaire'] ?? '', 'a_preciser' => '', 'piste' => ''];
        return self::retour_final($lu, $seuil, $phrases, $corrige, $reponse . ' ' . $titre)['commentaire'];
    }

    /**
     * Feedback shown to the student, in three parts: what is right, what to make clearer, a lead (request 16).
     *
     * Under the threshold each part loses the sentences holding a word of the answer key the student did not write
     * (words of $permis are allowed: the answer, the title, the statement of the subject), and "juste" loses what it
     * says is missing. A lead emptied that way, or not given, becomes the plugin's help sentence of the activity.
     * From the threshold: what is right, and what to make clearer only when a wrong idea X remains.
     *
     * @param array $lu An answer read by lire_reponse_ia (juste, a_preciser, piste, note, attendus, erreurs).
     * @param float $seuil Grade from which the work is validated.
     * @param array $phrases Sentences for the keys fonctionnement, les_deux, precision, juste (phrases_aide()).
     * @param string $corrige Answer key, with the vocabulary expected (avec_lexique()).
     * @param string $permis Text whose words can be shown.
     * @return array ['juste', 'a_preciser', 'piste', 'commentaire'] Plain text; commentaire = the three parts in a row.
     */
    public static function retour_final(array $lu, float $seuil, array $phrases, string $corrige, string $permis): array {
        $juste = self::sans_manque((string) ($lu['juste'] ?? ''));
        $apreciser = trim((string) ($lu['a_preciser'] ?? ''));
        $piste = trim((string) ($lu['piste'] ?? ''));

        if ($lu['note'] < $seuil) {
            $juste = self::sans_mots_du_corrige($juste, $corrige, $permis);
            $apreciser = self::sans_mots_du_corrige($apreciser, $corrige, $permis);
            $piste = self::sans_mots_du_corrige($piste, $corrige, $permis);
            if ($piste === '') {
                $piece = in_array('E1', $lu['attendus'], true);
                $fonctionnement = in_array('E2', $lu['attendus'], true);
                if ($piece && $fonctionnement) {
                    $piste = $lu['erreurs'] && ($juste . $apreciser) !== '' ? '' : $phrases['precision'];
                } else if ($piece) {
                    $piste = $phrases['fonctionnement'];
                } else {
                    // Not "the part is missing": the AI may have refused an answer that does name it, or marked it E2 alone.
                    $piste = $phrases['les_deux'];
                }
            }
        } else {
            // From the threshold too, an attempt is only practice: what to make clearer never gives a word of the key.
            // What is right stays whole: the answer that earned it holds the key's ideas already.
            $apreciser = $lu['erreurs'] ? self::sans_mots_du_corrige($apreciser, $corrige, $permis) : '';
            $piste = '';
            if ($juste === '') {
                $juste = $phrases['juste'];
            }
        }

        return ['juste' => $juste, 'a_preciser' => $apreciser, 'piste' => $piste,
            'commentaire' => self::bout_a_bout([$juste, $apreciser, $piste])];
    }

    /**
     * Pieces of feedback in a row, the empty ones left out.
     *
     * @param string[] $parties
     * @return string
     */
    protected static function bout_a_bout(array $parties): string {
        return implode(' ', array_filter(array_map('trim', $parties), fn($p) => $p !== ''));
    }

    /**
     * Removes each sentence holding a word of the answer key that the student did not write (public use of the filter).
     *
     * @param string $texte
     * @param string $corrige Answer key and vocabulary expected.
     * @param string $permis Text whose words can be shown.
     * @return string
     */
    public static function filtrer(string $texte, string $corrige, string $permis): string {
        return self::sans_mots_du_corrige($texte, $corrige, $permis);
    }

    /**
     * Fingerprint of the corrected answers and the language setting: an unchanged text gives the same one (request 16).
     *
     * @param string[] $textes Plain text of each answer, in the order of the subjects.
     * @param float $reglage Language setting (reglage_langue()).
     * @return string sha1
     */
    public static function empreinte(array $textes, float $reglage): string {
        return sha1(json_encode([array_values(array_map('strval', $textes)), round($reglage, 2)], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Grade of a copy: the teacher's when there is one, else the grade of the final correction (request 16).
     *
     * @param float|null $finale Grade of the latest successful final correction.
     * @param float|null $enseignant Teacher grade.
     * @return float|null
     */
    public static function note_finale(?float $finale, ?float $enseignant): ?float {
        return $enseignant ?? $finale;
    }

    /**
     * Whether a final correction replaces the teacher grade (request 17).
     *
     * Emmanuel's rule: "ma note prime sur l'IA tant qu'il n'y a pas une nouvelle évaluation IA". A final handed in after
     * the teacher grade replaces it; one handed in before, even corrected or retried after, does not: the teacher saw that
     * text. Within the same second, or without a hand-in time, the teacher grade stands.
     *
     * @param int $finalecreee Time the copy was handed in (redaction_submission.timesubmitted).
     * @param int $noteprof Time of the teacher grade (redaction_submission.grade_enseignant_time).
     * @return bool
     */
    public static function finale_remplace_prof(int $finalecreee, int $noteprof): bool {
        return $finalecreee > $noteprof;
    }

    /** @var string[] Levels the threshold may validate, those of PRONOTE but INS, in their order. */
    const NIVEAUX = ['DM', 'F', 'PM', 'S', 'TS'];

    /**
     * Name of the level the threshold validates in this activity, for "Level … validated" (request 15).
     *
     * @param \stdClass $redaction Activity record (sujets_niveau, F when absent).
     * @return string
     * @throws \InvalidArgumentException When the stored level is not one of NIVEAUX: never another level instead.
     */
    public static function niveau(\stdClass $redaction): string {
        $code = (string) ($redaction->sujets_niveau ?? 'F');
        if (!in_array($code, self::NIVEAUX, true)) {
            throw new \InvalidArgumentException('Unknown level: ' . $code);
        }
        return get_string('sujets_niveau_' . strtolower($code), 'mod_redaction');
    }

    /**
     * Sentences the plugin adds to a comment: the help sentences of the activity, or neutral ones (request 14).
     *
     * fonctionnement: E1 found but not E2; les_deux: E1 missing; precision and juste are the same for every activity.
     *
     * @param \stdClass $redaction Activity record (sujets_aide_partiel, sujets_aide_rien).
     * @return array
     */
    public static function phrases_aide(\stdClass $redaction): array {
        $partiel = trim((string) ($redaction->sujets_aide_partiel ?? ''));
        $rien = trim((string) ($redaction->sujets_aide_rien ?? ''));
        return [
            'fonctionnement' => $partiel !== '' ? $partiel : get_string('sujets_commentaire_partiel_defaut', 'mod_redaction'),
            'les_deux' => $rien !== '' ? $rien : get_string('sujets_commentaire_rien_defaut', 'mod_redaction'),
            'precision' => get_string('sujets_commentaire_precision', 'mod_redaction'),
            'juste' => get_string('sujets_commentaire_juste', 'mod_redaction'),
        ];
    }

    /**
     * Answer key with the words of the discipline expected, which belong to it (request 7).
     *
     * The words go first: what follows "X :" in the key lists wrong ideas a comment may name, they must not fall there.
     *
     * @param string $corrige Answer key, plain text.
     * @param string $lexique Words of the discipline expected, plain text.
     * @return string
     */
    public static function avec_lexique(string $corrige, string $lexique): string {
        $lexique = trim($lexique);
        return $lexique === '' ? $corrige : $lexique . "\n" . $corrige;
    }

    /**
     * Removes each sentence holding a word of the answer key that the student did not write, whatever its wording.
     *
     * A harmless sentence may go too: the plugin's own sentence is still there, a sentence giving the answer is what must not stay.
     *
     * @param string $commentaire
     * @param string $corrige Answer key.
     * @param string $permis Text whose words can be shown.
     * @return string
     */
    protected static function sans_mots_du_corrige(string $commentaire, string $corrige, string $permis): string {
        // What comes from "X :" on lists wrong ideas and grading remarks: a comment may name the error the student made.
        $attendu = preg_split('/\bX\s*:/u', $corrige, 2)[0];
        $interdits = array_diff(self::racines($attendu), self::racines($permis), self::RACINES_COMMUNES);
        $gardees = [];
        foreach (preg_split('/(?<=[.!?…])\s+/u', $commentaire) as $phrase) {
            if (!array_intersect(self::racines($phrase), $interdits)) {
                $gardees[] = $phrase;
            }
        }
        return trim(implode(' ', $gardees));
    }

    /**
     * First five letters of each word of at least four letters, without case or accent: "Rotors" and "rotor" are the same word.
     *
     * @param string $texte
     * @return string[]
     */
    protected static function racines(string $texte): array {
        $simple = strtr(mb_strtolower($texte), ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'œ' => 'oe']);
        preg_match_all('/[a-z]{4,}/', $simple, $mots);
        return array_values(array_unique(array_map(fn($mot) => substr($mot, 0, 5), $mots[0])));
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
            . '|(?:(?:tu|il|elle|ta\s+\S+|ton\s+\S+)\s+)?n(?:e\s+|[\'’])(?:as?\s+(?:pas\s+)?)?'
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
