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
 * External function for AI-assisted criteria generation.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/redaction/lib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_module;

/**
 * External function to generate evaluation criteria using AI.
 */
class generate_criteria extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    /**
     * Generate criteria via AI.
     *
     * @param int $cmid Course module ID
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
        ]);

        $cm = get_coursemodule_from_id('redaction', $params['cmid'], 0, false, MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/redaction:editconsignes', $context);

        if (!$redaction->ai_enabled) {
            return [
                'success' => false,
                'grille_criteres' => '',
                'ai_instructions' => '',
                'message' => get_string('ai_not_enabled', 'mod_redaction'),
            ];
        }

        $consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
        if (!$consignes || empty($consignes->consignes)) {
            return [
                'success' => false,
                'grille_criteres' => '',
                'ai_instructions' => '',
                'message' => get_string('error:noconsignes', 'mod_redaction'),
            ];
        }

        // Get AI provider.
        $providerclass = '\\mod_redaction\\ai_provider\\' . $redaction->ai_provider . '_provider';
        if (!class_exists($providerclass)) {
            throw new \moodle_exception('ai_unknown_provider', 'mod_redaction', '', $redaction->ai_provider);
        }

        $instancekey = \mod_redaction\ai_config::decrypt_api_key($redaction->ai_api_key ?? '');
        $apikey = \mod_redaction\ai_config::get_effective_api_key($redaction->ai_provider, $instancekey);
        if (empty($apikey)) {
            throw new \moodle_exception('ai_api_key_required', 'mod_redaction');
        }

        $provider = new $providerclass($apikey);

        $consignestext = strip_tags($consignes->consignes);
        $criterestext = $consignes->criteres ?? '';
        $titre = $consignes->titre ?? '';

        $systemprompt = get_string('ai_generate_criteria_system_prompt', 'mod_redaction');
        $userprompt = get_string('ai_generate_criteria_user_prompt', 'mod_redaction', (object) [
            'titre' => $titre,
            'consignes' => $consignestext,
            'criteres' => $criterestext,
        ]);

        $result = $provider->evaluate(
            $systemprompt,
            $userprompt,
            $provider->get_default_model(),
            2000
        );

        $response = $result['content'] ?? '';

        if (empty($response)) {
            throw new \moodle_exception('ai_invalid_response', 'mod_redaction');
        }

        // Parse JSON response.
        $response = trim($response);
        $response = preg_replace('/^```json\s*/i', '', $response);
        $response = preg_replace('/^```\s*/i', '', $response);
        $response = preg_replace('/\s*```$/i', '', $response);

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if (preg_match('/\{[\s\S]*\}/m', $response, $matches)) {
                $data = json_decode($matches[0], true);
            }
        }

        if (!$data || !isset($data['grille_criteres']) || !isset($data['ai_instructions'])) {
            throw new \moodle_exception('ai_parse_error', 'mod_redaction');
        }

        $grillecriteres = json_encode($data['grille_criteres'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return [
            'success' => true,
            'grille_criteres' => $grillecriteres,
            'ai_instructions' => $data['ai_instructions'],
            'message' => get_string('ai_generate_success', 'mod_redaction'),
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation succeeded'),
            'grille_criteres' => new external_value(PARAM_RAW, 'Generated criteria grid JSON'),
            'ai_instructions' => new external_value(PARAM_RAW, 'Generated AI instructions'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
        ]);
    }
}
