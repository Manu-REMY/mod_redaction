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
 * AI summary generator for teacher dashboard.
 *
 * Analyzes all AI evaluations for an activity and generates
 * a synthesis of common difficulties, strengths, and recommendations.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\dashboard;

defined('MOODLE_INTERNAL') || die();

use mod_redaction\ai_config;
use mod_redaction\ai_evaluator;

/**
 * Class for generating AI summaries of student feedback.
 */
class ai_summary_generator {

    /** @var int Minimum number of evaluations required for synthesis */
    const MIN_EVALUATIONS = 1;

    /** @var int Cache duration in seconds (1 hour) */
    const CACHE_DURATION = 3600;

    /** @var int The redaction instance ID */
    protected $redactionid;

    /** @var object The redaction instance record */
    protected $redaction;

    /** @var int Group ID filter (0 = global summary) */
    protected $groupid;

    /**
     * Constructor.
     *
     * @param int $redactionid The redaction instance ID
     * @param int $groupid Group ID filter (0 = global summary)
     */
    public function __construct(int $redactionid, int $groupid = 0) {
        global $DB;

        $this->redactionid = $redactionid;
        $this->groupid = $groupid;
        $this->redaction = $DB->get_record('redaction', ['id' => $redactionid], '*', MUST_EXIST);
    }

    /**
     * Get or generate the AI summary for this activity.
     *
     * Behaviour:
     *  - Cached summary exists → return it as-is (any age). Stale cache is fine
     *    on a page render; the user can click "Refresh" to update.
     *  - No cache yet AND enough evaluations AND AI enabled → generate
     *    synchronously so the user always sees content on first visit.
     *  - $force=true → always regenerate (used by the "Refresh" AJAX call).
     *
     * The synchronous generation happens at most ONCE per redaction (when the
     * cache is empty), so it never blocks subsequent renders — those return
     * the cached version instantly.
     *
     * @param bool $force Force regeneration (used by the explicit "Refresh" action)
     * @return object|null The summary object or null if not enough data
     */
    public function get_summary(bool $force = false): ?object {
        global $DB;

        $summary = $DB->get_record('redaction_ai_summaries', [
            'redactionid' => $this->redactionid,
            'groupid' => $this->groupid,
        ]);

        // Cached summary on a page render: return it regardless of age.
        if ($summary && !$force) {
            return $this->format_summary($summary);
        }

        // First-time generation OR explicit refresh: hit the AI.
        $evaluations = $this->get_completed_evaluations();
        if (count($evaluations) < self::MIN_EVALUATIONS) {
            return null;
        }
        if (!$this->redaction->ai_enabled) {
            return null;
        }

        $newSummary = $this->generate_summary($evaluations);
        if ($newSummary) {
            $this->save_summary($newSummary, count($evaluations));
            return $this->format_summary($newSummary);
        }
        return null;
    }

    /**
     * Get completed or applied evaluations for this activity with optional pagination.
     *
     * @param int $page Page number for pagination (0-based)
     * @param int $perpage Number of records per page (0 = all)
     * @return array
     */
    protected function get_completed_evaluations(int $page = 0, int $perpage = 0): array {
        global $DB;

        $userfilter = '';
        $userparams = [];
        if ($this->groupid > 0) {
            require_once($GLOBALS['CFG']->dirroot . '/mod/redaction/lib.php');
            $userids = redaction_get_filtered_userids($this->redaction->course, $this->groupid);
            if (empty($userids)) {
                return [];
            }
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_QM);
            $userfilter = ' AND userid ' . $insql;
            $userparams = $inparams;
        }

        $sql = 'SELECT *
                FROM {redaction_ai_evaluations}
                WHERE redactionid = ? AND status IN (?, ?)' . $userfilter . '
                ORDER BY timecreated DESC';
        $params = array_merge([$this->redactionid, 'completed', 'applied'], $userparams);

