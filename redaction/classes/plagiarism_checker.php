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
 * Plagiarism checker using Jaccard similarity.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

/**
 * Class for checking similarity between submissions.
 */
class plagiarism_checker {

    /** @var array French stopwords to exclude from comparison. */
    const STOPWORDS_FR = [
        'le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'au', 'aux',
        'et', 'ou', 'mais', 'donc', 'or', 'ni', 'car', 'que', 'qui', 'quoi',
        'dont', 'où', 'ce', 'cette', 'ces', 'mon', 'ma', 'mes', 'ton', 'ta',
        'tes', 'son', 'sa', 'ses', 'notre', 'votre', 'leur', 'leurs',
        'je', 'tu', 'il', 'elle', 'nous', 'vous', 'ils', 'elles', 'on',
        'me', 'te', 'se', 'lui', 'en', 'y',
        'ne', 'pas', 'plus', 'jamais', 'rien', 'personne', 'aucun',
        'est', 'sont', 'a', 'ont', 'été', 'être', 'avoir', 'fait', 'faire',
        'dans', 'sur', 'sous', 'avec', 'sans', 'pour', 'par', 'entre', 'vers',
        'chez', 'comme', 'si', 'quand', 'alors', 'très', 'bien', 'aussi',
        'tout', 'tous', 'toute', 'toutes', 'autre', 'autres', 'même', 'mêmes',
        'plus', 'moins', 'peu', 'beaucoup', 'trop', 'assez',
        'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
        'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
        'should', 'may', 'might', 'can', 'shall', 'must',
        'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'as',
        'into', 'through', 'during', 'before', 'after', 'above', 'below',
        'and', 'but', 'or', 'nor', 'not', 'so', 'yet',
        'this', 'that', 'these', 'those', 'it', 'its',
    ];

    /**
     * Check similarity of a submission against all other submissions in the same activity.
     *
     * @param int $redactionid Activity ID
     * @param int $submissionid Submission ID to check
     * @return array Array of ['submissionid' => int, 'name' => string, 'similarity_percent' => float]
     */
    public static function check_submission(int $redactionid, int $submissionid): array {
        global $DB;

        // Get the target submission.
        $target = $DB->get_record('redaction_submission', ['id' => $submissionid], '*', MUST_EXIST);

        if (empty($target->contenu)) {
            return [];
        }

        // Get all other submissions for this activity.
        $others = $DB->get_records_select(
            'redaction_submission',
            'redactionid = ? AND id != ? AND contenu IS NOT NULL AND contenu != ?',
            [$redactionid, $submissionid, '']
        );

        if (empty($others)) {
            return [];
        }

        // Tokenize the target.
        $targetTokens = self::tokenize($target->contenu);

        if (empty($targetTokens)) {
            return [];
        }

        // Get the redaction record to determine group/individual mode.
        $redaction = $DB->get_record('redaction', ['id' => $redactionid]);

        $results = [];

        foreach ($others as $other) {
            $otherTokens = self::tokenize($other->contenu);

            if (empty($otherTokens)) {
                continue;
            }

            $similarity = self::jaccard_similarity($targetTokens, $otherTokens);
            $percent = round($similarity * 100, 1);

            // Get name for display.
            $name = self::get_submission_name($other, $redaction);

            $results[] = [
                'submissionid' => $other->id,
                'name' => $name,
                'similarity_percent' => $percent,
            ];
        }

        // Sort by similarity descending.
        usort($results, function ($a, $b) {
            return $b['similarity_percent'] <=> $a['similarity_percent'];
        });

        return $results;
    }

    /**
     * Get the alert threshold from settings.
     *
     * @return int Threshold percentage (default 70)
     */
    public static function get_threshold(): int {
        $threshold = (int) get_config('mod_redaction', 'plagiarism_threshold');
        return ($threshold > 0 && $threshold <= 100) ? $threshold : 70;
    }

    /**
     * Tokenize text into a set of normalized words.
     *
     * @param string $text Raw text (may contain HTML)
     * @return array Set of unique tokens
     */
    protected static function tokenize(string $text): array {
        // Strip HTML tags.
        $text = strip_tags($text);

        // Normalize: lowercase and remove special characters.
        $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', trim($text));

        // Split into words.
        $words = explode(' ', $text);

        // Remove stopwords and short words.
        $tokens = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) >= 3 && !in_array($word, self::STOPWORDS_FR)) {
                $tokens[$word] = true;
            }
        }

        return $tokens;
    }

    /**
     * Calculate Jaccard similarity between two token sets.
     *
     * @param array $setA Token set A (associative array with tokens as keys)
     * @param array $setB Token set B (associative array with tokens as keys)
     * @return float Similarity coefficient (0.0 to 1.0)
     */
    protected static function jaccard_similarity(array $setA, array $setB): float {
        $intersection = count(array_intersect_key($setA, $setB));
        $union = count($setA) + count($setB) - $intersection;

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    /**
     * Get display name for a submission.
     *
     * @param object $submission Submission record
     * @param object $redaction Activity record
     * @return string Display name
     */
    protected static function get_submission_name(object $submission, object $redaction): string {
        global $DB;

        if ($redaction->group_submission && $submission->groupid > 0) {
            $group = $DB->get_record('groups', ['id' => $submission->groupid]);
            return $group ? $group->name : get_string('unknowngroup', 'redaction');
        }

        if ($submission->userid > 0) {
            $user = $DB->get_record('user', ['id' => $submission->userid], 'id, firstname, lastname');
            return $user ? fullname($user) : get_string('unknownuser', 'redaction');
        }

        return get_string('unknownuser', 'redaction');
    }

    /**
     * Get N-gram tokens for more accurate similarity detection.
     *
     * @param string $text Text to process
     * @param int $n N-gram size (default 3)
     * @return array Set of n-grams
     */
    protected static function get_ngrams(string $text, int $n = 3): array {
        $text = strip_tags($text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', trim($text));

        $words = explode(' ', $text);
        $words = array_filter($words, function ($w) {
            return strlen(trim($w)) >= 2 && !in_array(trim($w), self::STOPWORDS_FR);
        });
        $words = array_values($words);

        $ngrams = [];
        $count = count($words);
        for ($i = 0; $i <= $count - $n; $i++) {
            $ngram = implode(' ', array_slice($words, $i, $n));
            $ngrams[$ngram] = true;
        }

        return $ngrams;
    }
}
