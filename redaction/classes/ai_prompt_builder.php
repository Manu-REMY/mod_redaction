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
 * AI prompt builder for redaction evaluation.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

/**
 * Class for building AI prompts.
 */
class ai_prompt_builder {

    /**
     * Build prompts for evaluation.
     *
     * @param object $studentsubmission Student's submission
     * @param object $consignes Teacher's instructions
     * @param object $correctionmodel Teacher's correction model
     * @param bool $istraining Whether this is a training (formative) evaluation
     * @return array ['system' => string, 'user' => string]
     */
    public static function build_prompt(object $studentsubmission, object $consignes, object $correctionmodel, bool $istraining = false): array {
        return [
            'system' => self::build_system_prompt($consignes, $correctionmodel, $istraining),
            'user' => self::build_user_prompt($studentsubmission, $consignes, $correctionmodel),
        ];
    }

    /**
     * Build the system prompt.
     *
     * @param object $consignes
     * @param object $correctionmodel
     * @param bool $istraining Whether this is a training (formative) evaluation
     * @return string
     */
    protected static function build_system_prompt(object $consignes, object $correctionmodel, bool $istraining = false): string {
        $prompt = get_string('ai_prompt_system_intro', 'mod_redaction') . "\n\n";

        $prompt .= "## " . get_string('ai_prompt_activity_context', 'mod_redaction') . "\n";
        if (!empty($consignes->titre)) {
            $prompt .= "**" . get_string('ai_prompt_title_label', 'mod_redaction') . "** " . strip_tags($consignes->titre) . "\n";
        }

        $prompt .= "\n## " . get_string('ai_prompt_criteria_section', 'mod_redaction') . "\n";

        // Use custom criteria if available, otherwise default.
        $criteria = self::parse_criteria($correctionmodel->grille_criteres ?? '');
        if (empty($criteria)) {
            $criteria = self::get_default_criteria();
        }

        foreach ($criteria as $criterion) {
            $prompt .= "- **" . $criterion['name'] . "** (/" . $criterion['weight'] . ") : " . $criterion['description'] . "\n";
        }

        // Add teacher's AI instructions if available.
        if (!empty($correctionmodel->ai_instructions)) {
            $prompt .= "\n## " . get_string('ai_prompt_specific_instructions', 'mod_redaction') . "\n";
            $prompt .= strip_tags($correctionmodel->ai_instructions) . "\n";
        }

        $prompt .= "\n## " . get_string('ai_prompt_response_format', 'mod_redaction') . "\n";
        $prompt .= get_string('ai_prompt_response_format_intro', 'mod_redaction') . "\n";
        $prompt .= "```json\n";
        $prompt .= "{\n";
        $prompt .= "  \"grade\": <" . get_string('ai_prompt_grade_desc', 'mod_redaction') . ">,\n";
        $prompt .= "  \"feedback\": \"<" . get_string('ai_prompt_feedback_desc', 'mod_redaction') . ">\",\n";
        $prompt .= "  \"criteria\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"name\": \"<" . get_string('ai_prompt_criterion_name_desc', 'mod_redaction') . ">\",\n";
        $prompt .= "      \"score\": <score>,\n";
        $prompt .= "      \"max\": <max>,\n";
        $prompt .= "      \"comment\": \"<" . get_string('ai_prompt_criterion_comment_desc', 'mod_redaction') . ">\",\n";
        $prompt .= "      \"level\": \"<excellent|good|medium|low>\"\n";
        $prompt .= "    }\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"strengths\": [\"<" . get_string('ai_prompt_strengths_desc', 'mod_redaction') . " 1>\", \"<" . get_string('ai_prompt_strengths_desc', 'mod_redaction') . " 2>\"],\n";
        $prompt .= "  \"weaknesses\": [\"<" . get_string('ai_prompt_weaknesses_desc', 'mod_redaction') . " 1>\", \"<" . get_string('ai_prompt_weaknesses_desc', 'mod_redaction') . " 2>\"],\n";
        $prompt .= "  \"keywords_found\": [\"<" . get_string('ai_prompt_keywords_found_desc', 'mod_redaction') . ">\"],\n";
        $prompt .= "  \"keywords_missing\": [\"<" . get_string('ai_prompt_keywords_missing_desc', 'mod_redaction') . ">\"],\n";
        $prompt .= "  \"suggestions\": [\"<" . get_string('ai_prompt_suggestions_desc', 'mod_redaction') . " 1>\", \"<" . get_string('ai_prompt_suggestions_desc', 'mod_redaction') . " 2>\"],\n";
        $prompt .= "  \"overall_appreciation\": \"<" . get_string('ai_prompt_appreciation_desc', 'mod_redaction') . ">\",\n";
        $prompt .= "  \"confidence\": <" . get_string('ai_prompt_confidence_desc', 'mod_redaction') . ">\n";
        $prompt .= "}\n";
        $prompt .= "```\n\n";

        if ($istraining) {
            $prompt .= "\n## " . get_string('ai_prompt_training_context', 'mod_redaction') . "\n";
            $prompt .= get_string('ai_prompt_training_intro', 'mod_redaction') . "\n";
            $prompt .= "- " . get_string('ai_prompt_training_detailed', 'mod_redaction') . "\n";
            $prompt .= "- " . get_string('ai_prompt_training_identify', 'mod_redaction') . "\n";
            $prompt .= "- " . get_string('ai_prompt_training_examples', 'mod_redaction') . "\n";
            $prompt .= "- " . get_string('ai_prompt_training_indicative', 'mod_redaction') . "\n\n";
        }

        $prompt .= "## " . get_string('ai_prompt_important_instructions', 'mod_redaction') . "\n";
        $prompt .= "- " . get_string('ai_prompt_address_student', 'mod_redaction') . "\n";
        $prompt .= "- " . get_string('ai_prompt_start_positive', 'mod_redaction') . "\n";
        $prompt .= "- " . get_string('ai_prompt_level_criteria', 'mod_redaction') . "\n";
        $prompt .= "- " . get_string('ai_prompt_list_strengths', 'mod_redaction') . "\n";
        $prompt .= "- " . get_string('ai_prompt_give_suggestions', 'mod_redaction') . "\n";
        $prompt .= "- " . get_string('ai_prompt_appreciation_instructions', 'mod_redaction') . "\n";
        $prompt .= "- " . get_string('ai_prompt_grade_coherence', 'mod_redaction') . "\n";
        $prompt .= "- " . get_string('ai_prompt_feedback_structured', 'mod_redaction') . "\n";

        return $prompt;
    }

