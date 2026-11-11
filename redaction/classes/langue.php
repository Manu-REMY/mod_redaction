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
 * Language mastery assessment (grid of the note de service of 11/09/2026). Every method is pure: neither Moodle nor the database.
 *
 * The AI places each of the four dimensions on a level and quotes the student's mistakes. The server keeps only the quotes
 * found in the student's text, raises a level that has lost all its proof, and computes the points itself.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class langue {
    /** @var string[] Dimensions of the grid, in display order. */
    const DIMENSIONS = ['orthographe', 'syntaxe', 'lexique', 'organisation'];

    /** @var float[] Value of each level, from very insufficient to very satisfactory. */
    const PALIERS = ['TI' => 0.0, 'I' => 1 / 3, 'S' => 2 / 3, 'TS' => 1.0];

    /** @var string[] Kinds of spelling mistakes. */
    const TYPES_ORTHOGRAPHE = ['lexicale', 'accord', 'conjugaison', 'homophone'];

    /** @var string[] Kinds of sentence-building remarks. */
    const TYPES_SYNTAXE = ['phrase_mal_construite', 'phrase_incomplete', 'ponctuation'];

    /** @var string[] Dimensions whose low level must be proved by a quote found in the text. */
    const AVEC_PREUVE = ['orthographe' => 'fautes', 'syntaxe' => 'remarques'];

    /**
     * Reads the AI answer about the language.
     *
     * Anything the AI adds, such as its own points, is ignored: the points come from points() only.
     *
     * @param string $brut Raw text returned by the provider, JSON possibly inside a code fence.
     * @return array ['paliers' => [dimension => level], 'fautes' => [['cite', 'correction', 'type', 'fois']],
     *               'remarques' => [['cite', 'type']]]
     * @throws \UnexpectedValueException On invalid JSON, a missing dimension or an unknown level.
     */
    public static function lire_reponse_ia(string $brut): array {
        $json = trim($brut);
        if (preg_match('/```(?:json)?\s*(.*?)```/is', $json, $m)) {
            $json = trim($m[1]);
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('AI language answer is not JSON');
        }

        $paliers = [];
        foreach (self::DIMENSIONS as $dimension) {
            $palier = $data[$dimension]['palier'] ?? null;
            $palier = is_string($palier) ? strtoupper(trim($palier)) : null;
            if ($palier === null || !array_key_exists($palier, self::PALIERS)) {
                throw new \UnexpectedValueException('AI language answer has no valid level for ' . $dimension);
            }
            $paliers[$dimension] = $palier;
        }

        $fautes = [];
        foreach (self::liste($data['orthographe']['fautes'] ?? null) as $faute) {
            $fois = isset($faute['fois']) && is_numeric($faute['fois']) ? (int) $faute['fois'] : 1;
            $fautes[] = [
                'cite' => self::texte($faute['cite'] ?? null),
                'correction' => self::texte($faute['correction'] ?? null),
                'type' => self::type($faute['type'] ?? null, self::TYPES_ORTHOGRAPHE),
                'fois' => max(1, $fois),
            ];
        }

        $remarques = [];
        foreach (self::liste($data['syntaxe']['remarques'] ?? null) as $remarque) {
            $remarques[] = [
                'cite' => self::texte($remarque['cite'] ?? null),
                'type' => self::type($remarque['type'] ?? null, self::TYPES_SYNTAXE),
            ];
        }

        return ['paliers' => $paliers, 'fautes' => $fautes, 'remarques' => $remarques];
    }

    /**
     * Whether a quote is in the text: case, spaces and typographic apostrophes aside, whole words only.
     *
     * @param string $cite Quote given by the AI.
     * @param string $texte Text of the student.
     * @return bool
     */
    public static function figure_dans(string $cite, string $texte): bool {
        $cite = self::normaliser($cite);
        if ($cite === '') {
            return false;
        }
        return (bool) preg_match('/(?<![\p{L}\p{N}])' . preg_quote($cite, '/') . '(?![\p{L}\p{N}])/u', self::normaliser($texte));
    }

    /**
     * Whether a correction is another spelling of the quoted words, and not another word.
     *
     * "Different" keeps accents ("a" and "à" differ), "close" ignores them.
     *
     * @param string $cite Words as the student wrote them.
     * @param string $correction Correct form given by the AI.
     * @return bool
     */
    public static function correction_proche(string $cite, string $correction): bool {
        if (self::normaliser($correction) === '' || self::normaliser($correction) === self::normaliser($cite)) {
            return false;
        }
        $a = self::plier($cite);
        $b = self::plier($correction);
        return levenshtein($a, $b) <= max(2, (int) floor(0.4 * strlen($a)));
    }

    /**
     * Keeps what the text proves: quotes found in it, corrections close to their quote.
     *
     * A spelling or syntax level below S that keeps no proof goes up to S. Lexicon and organisation need no quote.
     *
     * @param array $lu An answer read by lire_reponse_ia.
     * @param string $texte Text of the student, every answer of the attempt.
     * @return array The same keys, plus 'ecartees': how many entries were removed (merged duplicates aside).
     */
    public static function verifier(array $lu, string $texte): array {
        $ecartees = 0;

        $fautes = [];
        foreach ($lu['fautes'] as $faute) {
            if (!self::figure_dans($faute['cite'], $texte) || !self::correction_proche($faute['cite'], $faute['correction'])) {
                $ecartees++;
                continue;
            }
            $cle = self::plier($faute['cite']) . '|' . self::normaliser($faute['correction']);
            if (isset($fautes[$cle])) {
                $fautes[$cle]['fois'] += $faute['fois'];
            } else {
                $fautes[$cle] = $faute;
            }
        }

        $remarques = [];
        foreach ($lu['remarques'] as $remarque) {
            if (!self::figure_dans($remarque['cite'], $texte)) {
                $ecartees++;
                continue;
            }
            $remarques[self::normaliser($remarque['cite'])] = $remarque;
        }

        $verifie = ['paliers' => $lu['paliers'], 'fautes' => array_values($fautes), 'remarques' => array_values($remarques)];
        foreach (self::AVEC_PREUVE as $dimension => $preuves) {
            if (in_array($verifie['paliers'][$dimension], ['TI', 'I'], true) && !$verifie[$preuves]) {
                $verifie['paliers'][$dimension] = 'S';
            }
        }
        $verifie['ecartees'] = $ecartees;
        return $verifie;
    }

    /**
     * Points of the language part: P times the mean value of the four levels.
     *
     * @param array $paliers Dimension => level.
     * @param float $p Points of the language part in the grade out of 20.
     * @return float Rounded to two decimals.
     */
    public static function points(array $paliers, float $p): float {
        $somme = 0.0;
        foreach (self::DIMENSIONS as $dimension) {
            $somme += self::PALIERS[$paliers[$dimension]];
        }
        return round($p * $somme / count(self::DIMENSIONS), 2);
    }

    /**
     * Lower case, one plain apostrophe, single spaces.
     *
     * @param string $texte
     * @return string
     */
    protected static function normaliser(string $texte): string {
        $texte = mb_strtolower(strtr($texte, ['’' => "'", '‘' => "'", 'ʼ' => "'", "\u{00A0}" => ' ', "\u{202F}" => ' ']));
        return trim(preg_replace('/\s+/u', ' ', $texte));
    }

    /**
     * Normalised text without accents, for comparing spellings.
     *
     * @param string $texte
     * @return string
     */
    protected static function plier(string $texte): string {
        return strtr(self::normaliser($texte), ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y', 'œ' => 'oe',
            'æ' => 'ae']);
    }

    /**
     * Entries of a list given by the AI, each an array.
     *
     * @param mixed $liste
     * @return array[]
     */
    protected static function liste($liste): array {
        return is_array($liste) ? array_values(array_filter($liste, 'is_array')) : [];
    }

    /**
     * A string given by the AI, empty when it is not one.
     *
     * @param mixed $valeur
     * @return string
     */
    protected static function texte($valeur): string {
        return is_string($valeur) ? trim($valeur) : '';
    }

    /**
     * A known kind, null otherwise: an unknown kind is shown without a label.
     *
     * @param mixed $type
     * @param string[] $connus
     * @return string|null
     */
    protected static function type($type, array $connus): ?string {
        return is_string($type) && in_array($type, $connus, true) ? $type : null;
    }
}
