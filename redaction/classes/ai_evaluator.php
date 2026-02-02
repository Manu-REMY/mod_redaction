<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AI evaluator orchestrator.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/ai_provider/provider_interface.php');
require_once(__DIR__ . '/ai_provider/base_provider.php');
require_once(__DIR__ . '/ai_provider/openai_provider.php');
require_once(__DIR__ . '/ai_provider/anthropic_provider.php');
require_once(__DIR__ . '/ai_provider/mistral_provider.php');
require_once(__DIR__ . '/ai_provider/albert_provider.php');
require_once(__DIR__ . '/ai_config.php');
require_once(__DIR__ . '/ai_prompt_builder.php');
require_once(__DIR__ . '/ai_response_parser.php');

use mod_redaction\ai_provider\provider_interface;
use mod_redaction\ai_provider\openai_provider;
use mod_redaction\ai_provider\anthropic_provider;
use mod_redaction\ai_provider\mistral_provider;
use mod_redaction\ai_provider\albert_provider;

/**
 * Main AI evaluator class.
 */
class ai_evaluator {

    /** @var int Maximum tokens for response */
    const MAX_TOKENS = 2000;

    /**
     * Queue an evaluation for processing.
     *
     * @param int $redactionid Instance ID
     * @param int $submissionid Submission ID
     * @param int $groupid Group ID
     * @param int $userid User ID
     * @return int Evaluation ID
     */
    public static function queue_evaluation(int $redactionid, int $submissionid, int $groupid, int $userid): int {
        global $DB;

        $redaction = $DB->get_record('redaction', ['id' => $redactionid], '*', MUST_EXIST);

        $evaluation = new \stdClass();
        $evaluation->redactionid = $redactionid;
        $evaluation->submissionid = $submissionid;
        $evaluation->groupid = $groupid;
        $evaluation->userid = $userid;
        $evaluation->provider = $redaction->ai_provider;
        $evaluation->model = self::get_model_for_provider($redaction->ai_provider);
        $evaluation->status = 'pending';
        $evaluation->timecreated = time();
        $evaluation->timemodified = time();

        $evaluationid = $DB->insert_record('redaction_ai_evaluations', $evaluation);

        // Queue adhoc task for async processing.
        $task = new \mod_redaction\task\evaluate_submission();
        $task->set_custom_data(['evaluationid' => $evaluationid]);
        \core\task\manager::queue_adhoc_task($task);

        return $evaluationid;
    }

