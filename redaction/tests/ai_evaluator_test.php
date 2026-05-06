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
 * Unit tests for mod_redaction ai_evaluator class.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/redaction/lib.php');

/**
 * Test class for ai_evaluator.
 *
 * Tests rate limiting, queue evaluation, apply evaluation, and helper methods.
 * Note: Actual AI API calls are not tested here as they require live connections.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_redaction\ai_evaluator
 */
class ai_evaluator_test extends \advanced_testcase {

    /** @var \stdClass Test course. */
    protected $course;

    /** @var \stdClass Test redaction instance. */
    protected $redaction;

    /** @var \stdClass Test student user. */
    protected $student;

    /** @var \stdClass Test submission. */
    protected $submission;

    /** @var \testing_module_generator Plugin generator. */
    protected $generator;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);

        $this->course = $this->getDataGenerator()->create_course();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_redaction');

        $this->redaction = $this->generator->create_instance([
            'course' => $this->course->id,
            'ai_enabled' => 1,
            'ai_provider' => 'albert',
            'ai_auto_apply' => 0,
        ]);

        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');

        $this->submission = $this->generator->create_submission([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
            'titre' => 'Test essay',
            'contenu' => '<p>This is a test essay for AI evaluation.</p>',
            'status' => 1,
        ]);
    }

    /**
     * Test check_rate_limit passes when under limits.
     */
    public function test_check_rate_limit_passes(): void {
        // Configure rate limit.
        set_config('ai_rate_limit', 60, 'mod_redaction');

        // Should not throw any exception.
        ai_evaluator::check_rate_limit($this->redaction->id, $this->submission->id);
        $this->assertTrue(true); // If we reach here, no exception was thrown.
    }

    /**
     * Test check_rate_limit throws when hourly limit exceeded.
     */
    public function test_check_rate_limit_hourly_exceeded(): void {
        global $DB;

        // Set a very low rate limit.
        set_config('ai_rate_limit', 2, 'mod_redaction');

        // Create evaluations to exceed the limit.
        for ($i = 0; $i < 2; $i++) {
            $sub = $this->generator->create_submission([
                'redactionid' => $this->redaction->id,
                'userid' => $this->getDataGenerator()->create_user()->id,
            ]);
            $this->generator->create_evaluation([
                'redactionid' => $this->redaction->id,
                'submissionid' => $sub->id,
                'status' => 'completed',
                'timecreated' => time() - 60, // Within the last hour.
            ]);
        }

        $this->expectException(\moodle_exception::class);
        ai_evaluator::check_rate_limit($this->redaction->id, $this->submission->id);
    }

    /**
     * Test check_rate_limit throws when cooldown has not elapsed for same submission.
     */
    public function test_check_rate_limit_cooldown(): void {
        // Create a recent evaluation for this submission.
        $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $this->submission->id,
            'status' => 'completed',
            'timecreated' => time() - 60, // 1 minute ago, within 5 min cooldown.
        ]);

        $this->expectException(\moodle_exception::class);
        ai_evaluator::check_rate_limit($this->redaction->id, $this->submission->id);
    }

    /**
     * Test check_rate_limit passes when cooldown has elapsed.
     */
    public function test_check_rate_limit_cooldown_elapsed(): void {
        // Create an old evaluation for this submission.
        $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $this->submission->id,
            'status' => 'completed',
            'timecreated' => time() - 600, // 10 minutes ago, beyond 5 min cooldown.
        ]);

        // Should not throw.
        ai_evaluator::check_rate_limit($this->redaction->id, $this->submission->id);
        $this->assertTrue(true);
    }

    /**
     * Test check_rate_limit ignores pending/processing evaluations for cooldown.
     */
    public function test_check_rate_limit_ignores_pending(): void {
        // Create a recent pending evaluation for this submission.
        $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $this->submission->id,
            'status' => 'pending',
            'timecreated' => time() - 60,
        ]);

        // Should not throw because pending evaluations are excluded from cooldown check.
        ai_evaluator::check_rate_limit($this->redaction->id, $this->submission->id);
        $this->assertTrue(true);
    }

    /**
     * Test get_evaluation returns the most recent evaluation.
     */
    public function test_get_evaluation(): void {
        // Create two evaluations; the newer one should be returned.
        $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $this->submission->id,
            'status' => 'completed',
            'parsed_grade' => 12.0,
            'timecreated' => time() - 3600,
        ]);

        $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $this->submission->id,
            'status' => 'completed',
            'parsed_grade' => 15.0,
            'timecreated' => time(),
        ]);

        $evaluation = ai_evaluator::get_evaluation($this->submission->id);

        $this->assertNotNull($evaluation);
        $this->assertEquals(15.0, (float) $evaluation->parsed_grade);
    }

    /**
     * Test get_evaluation returns null when no evaluation exists.
     */
    public function test_get_evaluation_none(): void {
        $evaluation = ai_evaluator::get_evaluation(999999);
        $this->assertNull($evaluation);
    }

    /**
     * Test has_pending_evaluation returns true when pending.
     */
    public function test_has_pending_evaluation_true(): void {
        $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $this->submission->id,
            'status' => 'pending',
        ]);

        $this->assertTrue(ai_evaluator::has_pending_evaluation($this->submission->id));
    }

    /**
     * Test has_pending_evaluation returns true when processing.
     */
    public function test_has_pending_evaluation_processing(): void {
        $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $this->submission->id,
            'status' => 'processing',
        ]);

        $this->assertTrue(ai_evaluator::has_pending_evaluation($this->submission->id));
    }

    /**
     * Test has_pending_evaluation returns false when completed.
     */
    public function test_has_pending_evaluation_false(): void {
        $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $this->submission->id,
            'status' => 'completed',
        ]);

        $this->assertFalse(ai_evaluator::has_pending_evaluation($this->submission->id));
    }

    /**
     * Test has_pending_evaluation returns false when no evaluation.
     */
    public function test_has_pending_evaluation_none(): void {
        $this->assertFalse(ai_evaluator::has_pending_evaluation(999999));
    }

    /**
     * Test apply_evaluation applies grade and feedback to submission.
     */
    public function test_apply_evaluation(): void {
        global $DB;

        $evaluation = $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $this->submission->id,
            'userid' => $this->student->id,
            'status' => 'completed',
            'parsed_grade' => 16.5,
            'parsed_feedback' => 'Excellent work on the essay.',
        ]);

        $teacher = $this->getDataGenerator()->create_user();

        $result = ai_evaluator::apply_evaluation($evaluation->id, $teacher->id);
        $this->assertTrue($result);

        // Verify submission was updated.
        $updatedsubmission = $DB->get_record('redaction_submission', ['id' => $this->submission->id]);
        $this->assertEquals(16.5, (float) $updatedsubmission->grade);
        $this->assertEquals('Excellent work on the essay.', $updatedsubmission->feedback);

        // Verify evaluation status changed to applied.
        $updatedevaluation = $DB->get_record('redaction_ai_evaluations', ['id' => $evaluation->id]);
        $this->assertEquals('applied', $updatedevaluation->status);
        $this->assertEquals($teacher->id, $updatedevaluation->applied_by);
        $this->assertGreaterThan(0, $updatedevaluation->applied_at);
    }

    /**
     * Test apply_evaluation with overridden grade.
     */
    public function test_apply_evaluation_with_override(): void {
        global $DB;

        $evaluation = $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $this->submission->id,
            'status' => 'completed',
            'parsed_grade' => 16.5,
            'parsed_feedback' => 'AI feedback',
        ]);

        $teacher = $this->getDataGenerator()->create_user();
        $result = ai_evaluator::apply_evaluation($evaluation->id, $teacher->id, 14.0, 'Teacher override feedback');
        $this->assertTrue($result);

        $updatedsubmission = $DB->get_record('redaction_submission', ['id' => $this->submission->id]);
        $this->assertEquals(14.0, (float) $updatedsubmission->grade);
        $this->assertEquals('Teacher override feedback', $updatedsubmission->feedback);
    }

    /**
     * Test apply_evaluation fails for non-completed evaluation.
     */
    public function test_apply_evaluation_fails_for_pending(): void {
        $evaluation = $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $this->submission->id,
            'status' => 'pending',
        ]);

        $result = ai_evaluator::apply_evaluation($evaluation->id, 0);
        $this->assertFalse($result);
    }

    /**
     * Test apply_evaluation fails for failed evaluation.
     */
    public function test_apply_evaluation_fails_for_failed(): void {
        $evaluation = $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $this->submission->id,
            'status' => 'failed',
        ]);

        $result = ai_evaluator::apply_evaluation($evaluation->id, 0);
        $this->assertFalse($result);
    }

    /**
     * Test get_model_for_provider returns correct models.
     */
    public function test_get_model_for_provider(): void {
        $this->assertEquals('gpt-4o-mini', ai_evaluator::get_model_for_provider('openai'));
        $this->assertEquals('claude-3-5-sonnet-20241022', ai_evaluator::get_model_for_provider('anthropic'));
        $this->assertEquals('mistral-medium-latest', ai_evaluator::get_model_for_provider('mistral'));
        $this->assertEquals('albert-large', ai_evaluator::get_model_for_provider('albert'));
    }

    /**
     * Test get_model_for_provider returns default for unknown provider.
     */
    public function test_get_model_for_unknown_provider(): void {
        $this->assertEquals('default', ai_evaluator::get_model_for_provider('unknown'));
    }

    /**
     * Test get_provider throws for unknown provider.
     */
    public function test_get_provider_unknown_throws(): void {
        $this->expectException(\moodle_exception::class);
        ai_evaluator::get_provider('nonexistent', 'key');
    }

    /**
     * Test MAX_TOKENS constant value.
     */
    public function test_max_tokens_constant(): void {
        $this->assertEquals(2000, ai_evaluator::MAX_TOKENS);
    }

    /**
     * Test DEFAULT_RATE_LIMIT constant value.
     */
    public function test_default_rate_limit_constant(): void {
        $this->assertEquals(60, ai_evaluator::DEFAULT_RATE_LIMIT);
    }

    /**
     * Test EVALUATION_COOLDOWN constant value.
     */
    public function test_evaluation_cooldown_constant(): void {
        $this->assertEquals(300, ai_evaluator::EVALUATION_COOLDOWN);
    }

    /**
     * Test retry_evaluation resets status and re-queues.
     */
    public function test_retry_evaluation(): void {
        global $DB;

        $evaluation = $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $this->submission->id,
            'status' => 'failed',
            'error_message' => 'API timeout',
        ]);

        $resultid = ai_evaluator::retry_evaluation($evaluation->id);
        $this->assertEquals($evaluation->id, $resultid);

        // Verify status reset.
        $updated = $DB->get_record('redaction_ai_evaluations', ['id' => $evaluation->id]);
        $this->assertEquals('pending', $updated->status);
        $this->assertNull($updated->error_message);
    }

    /**
     * Test redaction_effective_max_attempts returns default when training_max_attempts is 0,
     * and returns the configured value otherwise.
     *
     * Covers the unified attempt quota introduced when is_training was removed:
     * all evaluations share the same quota governed by this helper.
     */
    public function test_effective_max_attempts_defaults_when_zero(): void {
        require_once($GLOBALS['CFG']->dirroot . '/mod/redaction/lib.php');
        $r = (object) ['training_max_attempts' => 0];
        $this->assertSame(REDACTION_DEFAULT_TRAINING_ATTEMPTS, redaction_effective_max_attempts($r));

        $r->training_max_attempts = 8;
        $this->assertSame(8, redaction_effective_max_attempts($r));
    }
}
