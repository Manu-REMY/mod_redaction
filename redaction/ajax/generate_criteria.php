<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX endpoint to generate criteria and AI instructions from consignes.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

$cmid = required_param('id', PARAM_INT);

// Validate session.
require_sesskey();

// Get course module.
$cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

// Check login.
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/redaction:editconsignes', $context);

header('Content-Type: application/json');

// Check if AI is enabled.
if (!$redaction->ai_enabled) {
    echo json_encode(['success' => false, 'message' => get_string('ai_not_enabled', 'redaction')]);
    exit;
}

// Get consignes.
$consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
if (!$consignes || empty($consignes->consignes)) {
    echo json_encode(['success' => false, 'message' => get_string('error:noconsignes', 'redaction')]);
    exit;
}

try {
    // Get AI provider.
    $providerclass = '\\mod_redaction\\ai_provider\\' . $redaction->ai_provider . '_provider';
    if (!class_exists($providerclass)) {
        throw new Exception(get_string('ai_unknown_provider', 'redaction', $redaction->ai_provider));
    }

    // Get API key.
    $instancekey = \mod_redaction\ai_config::decrypt_api_key($redaction->ai_api_key ?? '');
    $apikey = \mod_redaction\ai_config::get_effective_api_key($redaction->ai_provider, $instancekey);
    if (empty($apikey)) {
        throw new Exception(get_string('ai_api_key_required', 'redaction'));
    }

    $provider = new $providerclass($apikey);

    // Build the prompt for generating criteria.
    $consignestext = strip_tags($consignes->consignes);
    $criterestext = $consignes->criteres ?? '';
    $titre = $consignes->titre ?? '';

    $systemprompt = "Tu es un assistant pédagogique expert en évaluation de travaux d'élèves. " .
        "Tu dois analyser les consignes fournies par l'enseignant et générer :\n" .
        "1. Une grille de critères d'évaluation au format JSON\n" .
        "2. Des instructions détaillées pour l'IA qui évaluera les travaux des élèves\n\n" .
        "La grille de critères doit être un tableau JSON avec le format suivant :\n" .
        '[{"name": "Nom du critère", "weight": 5, "description": "Description du critère"}]' . "\n" .
        "La somme des poids (weight) doit être égale à 20.\n\n" .
        "Les instructions IA doivent être claires et précises, expliquant comment évaluer chaque critère.";

    $userprompt = "Voici les consignes de l'enseignant :\n\n";
    if (!empty($titre)) {
        $userprompt .= "**Titre de l'activité :** {$titre}\n\n";
    }
    $userprompt .= "**Consignes détaillées :**\n{$consignestext}\n\n";
    if (!empty($criterestext)) {
        $userprompt .= "**Critères mentionnés par l'enseignant :**\n{$criterestext}\n\n";
    }
    $userprompt .= "Génère une réponse au format JSON avec deux clés :\n" .
        '- "grille_criteres": un tableau JSON de critères d\'évaluation (somme des poids = 20)' . "\n" .
        '- "ai_instructions": des instructions détaillées en français pour l\'IA évaluatrice' . "\n\n" .
        "Réponds UNIQUEMENT avec le JSON, sans texte avant ou après.";

    // Call AI using the evaluate method.
    $result = $provider->evaluate(
        $systemprompt,
        $userprompt,
        $provider->get_default_model(),
        2000
    );

    $response = $result['content'] ?? '';

    if (empty($response)) {
        throw new Exception(get_string('ai_invalid_response', 'redaction'));
    }

    // Parse JSON response.
    // Clean up the response - remove markdown code blocks if present.
    $response = trim($response);
    $response = preg_replace('/^```json\s*/i', '', $response);
    $response = preg_replace('/^```\s*/i', '', $response);
    $response = preg_replace('/\s*```$/i', '', $response);

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // Try to extract JSON from the response.
        if (preg_match('/\{[\s\S]*\}/m', $response, $matches)) {
            $data = json_decode($matches[0], true);
        }
    }

    if (!$data || !isset($data['grille_criteres']) || !isset($data['ai_instructions'])) {
        throw new Exception(get_string('ai_parse_error', 'redaction'));
    }

    // Format criteria as pretty JSON.
    $grillecriteres = json_encode($data['grille_criteres'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    echo json_encode([
        'success' => true,
        'grille_criteres' => $grillecriteres,
        'ai_instructions' => $data['ai_instructions']
    ]);

} catch (\moodle_exception $e) {
    // Moodle exceptions include debug info.
    $message = $e->getMessage();
    if ($e->debuginfo) {
        $message .= ' - ' . $e->debuginfo;
    }
    echo json_encode(['success' => false, 'message' => $message]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