    /**
     * Build the user prompt.
     *
     * @param object $studentsubmission
     * @param object $consignes
     * @param object $correctionmodel
     * @return string
     */
    protected static function build_user_prompt(object $studentsubmission, object $consignes, object $correctionmodel): string {
        $prompt = "## " . get_string('ai_prompt_student_instructions', 'mod_redaction') . "\n";
        if (!empty($consignes->consignes)) {
            $prompt .= strip_tags($consignes->consignes) . "\n";
        }

        if (!empty($consignes->criteres)) {
            $prompt .= "\n**" . get_string('ai_prompt_criteria_communicated', 'mod_redaction') . "**\n";
            $prompt .= strip_tags($consignes->criteres) . "\n";
        }

        // Add teacher's model answer if available.
        if (!empty($correctionmodel->modele_reponse)) {
            $prompt .= "\n## " . get_string('ai_prompt_model_answer', 'mod_redaction') . "\n";
            $prompt .= strip_tags($correctionmodel->modele_reponse) . "\n";
        }

        $prompt .= "\n## " . get_string('ai_prompt_student_work', 'mod_redaction') . "\n";

        if (!empty($studentsubmission->titre)) {
            $prompt .= "**" . get_string('ai_prompt_title_label', 'mod_redaction') . "** " . strip_tags($studentsubmission->titre) . "\n\n";
        }

        $prompt .= "**" . get_string('ai_prompt_content_label', 'mod_redaction') . "**\n";
        $prompt .= strip_tags($studentsubmission->contenu ?? '') . "\n";

        $prompt .= "\n---\n";
        $prompt .= get_string('ai_prompt_evaluate_instruction', 'mod_redaction');

        return $prompt;
    }

    /**
     * Parse criteria JSON string.
     *
     * @param string $json
     * @return array
     */
    protected static function parse_criteria(string $json): array {
        if (empty($json)) {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        $criteria = [];
        foreach ($data as $item) {
            if (isset($item['name'])) {
                $criteria[] = [
                    'name' => $item['name'],
                    'weight' => isset($item['weight']) ? (int) $item['weight'] : 5,
                    'description' => $item['description'] ?? '',
                ];
            }
        }

        return $criteria;
    }

    /**
     * Get default criteria for display.
     *
     * @return array
     */
    public static function get_default_criteria(): array {
        return [
            [
                'name' => get_string('ai_default_criterion_relevance', 'mod_redaction'),
                'weight' => 5,
                'description' => get_string('ai_default_criterion_relevance_desc', 'mod_redaction'),
            ],
            [
                'name' => get_string('ai_default_criterion_structure', 'mod_redaction'),
                'weight' => 5,
                'description' => get_string('ai_default_criterion_structure_desc', 'mod_redaction'),
            ],
            [
                'name' => get_string('ai_default_criterion_expression', 'mod_redaction'),
                'weight' => 5,
                'description' => get_string('ai_default_criterion_expression_desc', 'mod_redaction'),
            ],
            [
                'name' => get_string('ai_default_criterion_argumentation', 'mod_redaction'),
                'weight' => 5,
                'description' => get_string('ai_default_criterion_argumentation_desc', 'mod_redaction'),
            ],
        ];
    }
}
