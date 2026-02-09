<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

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

    /** @var array Default evaluation criteria */
    const DEFAULT_CRITERIA = [
        ['name' => 'Pertinence', 'weight' => 5, 'description' => 'La réponse est pertinente par rapport au sujet'],
        ['name' => 'Structure', 'weight' => 5, 'description' => 'Organisation logique et claire du texte'],
        ['name' => 'Expression', 'weight' => 5, 'description' => 'Qualité de l\'expression écrite (orthographe, grammaire, vocabulaire)'],
        ['name' => 'Argumentation', 'weight' => 5, 'description' => 'Qualité et pertinence des arguments présentés'],
    ];

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
        $prompt = "Tu es un assistant pédagogique expert en évaluation de rédactions d'élèves. ";
        $prompt .= "Tu dois évaluer la production d'un élève de manière juste, bienveillante et constructive.\n\n";

        $prompt .= "## Contexte de l'activité\n";
        if (!empty($consignes->titre)) {
            $prompt .= "**Titre :** " . strip_tags($consignes->titre) . "\n";
        }

        $prompt .= "\n## Critères d'évaluation\n";

        // Use custom criteria if available, otherwise default.
        $criteria = self::parse_criteria($correctionmodel->grille_criteres ?? '');
        if (empty($criteria)) {
            $criteria = self::DEFAULT_CRITERIA;
        }

        foreach ($criteria as $criterion) {
            $prompt .= "- **" . $criterion['name'] . "** (/" . $criterion['weight'] . ") : " . $criterion['description'] . "\n";
        }

        // Add teacher's AI instructions if available.
        if (!empty($correctionmodel->ai_instructions)) {
            $prompt .= "\n## Instructions spécifiques\n";
            $prompt .= strip_tags($correctionmodel->ai_instructions) . "\n";
        }

        $prompt .= "\n## Format de réponse\n";
        $prompt .= "Tu DOIS répondre en JSON avec la structure suivante :\n";
        $prompt .= "```json\n";
        $prompt .= "{\n";
        $prompt .= "  \"grade\": <note de 0 à 20>,\n";
        $prompt .= "  \"feedback\": \"<commentaire détaillé et constructif adressé directement à l'élève>\",\n";
        $prompt .= "  \"criteria\": [\n";
        $prompt .= "    {\n";
        $prompt .= "      \"name\": \"<nom du critère>\",\n";
        $prompt .= "      \"score\": <note>,\n";
        $prompt .= "      \"max\": <max>,\n";
        $prompt .= "      \"comment\": \"<commentaire détaillé sur ce critère>\",\n";
        $prompt .= "      \"level\": \"<excellent|good|medium|low>\"\n";
        $prompt .= "    }\n";
        $prompt .= "  ],\n";
        $prompt .= "  \"strengths\": [\"<point fort 1>\", \"<point fort 2>\"],\n";
        $prompt .= "  \"weaknesses\": [\"<axe d'amélioration 1>\", \"<axe d'amélioration 2>\"],\n";
        $prompt .= "  \"keywords_found\": [\"<mots-clés trouvés>\"],\n";
        $prompt .= "  \"keywords_missing\": [\"<mots-clés attendus mais absents>\"],\n";
        $prompt .= "  \"suggestions\": [\"<conseil d'amélioration concret et actionnable 1>\", \"<conseil 2>\"],\n";
        $prompt .= "  \"overall_appreciation\": \"<appréciation globale courte, 1-2 phrases, encourageante>\",\n";
        $prompt .= "  \"confidence\": <0.0 à 1.0>\n";
        $prompt .= "}\n";
        $prompt .= "```\n\n";

        if ($istraining) {
            $prompt .= "\n## CONTEXTE : MODE ENTRAÎNEMENT\n";
            $prompt .= "Cette évaluation est un retour formatif pour aider l'élève à s'améliorer AVANT sa soumission finale.\n";
            $prompt .= "- Sois particulièrement détaillé dans tes suggestions d'amélioration.\n";
            $prompt .= "- Identifie clairement ce qui doit être retravaillé.\n";
            $prompt .= "- Donne des exemples concrets de reformulation ou d'ajouts possibles.\n";
            $prompt .= "- La note n'est qu'indicative, insiste sur les pistes d'amélioration.\n\n";
        }

        $prompt .= "## Consignes importantes\n";
        $prompt .= "- Adresse-toi directement à l'élève avec bienveillance et encouragement (utilise \"tu\").\n";
        $prompt .= "- Commence TOUJOURS par valoriser les points positifs avant les axes d'amélioration.\n";
        $prompt .= "- Pour chaque critère, attribue un level: \"excellent\" (>=80%), \"good\" (>=60%), \"medium\" (>=40%), \"low\" (<40%).\n";
        $prompt .= "- Liste 2 à 4 points forts (strengths) et 2 à 4 axes d'amélioration (weaknesses).\n";
        $prompt .= "- Donne 2 à 4 suggestions concrètes, actionnables et réalisables pour s'améliorer.\n";
        $prompt .= "- L'appréciation globale (overall_appreciation) doit être encourageante et résumer l'essentiel en 1-2 phrases.\n";
        $prompt .= "- La note doit être cohérente avec les scores des critères.\n";
        $prompt .= "- Le feedback doit être structuré et lisible.\n";

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
        $prompt = "## Consignes données aux élèves\n";
        if (!empty($consignes->consignes)) {
            $prompt .= strip_tags($consignes->consignes) . "\n";
        }

        if (!empty($consignes->criteres)) {
            $prompt .= "\n**Critères communiqués aux élèves :**\n";
            $prompt .= strip_tags($consignes->criteres) . "\n";
        }

        // Add teacher's model answer if available.
        if (!empty($correctionmodel->modele_reponse)) {
            $prompt .= "\n## Modèle de réponse attendue\n";
            $prompt .= strip_tags($correctionmodel->modele_reponse) . "\n";
        }

        $prompt .= "\n## Production de l'élève\n";

        if (!empty($studentsubmission->titre)) {
            $prompt .= "**Titre :** " . strip_tags($studentsubmission->titre) . "\n\n";
        }

        $prompt .= "**Contenu :**\n";
        $prompt .= strip_tags($studentsubmission->contenu ?? '') . "\n";

        $prompt .= "\n---\n";
        $prompt .= "Évalue cette production selon les critères définis et fournis ta réponse en JSON.";

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
        return self::DEFAULT_CRITERIA;
    }
}
