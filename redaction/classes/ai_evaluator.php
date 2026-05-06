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

    /** @var int Default rate limit: max evaluations per hour per activity */
    const DEFAULT_RATE_LIMIT = 60;

    /**
     * Check rate limiting for AI evaluations.
     *
     * Per-user hourly cap. The earlier per-activity cap blocked the entire
     * class once 60 evaluations had run in an hour, which made iterative
     * training unusable for a 25-student class. Each user now has their own
     * budget, sized for a pathological case (sustained one eval per minute);
     * legitimate use bumps into training_max_attempts long before this.
     *
     * Concurrent evaluations on the same submission are still blocked by
     * redaction_can_submit_attempt() (pending/processing check).
     *
     * @param int $redactionid Activity instance ID
     * @param int $submissionid Submission ID (used to resolve the user)
     * @throws \moodle_exception If hourly rate limit is exceeded for this user.
     */
    public static function check_rate_limit(int $redactionid, int $submissionid): void {
        global $DB, $USER;

        $ratelimit = (int) get_config('mod_redaction', 'ai_rate_limit');
        if ($ratelimit <= 0) {
            $ratelimit = self::DEFAULT_RATE_LIMIT;
        }

        // Resolve the user to count against. Prefer the submission's userid
        // (handles teachers triggering re-eval); fall back to current $USER.
        $submission = $DB->get_record('redaction_submission', ['id' => $submissionid], 'userid', IGNORE_MISSING);
        $userid = ($submission && !empty($submission->userid)) ? (int) $submission->userid : (int) $USER->id;

        $onehourago = time() - 3600;
        $recentcount = $DB->count_records_select(
            'redaction_ai_evaluations',
            'redactionid = ? AND userid = ? AND timecreated > ?',
            [$redactionid, $userid, $onehourago]
        );

        if ($recentcount >= $ratelimit) {
            throw new \moodle_exception('error:rate_limit_exceeded', 'redaction');
        }
    }

    /**
     * Queue an evaluation for processing.
     *
     * @param int $redactionid Instance ID
     * @param int $submissionid Submission ID
     * @param int $groupid Group ID
     * @param int $userid User ID
     * @param bool $skipratelimit Skip rate limiting (for auto-submit tasks)
     * @return int Evaluation ID
     */
    public static function queue_evaluation(int $redactionid, int $submissionid, int $groupid, int $userid, bool $skipratelimit = false): int {
        global $DB;

        // Check rate limiting unless explicitly skipped (e.g., from cron task).
        if (!$skipratelimit) {
            self::check_rate_limit($redactionid, $submissionid);
        }

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

            // Build prompts. Activities in training mode get formative-style guidance
            // (more detailed feedback, concrete reformulations) — see ai_prompt_builder.
            $isformative = !empty($redaction->training_enabled);
            $prompts = ai_prompt_builder::build_prompt(
                $submission,
                $consignes,
                $correction ?? new \stdClass(),
                $isformative
            );

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
            // Store criteria as arrays with level field preserved.
            $criteriaData = [];
            foreach ($parsed->criteria as $criterion) {
                $criteriaData[] = [
                    'name' => $criterion->name,
                    'score' => $criterion->score,
                    'max' => $criterion->max,
                    'comment' => $criterion->comment,
                    'level' => $criterion->level ?? ai_response_parser::calculate_level(
                        $criterion->max > 0 ? ($criterion->score / $criterion->max) * 100 : 0
                    ),
                ];
            }
            $evaluation->criteria_json = json_encode($criteriaData);
            $evaluation->status = 'completed';
            $evaluation->timemodified = time();

            $DB->update_record('redaction_ai_evaluations', $evaluation);

            // Trigger AI evaluation completed event.
            $cm = get_coursemodule_from_instance('redaction', $redaction->id, $redaction->course, false, MUST_EXIST);
            $context = \context_module::instance($cm->id);
            $event = \mod_redaction\event\ai_evaluation_completed::create([
                'objectid' => $evaluation->id,
                'context' => $context,
                'userid' => $evaluation->userid,
                'other' => [
                    'submissionid' => $evaluation->submissionid,
                    'provider' => $evaluation->provider,
                ],
            ]);
            $event->trigger();

            // Auto-apply if configured.
            if ($redaction->ai_auto_apply) {
                $delay = (int) get_config('mod_redaction', 'ai_auto_apply_delay');
                if ($delay > 0) {
                    // Delayed auto-apply: set pending status with scheduled timestamp.
                    $evaluation->status = 'pending_apply';
                    $evaluation->scheduled_apply_at = time() + ($delay * 60);
                    $evaluation->timemodified = time();
                    $DB->update_record('redaction_ai_evaluations', $evaluation);
                } else {
                    // Immediate auto-apply (existing behavior).
                    self::apply_evaluation($evaluationid, 0);
                }
            }

            // Invalidate dashboard cache.
            \mod_redaction\dashboard\submission_stats::invalidate_cache($redaction->id);

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

        $records = $DB->get_records_sql(
            'SELECT * FROM {redaction_ai_evaluations} WHERE submissionid = ? ORDER BY timecreated DESC',
            [$submissionid],
            0,
            1
        );

        return !empty($records) ? reset($records) : null;
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
        $models = [
            'openai' => 'gpt-4o-mini',
            'anthropic' => 'claude-3-5-sonnet-20241022',
            'mistral' => 'mistral-medium-latest',
            'albert' => 'albert-large',
        ];
        return $models[$provider] ?? 'default';
    }
}
