<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Token statistics for teacher dashboard.
 *
 * Tracks AI API token usage across evaluations.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\dashboard;

defined('MOODLE_INTERNAL') || die();

/**
 * Class for calculating AI token usage statistics.
 */
class token_stats {

    /** @var int The redaction instance ID */
    protected $redactionid;

    /**
     * Constructor.
     *
     * @param int $redactionid The redaction instance ID
     */
    public function __construct(int $redactionid) {
        $this->redactionid = $redactionid;
    }

    /**
     * Get all token statistics.
     *
     * @return object Statistics object
     */
    public function get_stats(): object {
        global $DB;

        $stats = new \stdClass();

        // Get totals from evaluations.
        $sql = "SELECT
                    COALESCE(SUM(prompt_tokens), 0) as total_prompt,
                    COALESCE(SUM(completion_tokens), 0) as total_completion,
                    COUNT(*) as evaluation_count
                FROM {redaction_ai_evaluations}
                WHERE redactionid = ? AND status IN ('completed', 'applied')";

        $evalTokens = $DB->get_record_sql($sql, [$this->redactionid]);

        // Get totals from summaries.
        $sql = "SELECT
                    COALESCE(SUM(prompt_tokens), 0) as total_prompt,
                    COALESCE(SUM(completion_tokens), 0) as total_completion
                FROM {redaction_ai_summaries}
                WHERE redactionid = ?";

        $summaryTokens = $DB->get_record_sql($sql, [$this->redactionid]);

        // Calculate totals.
        $stats->prompt_tokens = ($evalTokens->total_prompt ?? 0) + ($summaryTokens->total_prompt ?? 0);
        $stats->completion_tokens = ($evalTokens->total_completion ?? 0) + ($summaryTokens->total_completion ?? 0);
        $stats->total_tokens = $stats->prompt_tokens + $stats->completion_tokens;
        $stats->evaluation_count = $evalTokens->evaluation_count ?? 0;

        // Get usage by provider.
        $stats->by_provider = $this->get_usage_by_provider();

        // Estimate costs (rough estimates based on typical pricing).
        $stats->estimated_cost = $this->estimate_cost($stats->by_provider);

        return $stats;
    }

    /**
     * Get token usage broken down by provider.
     *
     * @return array
     */
    protected function get_usage_by_provider(): array {
        global $DB;

        $sql = "SELECT
                    provider,
                    COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                    COALESCE(SUM(completion_tokens), 0) as completion_tokens,
                    COUNT(*) as request_count
                FROM {redaction_ai_evaluations}
                WHERE redactionid = ? AND status IN ('completed', 'applied')
                GROUP BY provider";

        $evalUsage = $DB->get_records_sql($sql, [$this->redactionid]);

        // Also include summary generation.
        $sql = "SELECT
                    provider,
                    COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                    COALESCE(SUM(completion_tokens), 0) as completion_tokens,
                    COUNT(*) as request_count
                FROM {redaction_ai_summaries}
                WHERE redactionid = ? AND provider IS NOT NULL
                GROUP BY provider";

        $summaryUsage = $DB->get_records_sql($sql, [$this->redactionid]);

        // Merge results.
        $result = [];
        foreach ($evalUsage as $row) {
            if (!isset($result[$row->provider])) {
                $result[$row->provider] = [
                    'provider' => $row->provider,
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                    'request_count' => 0,
                ];
            }
            $result[$row->provider]['prompt_tokens'] += $row->prompt_tokens;
            $result[$row->provider]['completion_tokens'] += $row->completion_tokens;
            $result[$row->provider]['total_tokens'] += ($row->prompt_tokens + $row->completion_tokens);
            $result[$row->provider]['request_count'] += $row->request_count;
        }

        foreach ($summaryUsage as $row) {
            if (!isset($result[$row->provider])) {
                $result[$row->provider] = [
                    'provider' => $row->provider,
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                    'request_count' => 0,
                ];
            }
            $result[$row->provider]['prompt_tokens'] += $row->prompt_tokens;
            $result[$row->provider]['completion_tokens'] += $row->completion_tokens;
            $result[$row->provider]['total_tokens'] += ($row->prompt_tokens + $row->completion_tokens);
            $result[$row->provider]['request_count'] += $row->request_count;
        }

        return array_values($result);
    }

    /**
     * Estimate cost based on token usage and provider.
     *
     * Note: These are rough estimates. Actual costs depend on specific models used.
     *
     * @param array $byProvider Usage by provider
     * @return float Estimated cost in USD
     */
    protected function estimate_cost(array $byProvider): float {
        $totalCost = 0.0;

        // Approximate pricing per 1M tokens (as of 2024).
        $pricing = [
            'openai' => ['input' => 2.50, 'output' => 10.00],       // GPT-4 Turbo
            'anthropic' => ['input' => 3.00, 'output' => 15.00],   // Claude 3 Sonnet
            'mistral' => ['input' => 0.25, 'output' => 0.25],      // Mistral Medium
            'albert' => ['input' => 0.00, 'output' => 0.00],       // Free (public service)
        ];

        foreach ($byProvider as $usage) {
            $provider = strtolower($usage['provider']);
            if (isset($pricing[$provider])) {
                $inputCost = ($usage['prompt_tokens'] / 1000000) * $pricing[$provider]['input'];
                $outputCost = ($usage['completion_tokens'] / 1000000) * $pricing[$provider]['output'];
                $totalCost += $inputCost + $outputCost;
            }
        }

        return round($totalCost, 4);
    }

    /**
     * Get token usage over time (for charts).
     *
     * @param int $days Number of days to look back
     * @return array Daily usage data
     */
    public function get_usage_over_time(int $days = 30): array {
        global $DB;

        $startTime = time() - ($days * 86400);

        $sql = "SELECT
                    DATE(FROM_UNIXTIME(timecreated)) as date,
                    COALESCE(SUM(prompt_tokens), 0) as prompt_tokens,
                    COALESCE(SUM(completion_tokens), 0) as completion_tokens,
                    COUNT(*) as request_count
                FROM {redaction_ai_evaluations}
                WHERE redactionid = ? AND timecreated >= ?
                GROUP BY DATE(FROM_UNIXTIME(timecreated))
                ORDER BY date";

        $results = $DB->get_records_sql($sql, [$this->redactionid, $startTime]);

        $data = [];
        foreach ($results as $row) {
            $data[] = [
                'date' => $row->date,
                'prompt_tokens' => (int) $row->prompt_tokens,
                'completion_tokens' => (int) $row->completion_tokens,
                'total_tokens' => (int) ($row->prompt_tokens + $row->completion_tokens),
                'request_count' => (int) $row->request_count,
            ];
        }

        return $data;
    }
}
