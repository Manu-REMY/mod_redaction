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

    /** @var int Timeout of one call in the assigned subjects mode, in seconds. */
    const SUJET_DELAI_APPEL = 30;

    /** @var int Retries of one call in the assigned subjects mode. */
    const SUJET_REPRISES = 1;

    /** @var int Default rate limit: max evaluations per hour per activity */
    const DEFAULT_RATE_LIMIT = 60;

    /** @var int Seconds after which an evaluation of the assigned subjects mode counts as stuck. */
    const DELAI_BLOCAGE = 1800;

    /**
     * @var int Seconds after which a pending evaluation of the assigned subjects mode counts as stuck.
     *
     * Much longer than for a processing one: a pending evaluation only waits for the cron, and a cron outage
     * must not fail work that would have been graded.
     */
    const DELAI_BLOCAGE_ATTENTE = 86400;

    /** @var int Seconds to wait for the lock of an evaluation of the assigned subjects mode. */
    const LOCK_TIMEOUT = 5;

    /** @var provider_interface|null Stand-in provider for the CLI checks, ignored outside the command line. */
    public static ?provider_interface $fournisseurtest = null;

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

        // The grade goes to the members of the group at this moment, whatever happens to the group later.
        if (!empty($redaction->sujets_enabled)) {
            foreach (groups_get_members($groupid, 'u.id') as $membre) {
                $DB->insert_record('redaction_sujet_membre', (object) ['evaluationid' => $evaluationid, 'userid' => $membre->id]);
            }
        }

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

        if ($DB->get_field('redaction', 'sujets_enabled', ['id' => $evaluation->redactionid])) {
            return self::process_evaluation_sujets($evaluation);
        }

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
            $evaluation->parsed_feedback = $parsed->feedback;
            // Store criteria as arrays with level field preserved.
            $criteriaData = [];
            $criteriaScoreSum = 0.0;
            $criteriaMaxSum = 0.0;
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
                $criteriaScoreSum += (float) ($criterion->score ?? 0);
                $criteriaMaxSum += (float) ($criterion->max ?? 0);
            }
            $evaluation->criteria_json = json_encode($criteriaData);

            // Overall grade: prefer sum of criteria scaled to /20 when criteria
            // are present and total > 0 (matches what the teacher sees in the
            // detail view and the progression overview). The AI's holistic
            // grade is used only as a fallback when no criteria are returned.
            if ($criteriaMaxSum > 0) {
                $evaluation->parsed_grade = round(($criteriaScoreSum / $criteriaMaxSum) * 20, 2);
            } else {
                $evaluation->parsed_grade = $parsed->grade;
            }
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
     * Process an evaluation of the assigned subjects mode: one AI call per subject, applied at once.
     *
     * Any error leaves the evaluation failed, without a grade, and gives the attempt back to the group.
     * Every change of status happens under the lock of the evaluation, after reading the status again,
     * so an evaluation never gets both a grade and a refund, nor two refunds.
     *
     * @param \stdClass $evaluation Evaluation record.
     * @return bool
     */
    protected static function process_evaluation_sujets(\stdClass $evaluation): bool {
        global $DB;

        $lock = self::verrou_evaluation($evaluation->id);
        try {
            // A late run of an evaluation already settled must neither grade nor refund.
            $statut = $DB->get_field('redaction_ai_evaluations', 'status', ['id' => $evaluation->id]);
            if (!in_array($statut, ['pending', 'processing'], true)) {
                return false;
            }
            $evaluation->status = 'processing';
            $evaluation->timemodified = time();
            $DB->update_record('redaction_ai_evaluations', $evaluation);
        } finally {
            $lock->release();
        }

        $bruts = [];
        try {
            $redaction = $DB->get_record('redaction', ['id' => $evaluation->redactionid], '*', MUST_EXIST);
            $submission = $DB->get_record('redaction_submission', ['id' => $evaluation->submissionid], '*', MUST_EXIST);
            $reponses = $DB->get_records('redaction_sujet_reponse', ['submissionid' => $submission->id], '', 'sujetid, contenu, contenuformat');

            $apikey = ai_config::get_effective_api_key($redaction->ai_provider, ai_config::decrypt_api_key($redaction->ai_api_key ?? ''));
            $provider = self::get_provider($redaction->ai_provider, $apikey);
            if ($provider instanceof \mod_redaction\ai_provider\base_provider) {
                // The group is waiting: at most 2 calls of 30 s by answer, so a failure shows within about 2 minutes.
                $provider->limiter_attente(self::SUJET_DELAI_APPEL, self::SUJET_REPRISES);
            }

            $details = [];
            $notes = [];
            $commentaires = [];
            $prompttokens = 0;
            $completiontokens = 0;
            foreach (sujets::du_groupe($redaction, (int) $submission->groupid) as $sujet) {
                if (notes_sujets::texte_reponse($sujet->corrige, notes_sujets::FORMAT_TEXTE) === '') {
                    throw new \moodle_exception('sujets_corrige_vide', 'mod_redaction');
                }
                $reponse = $reponses[$sujet->id] ?? null;
                if (!$reponse || notes_sujets::texte_reponse($reponse->contenu, (int) $reponse->contenuformat) === '') {
                    throw new \moodle_exception('sujets_reponse_vide', 'mod_redaction');
                }
                $prompts = ai_prompt_builder::build_sujet_prompt($sujet, $reponse->contenu, (int) $reponse->contenuformat);
                $response = $provider->evaluate($prompts['system'], $prompts['user'], $evaluation->model, self::MAX_TOKENS);
                $bruts[] = $response['content'];
                $prompttokens += $response['prompt_tokens'];
                $completiontokens += $response['completion_tokens'];

                $lu = notes_sujets::lire_reponse_ia($response['content']);
                $lu['commentaire'] = notes_sujets::commentaire_final($lu, (float) $redaction->sujets_seuil, self::phrases_commentaire());
                $notes[] = $lu['note'];
                $commentaires[] = $sujet->titre . get_string('labelsep', 'langconfig') . $lu['commentaire'];
                $details[] = [
                    'sujetid' => (int) $sujet->id,
                    'titre' => $sujet->titre,
                    'note' => $lu['note'],
                    'commentaire' => $lu['commentaire'],
                ];
            }

            $evaluation->raw_response = json_encode($bruts, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $evaluation->prompt_tokens = $prompttokens;
            $evaluation->completion_tokens = $completiontokens;
            $evaluation->parsed_grade = notes_sujets::note_soumission($notes);
            $evaluation->parsed_feedback = implode("\n\n", $commentaires);
            $evaluation->sujets_json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $evaluation->status = 'completed';
            $evaluation->timemodified = time();

            $cm = get_coursemodule_from_instance('redaction', $redaction->id, $redaction->course, false, MUST_EXIST);

            $lock = self::verrou_evaluation($evaluation->id);
            try {
                // The sweep may have failed this evaluation while the provider was answering.
                if ($DB->get_field('redaction_ai_evaluations', 'status', ['id' => $evaluation->id]) !== 'processing') {
                    return false;
                }

                // Grade and status go together: a gradebook error leaves neither.
                $transaction = $DB->start_delegated_transaction();
                try {
                    $DB->update_record('redaction_ai_evaluations', $evaluation);

                    \mod_redaction\event\ai_evaluation_completed::create([
                        'objectid' => $evaluation->id,
                        'context' => \context_module::instance($cm->id),
                        'userid' => $evaluation->userid,
                        'other' => [
                            'submissionid' => $evaluation->submissionid,
                            'provider' => $evaluation->provider,
                        ],
                    ])->trigger();

                    \mod_redaction\dashboard\submission_stats::invalidate_cache($redaction->id);

                    // Never delayed in this mode, whatever ai_auto_apply_delay says.
                    self::apply_evaluation($evaluation->id, 0);

                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    $transaction->rollback($e);
                }
            } finally {
                $lock->release();
            }

            return true;

        } catch (\Throwable $e) {
            self::echouer_sujets($evaluation, $e->getMessage(), $bruts);

            return false;
        }
    }

    /**
     * Fail an unfinished evaluation of the assigned subjects mode: no grade, and the attempt goes back to the group.
     *
     * An evaluation that is already settled is left alone, so that the attempt is never given back twice.
     *
     * @param \stdClass $evaluation Evaluation record.
     * @param string $message Error to keep on the evaluation.
     * @param array $bruts Raw answers received from the provider so far.
     * @return bool True if this call failed the evaluation.
     */
    protected static function echouer_sujets(\stdClass $evaluation, string $message, array $bruts = []): bool {
        global $DB;

        $lock = self::verrou_evaluation($evaluation->id);
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $statut = $DB->get_field('redaction_ai_evaluations', 'status', ['id' => $evaluation->id]);
                $afaire = in_array($statut, ['pending', 'processing'], true);
                if ($afaire) {
                    $evaluation->status = 'failed';
                    $evaluation->error_message = $message;
                    $evaluation->raw_response = json_encode($bruts, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                    $evaluation->parsed_grade = null;
                    $evaluation->parsed_feedback = null;
                    $evaluation->sujets_json = null;
                    $evaluation->timemodified = time();
                    $DB->update_record('redaction_ai_evaluations', $evaluation);

                    $DB->execute(
                        'UPDATE {redaction_submission} SET training_count = training_count - 1 WHERE id = ? AND training_count > 0',
                        [$evaluation->submissionid]
                    );
                }

                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }

        return $afaire;
    }

    /**
     * Take the lock of an evaluation of the assigned subjects mode.
     *
     * @param int $evaluationid
     * @return \core\lock\lock
     * @throws \moodle_exception When the lock cannot be obtained.
     */
    protected static function verrou_evaluation(int $evaluationid): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory('mod_redaction');
        $lock = $factory->get_lock('redaction_evaluation_' . $evaluationid, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('sujets_verrou_correction', 'mod_redaction');
        }
        return $lock;
    }

    /**
     * Fail the evaluations of the assigned subjects mode that have not moved for too long.
     *
     * A task killed halfway leaves its evaluation processing, which blocks the group. A pending one is only
     * failed after a much longer delay, since it may just be waiting for the cron.
     *
     * @return int Number of evaluations failed.
     */
    public static function echouer_corrections_bloquees(): int {
        global $DB;

        $evaluations = $DB->get_records_sql(
            "SELECT e.*
               FROM {redaction_ai_evaluations} e
               JOIN {redaction} r ON r.id = e.redactionid
              WHERE r.sujets_enabled = 1
                AND ((e.status = 'processing' AND e.timemodified < ?)
                  OR (e.status = 'pending' AND e.timemodified < ?))",
            [time() - self::DELAI_BLOCAGE, time() - self::DELAI_BLOCAGE_ATTENTE]
        );

        $echecs = 0;
        foreach ($evaluations as $evaluation) {
            try {
                if (self::echouer_sujets($evaluation, get_string('sujets_correction_bloquee', 'mod_redaction'))) {
                    $echecs++;
                }
            } catch (\Exception $e) {
                // The next sweep tries again; the other evaluations and the ordinary grades must not wait.
                mtrace("  ERROR failing stuck evaluation {$evaluation->id}: " . $e->getMessage());
            }
        }
        return $echecs;
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

        $sujetsenabled = (bool) $DB->get_field('redaction', 'sujets_enabled', ['id' => $evaluation->redactionid]);
        if ($evaluation->status !== 'completed' && !($sujetsenabled && $evaluation->status === 'pending_apply')) {
            return false;
        }

        $submission = $DB->get_record('redaction_submission', ['id' => $evaluation->submissionid], '*', MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $evaluation->redactionid], '*', MUST_EXIST);

        // In the assigned subjects mode, the grade, the status and the gradebook go together.
        $transaction = $sujetsenabled ? $DB->start_delegated_transaction() : null;
        try {
            // Apply grade and feedback.
            if ($sujetsenabled) {
                // A worse submission never lowers the grade: it stays the best one, or the teacher's.
                if ($overridegrade !== null) {
                    $submission->grade_enseignant = $overridegrade;
                }
                $notes = $DB->get_fieldset_select(
                    'redaction_ai_evaluations',
                    'parsed_grade',
                    "submissionid = ? AND id <> ? AND status IN ('completed', 'applied')",
                    [$submission->id, $evaluation->id]
                );
                $notes[] = $evaluation->parsed_grade;
                $enseignant = $submission->grade_enseignant !== null ? (float) $submission->grade_enseignant : null;
                $submission->grade = notes_sujets::meilleure($notes, $enseignant);
            } else {
                $submission->grade = $overridegrade ?? $evaluation->parsed_grade;
            }
            // The comment of the teacher stays while the teacher grade stands: the next correction must not erase it.
            if (!($sujetsenabled && $submission->grade_enseignant !== null && $overridefeedback === null)) {
                $submission->feedback = $overridefeedback ?? $evaluation->parsed_feedback;
            }
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
            $resultat = redaction_update_grades($redaction);

            // A locked grade item is the teacher's choice, not a failure.
            if ($sujetsenabled && in_array($resultat, [GRADE_UPDATE_FAILED, GRADE_UPDATE_MULTIPLE], true)) {
                throw new \moodle_exception('sujets_carnet_echec', 'mod_redaction');
            }

            if ($transaction) {
                $transaction->allow_commit();
            }
        } catch (\Throwable $e) {
            if ($transaction) {
                $transaction->rollback($e);
            }
            throw $e;
        }

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

        // A failure has already given the attempt back: a retry would refund twice, or grade for free.
        if ($DB->get_field('redaction', 'sujets_enabled', ['id' => $evaluation->redactionid])) {
            throw new \moodle_exception('sujets_relance_refusee', 'mod_redaction');
        }

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
     * Sentences the plugin adds to a comment in the assigned subjects mode.
     *
     * @return array
     */
    public static function phrases_commentaire(): array {
        $phrases = [];
        foreach (['piece', 'fonctionnement', 'les_deux', 'precision', 'juste'] as $cle) {
            $phrases[$cle] = get_string('sujets_commentaire_' . $cle, 'mod_redaction');
        }
        return $phrases;
    }

    /**
     * Get provider instance.
     *
     * @param string $provider Provider name
     * @param string $apikey API key
     * @return provider_interface
     */
    public static function get_provider(string $provider, string $apikey): provider_interface {
        if (self::$fournisseurtest !== null && defined('CLI_SCRIPT') && CLI_SCRIPT) {
            return self::$fournisseurtest;
        }

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
            'albert' => 'deepseek-v4-flash',
        ];
        return $models[$provider] ?? 'default';
    }
}