        if ($perpage > 0) {
            return $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);
        }

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Count total completed or applied evaluations for this activity.
     *
     * Used for pagination UI alongside get_completed_evaluations().
     *
     * @return int Total number of completed/applied evaluations
     */
    protected function count_completed_evaluations(): int {
        global $DB;

        return $DB->count_records_select(
            'redaction_ai_evaluations',
            'redactionid = ? AND status IN (?, ?)',
            [$this->redactionid, 'completed', 'applied']
        );
    }

    /**
     * Generate a synthesis from all evaluations.
     *
     * @param array $evaluations The evaluations to analyze
     * @return object|null The generated summary data
     */
    protected function generate_summary(array $evaluations): ?object {
        // Get AI configuration.
        $config = ai_config::get_config($this->redactionid);
        if (!$config || !$config->enabled) {
            return null;
        }

        // Build the synthesis prompt.
        $userprompt = $this->build_synthesis_prompt($evaluations);
        $systemprompt = "Tu es un assistant pedagogique expert. Tu analyses des feedbacks d'evaluation et generes des syntheses structurees au format JSON.";

        try {
            // Get the AI provider.
            $apiKey = ai_config::get_effective_api_key($config->provider, $config->api_key);
            $provider = ai_evaluator::get_provider($config->provider, $apiKey);

            // Call the AI using the evaluate method.
            $model = $provider->get_default_model();
            $response = $provider->evaluate($systemprompt, $userprompt, $model, 2000);

            // Parse the response.
            $parsed = $this->parse_synthesis_response($response['content']);

            if ($parsed) {
                $parsed->provider = $config->provider;
                $parsed->model = $model;
                $parsed->prompt_tokens = $response['prompt_tokens'] ?? 0;
                $parsed->completion_tokens = $response['completion_tokens'] ?? 0;
            }

            return $parsed;

        } catch (\Exception $e) {
            debugging('AI summary generation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Build the synthesis prompt from evaluations.
     *
     * @param array $evaluations
     * @return string
     */
    protected function build_synthesis_prompt(array $evaluations): string {
        $feedbackList = [];

        foreach ($evaluations as $eval) {
            $entry = [];

            if (!empty($eval->parsed_grade)) {
                $entry[] = "Note: {$eval->parsed_grade}/20";
            }

            if (!empty($eval->parsed_feedback)) {
                // Truncate long feedback.
                $feedback = strip_tags($eval->parsed_feedback);
                if (strlen($feedback) > 500) {
                    $feedback = substr($feedback, 0, 500) . '...';
                }
                $entry[] = "Feedback: " . $feedback;
            }

            if (!empty($eval->criteria_json)) {
                $criteria = json_decode($eval->criteria_json, true);
                if (is_array($criteria)) {
                    $criteriaTexts = [];
                    foreach ($criteria as $c) {
                        if (isset($c['name'], $c['score'], $c['max'])) {
                            $criteriaTexts[] = "{$c['name']}: {$c['score']}/{$c['max']}";
                            if (!empty($c['comment'])) {
                                $criteriaTexts[] = "  -> " . substr($c['comment'], 0, 200);
                            }
                        }
                    }
                    if (!empty($criteriaTexts)) {
                        $entry[] = "Criteres:\n" . implode("\n", $criteriaTexts);
                    }
                }
            }

            if (!empty($entry)) {
                $feedbackList[] = "--- Evaluation " . count($feedbackList) + 1 . " ---\n" . implode("\n", $entry);
            }
        }

        $feedbackText = implode("\n\n", $feedbackList);
        $count = count($evaluations);

        return <<<PROMPT
Tu es un assistant pedagogique expert. Analyse les {$count} feedbacks IA suivants pour cette activite de redaction et genere une synthese structuree.

FEEDBACKS A ANALYSER:
{$feedbackText}

CONSIGNES:
1. Identifie les 3-5 difficultes les plus frequentes chez les eleves
2. Identifie les 3-5 points forts recurrents
3. Propose 2-4 recommandations pedagogiques concretes pour l'enseignant
4. Redige une observation generale de 2-3 phrases

IMPORTANT: Reponds UNIQUEMENT avec un objet JSON valide, sans texte avant ou apres, au format suivant:
{
    "difficulties": ["difficulte 1", "difficulte 2", "difficulte 3"],
    "strengths": ["point fort 1", "point fort 2", "point fort 3"],
    "recommendations": ["recommandation 1", "recommandation 2"],
    "general_observation": "Observation generale en 2-3 phrases."
}
PROMPT;
    }

    /**
     * Parse the AI synthesis response.
     *
     * @param string $response The AI response text
     * @return object|null Parsed data or null on failure
     */
    protected function parse_synthesis_response(string $response): ?object {
        // Try to extract JSON from the response.
        $jsonMatch = [];
        if (preg_match('/\{[\s\S]*\}/', $response, $jsonMatch)) {
            $json = $jsonMatch[0];
        } else {
            $json = $response;
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            debugging('Failed to parse AI summary response as JSON', DEBUG_DEVELOPER);
            return null;
        }

        $result = new \stdClass();
        $result->difficulties = isset($data['difficulties']) && is_array($data['difficulties'])
            ? json_encode($data['difficulties'], JSON_UNESCAPED_UNICODE)
            : '[]';
        $result->strengths = isset($data['strengths']) && is_array($data['strengths'])
            ? json_encode($data['strengths'], JSON_UNESCAPED_UNICODE)
            : '[]';
        $result->recommendations = isset($data['recommendations']) && is_array($data['recommendations'])
            ? json_encode($data['recommendations'], JSON_UNESCAPED_UNICODE)
            : '[]';
        $result->general_observation = $data['general_observation'] ?? '';

        return $result;
    }

    /**
     * Save the summary to database.
     *
     * @param object $summaryData The summary data to save
     * @param int $submissionsAnalyzed Number of submissions analyzed
     */
    protected function save_summary(object $summaryData, int $submissionsAnalyzed): void {
        global $DB;

        $now = time();

        $existing = $DB->get_record('redaction_ai_summaries', [
            'redactionid' => $this->redactionid,
            'groupid' => $this->groupid,
        ]);

        if ($existing) {
            $existing->difficulties = $summaryData->difficulties;
            $existing->strengths = $summaryData->strengths;
            $existing->recommendations = $summaryData->recommendations;
            $existing->general_observation = $summaryData->general_observation;
            $existing->submissions_analyzed = $submissionsAnalyzed;
            $existing->provider = $summaryData->provider ?? null;
            $existing->model = $summaryData->model ?? null;
            $existing->prompt_tokens = $summaryData->prompt_tokens ?? null;
            $existing->completion_tokens = $summaryData->completion_tokens ?? null;
            $existing->groupid = $this->groupid;
            $existing->timemodified = $now;

            $DB->update_record('redaction_ai_summaries', $existing);
        } else {
            $record = new \stdClass();
            $record->redactionid = $this->redactionid;
            $record->groupid = $this->groupid;
            $record->difficulties = $summaryData->difficulties;
            $record->strengths = $summaryData->strengths;
            $record->recommendations = $summaryData->recommendations;
            $record->general_observation = $summaryData->general_observation;
            $record->submissions_analyzed = $submissionsAnalyzed;
            $record->provider = $summaryData->provider ?? null;
            $record->model = $summaryData->model ?? null;
            $record->prompt_tokens = $summaryData->prompt_tokens ?? null;
            $record->completion_tokens = $summaryData->completion_tokens ?? null;
            $record->timecreated = $now;
            $record->timemodified = $now;

            $DB->insert_record('redaction_ai_summaries', $record);
        }
    }

    /**
     * Format the summary for display.
     *
     * @param object $summary The raw summary record
     * @return object Formatted summary
     */
    protected function format_summary(object $summary): object {
        $formatted = new \stdClass();

        $formatted->difficulties = json_decode($summary->difficulties ?? '[]', true) ?: [];
        $formatted->strengths = json_decode($summary->strengths ?? '[]', true) ?: [];
        $formatted->recommendations = json_decode($summary->recommendations ?? '[]', true) ?: [];
        $formatted->general_observation = $summary->general_observation ?? '';
        $formatted->submissions_analyzed = $summary->submissions_analyzed ?? 0;
        $formatted->provider = $summary->provider ?? '';
        $formatted->model = $summary->model ?? '';
        $formatted->prompt_tokens = $summary->prompt_tokens ?? 0;
        $formatted->completion_tokens = $summary->completion_tokens ?? 0;
        $formatted->timemodified = $summary->timemodified ?? 0;
        $formatted->cache_expires = ($summary->timemodified ?? 0) + self::CACHE_DURATION;

        return $formatted;
    }

    /**
     * Delete the cached summary (force fresh generation on next request).
     */
    public function invalidate_cache(): void {
        global $DB;

        $DB->delete_records('redaction_ai_summaries', [
            'redactionid' => $this->redactionid,
            'groupid' => $this->groupid,
        ]);
    }
}