    /**
     * Process an evaluation.
     *
     * @param int $evaluationid
     * @return bool
     */
    public static function process_evaluation(int $evaluationid): bool {
        global $DB;

        $evaluation = $DB->get_record('redaction_ai_evaluations', ['id' => $evaluationid], '*', MUST_EXIST);

        // Update status to processing.
        $evaluation->status = 'processing';
        $evaluation->timemodified = time();
        $DB->update_record('redaction_ai_evaluations', $evaluation);

        try {
            // Get related records.
            $redaction = $DB->get_record('redaction', ['id' => $evaluation->redactionid], '*', MUST_EXIST);
            $submission = $DB->get_record('redaction_submission', ['id' => $evaluation->submissionid], '*', MUST_EXIST);
            $consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
            $correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);

            if (!$consignes) {
                throw new \moodle_exception('error:noconsignes', 'redaction');
            }

            // Build prompts.
            $prompts = ai_prompt_builder::build_prompt($submission, $consignes, $correction ?? new \stdClass());

            // Get API key.
            $apikey = ai_config::get_effective_api_key($redaction->ai_provider, ai_config::decrypt_api_key($redaction->ai_api_key ?? ''));

            // Get provider and call AI.
            $provider = self::get_provider($redaction->ai_provider, $apikey);
            $response = $provider->evaluate(
                $prompts['system'],
                $prompts['user'],
                $evaluation->model,
                self::MAX_TOKENS
            );

            // Parse response.
            $parsed = ai_response_parser::parse($response['content']);

            // Update evaluation with results.
            $evaluation->raw_response = $response['content'];
            $evaluation->prompt_tokens = $response['prompt_tokens'];
            $evaluation->completion_tokens = $response['completion_tokens'];
            $evaluation->parsed_grade = $parsed->grade;
            $evaluation->parsed_feedback = $parsed->feedback;
            $evaluation->criteria_json = json_encode($parsed->criteria);
            $evaluation->status = 'completed';
            $evaluation->timemodified = time();

            $DB->update_record('redaction_ai_evaluations', $evaluation);

            // Auto-apply if configured.
            if ($redaction->ai_auto_apply) {
                self::apply_evaluation($evaluationid, 0); // System auto-apply.
            }

            return true;

        } catch (\Exception $e) {
            // Update status to failed.
            $evaluation->status = 'failed';
            $evaluation->error_message = $e->getMessage();
            $evaluation->timemodified = time();
            $DB->update_record('redaction_ai_evaluations', $evaluation);

            return false;
        }
    }

    /**
     * Get an evaluation for a submission.
     *
     * @param int $submissionid
     * @return object|null
     */
    public static function get_evaluation(int $submissionid): ?object {
        global $DB;

        return $DB->get_record_sql(
            'SELECT * FROM {redaction_ai_evaluations} WHERE submissionid = ? ORDER BY timecreated DESC LIMIT 1',
            [$submissionid]
        ) ?: null;
    }

    /**
     * Apply an evaluation to the submission.
     *
     * @param int $evaluationid
     * @param int $userid User ID of teacher applying (0 for auto)
     * @param float|null $overridegrade Override grade
     * @param string|null $overridefeedback Override feedback
     * @return bool
     */
    public static function apply_evaluation(int $evaluationid, int $userid, ?float $overridegrade = null, ?string $overridefeedback = null): bool {
        global $DB;

        $evaluation = $DB->get_record('redaction_ai_evaluations', ['id' => $evaluationid], '*', MUST_EXIST);

        if ($evaluation->status !== 'completed') {
            return false;
        }

        $submission = $DB->get_record('redaction_submission', ['id' => $evaluation->submissionid], '*', MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $evaluation->redactionid], '*', MUST_EXIST);

        // Apply grade and feedback.
        $submission->grade = $overridegrade ?? $evaluation->parsed_grade;
        $submission->feedback = $overridefeedback ?? $evaluation->parsed_feedback;
        $submission->timemodified = time();
        $DB->update_record('redaction_submission', $submission);

        // Update evaluation status.
        $evaluation->status = 'applied';
        $evaluation->applied_by = $userid;
        $evaluation->applied_at = time();
        $evaluation->timemodified = time();
        $DB->update_record('redaction_ai_evaluations', $evaluation);

        // Update gradebook.
        require_once(__DIR__ . '/../lib.php');
        redaction_update_grades($redaction);

        return true;
    }

    /**
     * Check if a submission has a pending evaluation.
     *
     * @param int $submissionid
     * @return bool
     */
    public static function has_pending_evaluation(int $submissionid): bool {
        global $DB;

        return $DB->record_exists_select(
            'redaction_ai_evaluations',
            'submissionid = ? AND status IN (?, ?)',
            [$submissionid, 'pending', 'processing']
        );
    }

    /**
     * Retry a failed evaluation.
     *
     * @param int $evaluationid
     * @return int New evaluation ID
     */
    public static function retry_evaluation(int $evaluationid): int {
        global $DB;

        $evaluation = $DB->get_record('redaction_ai_evaluations', ['id' => $evaluationid], '*', MUST_EXIST);

        // Reset to pending.
        $evaluation->status = 'pending';
        $evaluation->error_message = null;
        $evaluation->timemodified = time();
        $DB->update_record('redaction_ai_evaluations', $evaluation);

        // Queue task.
        $task = new \mod_redaction\task\evaluate_submission();
        $task->set_custom_data(['evaluationid' => $evaluationid]);
        \core\task\manager::queue_adhoc_task($task);

        return $evaluationid;
    }

    /**
     * Get provider instance.
     *
     * @param string $provider Provider name
     * @param string $apikey API key
     * @return provider_interface
     */
    public static function get_provider(string $provider, string $apikey): provider_interface {
        switch ($provider) {
            case 'openai':
                return new openai_provider($apikey);
            case 'anthropic':
                return new anthropic_provider($apikey);
            case 'mistral':
                return new mistral_provider($apikey);
            case 'albert':
                return new albert_provider($apikey);
            default:
                throw new \moodle_exception('ai_unknown_provider', 'redaction', '', $provider);
        }
    }

    /**
     * Get default model for a provider.
     *
     * @param string $provider
     * @return string
     */
    public static function get_model_for_provider(string $provider): string {
        $instance = self::get_provider($provider, '');
        return $instance->get_default_model();
    }
}
