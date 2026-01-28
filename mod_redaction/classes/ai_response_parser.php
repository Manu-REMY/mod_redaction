<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AI response parser.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

/**
 * Class for parsing AI responses.
 */
class ai_response_parser {

    /** @var float Minimum grade */
    const MIN_GRADE = 0.0;

    /** @var float Maximum grade */
    const MAX_GRADE = 20.0;

    /**
     * Parse the AI response content.
     *
     * @param string $content Raw AI response
     * @return object Normalized response object
     * @throws \moodle_exception If parsing fails
     */
    public static function parse(string $content): object {
        $json = self::extract_json($content);

        if ($json === null) {
            throw new \moodle_exception('ai_parse_error', 'redaction', '', null, 'Could not extract JSON from response');
        }

        return self::normalize($json);
    }

    /**
     * Extract JSON from the response content.
     *
     * @param string $content
     * @return array|null
     */
    protected static function extract_json(string $content): ?array {
        // Try direct JSON parsing first.
        $data = json_decode($content, true);
        if ($data !== null) {
            return $data;
        }

        // Try to find JSON in markdown code blocks.
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $content, $matches)) {
            $data = json_decode($matches[1], true);
            if ($data !== null) {
                return $data;
            }
        }

        // Try to find any JSON object in the content.
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $data = json_decode($matches[0], true);
            if ($data !== null) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Normalize the parsed data.
     *
     * @param array $data Raw parsed data
     * @return object Normalized object
     */
    protected static function normalize(array $data): object {
        $result = new \stdClass();

        // Grade: clamp to valid range.
        $grade = isset($data['grade']) ? (float) $data['grade'] : 0.0;
        $result->grade = max(self::MIN_GRADE, min(self::MAX_GRADE, $grade));

        // Feedback: sanitize HTML.
        $result->feedback = isset($data['feedback']) ? self::sanitize_text($data['feedback']) : '';

        // Criteria array.
        $result->criteria = [];
        if (isset($data['criteria']) && is_array($data['criteria'])) {
            foreach ($data['criteria'] as $criterion) {
                $result->criteria[] = (object) [
                    'name' => $criterion['name'] ?? 'Critère',
                    'score' => isset($criterion['score']) ? (float) $criterion['score'] : 0,
                    'max' => isset($criterion['max']) ? (float) $criterion['max'] : 5,
                    'comment' => isset($criterion['comment']) ? self::sanitize_text($criterion['comment']) : '',
                ];
            }
        }

        // Keywords found/missing.
        $result->keywords_found = isset($data['keywords_found']) && is_array($data['keywords_found'])
            ? array_map('trim', $data['keywords_found']) : [];
        $result->keywords_missing = isset($data['keywords_missing']) && is_array($data['keywords_missing'])
            ? array_map('trim', $data['keywords_missing']) : [];

        // Suggestions.
        $result->suggestions = isset($data['suggestions']) && is_array($data['suggestions'])
            ? array_map([self::class, 'sanitize_text'], $data['suggestions']) : [];

        // Confidence score.
        $confidence = isset($data['confidence']) ? (float) $data['confidence'] : 0.8;
        $result->confidence = max(0.0, min(1.0, $confidence));

        return $result;
    }

    /**
     * Sanitize text content.
     *
     * @param string $text
     * @return string
     */
    protected static function sanitize_text(string $text): string {
        // Remove dangerous tags but keep basic formatting.
        $text = strip_tags($text, '<p><br><strong><em><ul><ol><li>');
        // Convert newlines to HTML if no tags present.
        if (strip_tags($text) === $text) {
            $text = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        }
        return $text;
    }

    /**
     * Calculate grade from criteria scores.
     *
     * @param array $criteria
     * @return float
     */
    public static function calculate_grade_from_criteria(array $criteria): float {
        if (empty($criteria)) {
            return 0.0;
        }

        $totalScore = 0;
        $totalMax = 0;

        foreach ($criteria as $criterion) {
            $score = is_object($criterion) ? $criterion->score : ($criterion['score'] ?? 0);
            $max = is_object($criterion) ? $criterion->max : ($criterion['max'] ?? 5);
            $totalScore += $score;
            $totalMax += $max;
        }

        if ($totalMax == 0) {
            return 0.0;
        }

        // Scale to 0-20.
        return ($totalScore / $totalMax) * 20.0;
    }

    /**
     * Format parsed result for display.
     *
     * @param object $result Parsed result
     * @return string HTML formatted output
     */
    public static function format_for_display(object $result): string {
        $html = '<div class="ai-result">';

        // Grade.
        $html .= '<div class="ai-grade-display">';
        $html .= '<strong>Note :</strong> ' . number_format($result->grade, 1) . '/20';
        $html .= '</div>';

        // Feedback.
        if (!empty($result->feedback)) {
            $html .= '<div class="ai-feedback-display">';
            $html .= '<strong>Commentaires :</strong><br>';
            $html .= $result->feedback;
            $html .= '</div>';
        }

        // Criteria.
        if (!empty($result->criteria)) {
            $html .= '<div class="ai-criteria-display">';
            $html .= '<strong>Critères :</strong>';
            $html .= '<ul>';
            foreach ($result->criteria as $criterion) {
                $html .= '<li>';
                $html .= htmlspecialchars($criterion->name) . ': ';
                $html .= $criterion->score . '/' . $criterion->max;
                if (!empty($criterion->comment)) {
                    $html .= ' - ' . $criterion->comment;
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }
}
