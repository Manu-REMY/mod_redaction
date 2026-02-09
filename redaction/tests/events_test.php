<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Unit tests for mod_redaction events.
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
 * Test class for mod_redaction events.
 *
 * Tests all 7 events:
 * - course_module_viewed
 * - submission_created
 * - submission_submitted
 * - grade_updated
 * - ai_evaluation_requested
 * - ai_evaluation_completed
 * - ai_grade_applied
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_redaction\event\course_module_viewed
 * @covers \mod_redaction\event\submission_created
 * @covers \mod_redaction\event\submission_submitted
 * @covers \mod_redaction\event\grade_updated
 * @covers \mod_redaction\event\ai_evaluation_requested
 * @covers \mod_redaction\event\ai_evaluation_completed
 * @covers \mod_redaction\event\ai_grade_applied
 */
class events_test extends \advanced_testcase {

    /** @var \stdClass Test course. */
    protected $course;

    /** @var \stdClass Test redaction instance. */
    protected $redaction;

    /** @var \stdClass Course module. */
    protected $cm;

    /** @var \context_module Module context. */
    protected $context;

    /** @var \stdClass Test student. */
    protected $student;

    /** @var \stdClass Test teacher. */
    protected $teacher;

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

        $this->redaction = $this->generator->create_instance(['course' => $this->course->id]);
        $this->cm = get_coursemodule_from_instance('redaction', $this->redaction->id, $this->course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($this->cm->id);

        $this->student = $this->getDataGenerator()->create_user();
        $this->teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
    }

    /**
     * Test course_module_viewed event fires correctly.
     */
    public function test_course_module_viewed(): void {
        $sink = $this->redirectEvents();

        $event = \mod_redaction\event\course_module_viewed::create([
            'objectid' => $this->redaction->id,
            'context' => $this->context,
        ]);
        $event->add_record_snapshot('course_modules', $this->cm);
        $event->add_record_snapshot('course', $this->course);
        $event->trigger();

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);

        $this->assertInstanceOf('\mod_redaction\event\course_module_viewed', $event);
        $this->assertEquals($this->context, $event->get_context());
        $this->assertEquals($this->redaction->id, $event->objectid);
        $this->assertEquals('r', $event->crud);
        $this->assertEquals(\core\event\base::LEVEL_PARTICIPATING, $event->edulevel);
        $this->assertNotEmpty($event->get_url());
    }

    /**
     * Test course_module_viewed event get_objectid_mapping.
     */
    public function test_course_module_viewed_mapping(): void {
        $mapping = \mod_redaction\event\course_module_viewed::get_objectid_mapping();

        $this->assertArrayHasKey('db', $mapping);
        $this->assertEquals('redaction', $mapping['db']);
    }

    /**
     * Test submission_created event fires correctly.
     */
    public function test_submission_created(): void {
        $submission = $this->generator->create_submission([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
        ]);

        $sink = $this->redirectEvents();

        $event = \mod_redaction\event\submission_created::create([
            'objectid' => $submission->id,
            'context' => $this->context,
            'userid' => $this->student->id,
        ]);
        $event->trigger();

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);

        $this->assertInstanceOf('\mod_redaction\event\submission_created', $event);
        $this->assertEquals($submission->id, $event->objectid);
        $this->assertEquals($this->student->id, $event->userid);
        $this->assertEquals('c', $event->crud);
        $this->assertEquals(\core\event\base::LEVEL_PARTICIPATING, $event->edulevel);
        $this->assertNotEmpty($event->get_description());
        $this->assertNotEmpty($event->get_url());
    }

    /**
     * Test submission_created event get_objectid_mapping.
     */
    public function test_submission_created_mapping(): void {
        $mapping = \mod_redaction\event\submission_created::get_objectid_mapping();

        $this->assertEquals('redaction_submission', $mapping['db']);
    }

    /**
     * Test submission_submitted event fires correctly.
     */
    public function test_submission_submitted(): void {
        $submission = $this->generator->create_submission([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
            'status' => 1,
        ]);

        $sink = $this->redirectEvents();

        $event = \mod_redaction\event\submission_submitted::create([
            'objectid' => $submission->id,
            'context' => $this->context,
            'userid' => $this->student->id,
        ]);
        $event->trigger();

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);

        $this->assertInstanceOf('\mod_redaction\event\submission_submitted', $event);
        $this->assertEquals($submission->id, $event->objectid);
        $this->assertEquals($this->student->id, $event->userid);
        $this->assertEquals('u', $event->crud);
        $this->assertEquals(\core\event\base::LEVEL_PARTICIPATING, $event->edulevel);
        $this->assertNotEmpty($event->get_description());
        $this->assertNotEmpty($event->get_url());
    }

    /**
     * Test submission_submitted event get_objectid_mapping.
     */
    public function test_submission_submitted_mapping(): void {
        $mapping = \mod_redaction\event\submission_submitted::get_objectid_mapping();

        $this->assertEquals('redaction_submission', $mapping['db']);
    }

    /**
     * Test grade_updated event fires correctly.
     */
    public function test_grade_updated(): void {
        $submission = $this->generator->create_submission([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
        ]);

        $sink = $this->redirectEvents();

        $event = \mod_redaction\event\grade_updated::create([
            'objectid' => $submission->id,
            'context' => $this->context,
            'userid' => $this->teacher->id,
            'other' => [
                'oldgrade' => null,
                'newgrade' => 15.0,
            ],
        ]);
        $event->trigger();

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);

        $this->assertInstanceOf('\mod_redaction\event\grade_updated', $event);
        $this->assertEquals($submission->id, $event->objectid);
        $this->assertEquals($this->teacher->id, $event->userid);
        $this->assertEquals('u', $event->crud);
        $this->assertEquals(\core\event\base::LEVEL_TEACHING, $event->edulevel);
        $this->assertNotEmpty($event->get_description());
        $this->assertNotEmpty($event->get_url());
    }

    /**
     * Test grade_updated event requires oldgrade in other.
     */
    public function test_grade_updated_requires_oldgrade(): void {
        $submission = $this->generator->create_submission([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
        ]);

        $this->expectException(\coding_exception::class);

        $event = \mod_redaction\event\grade_updated::create([
            'objectid' => $submission->id,
            'context' => $this->context,
            'userid' => $this->teacher->id,
            'other' => [
                'newgrade' => 15.0,
            ],
        ]);
        $event->trigger();
    }

    /**
     * Test grade_updated event requires newgrade in other.
     */
    public function test_grade_updated_requires_newgrade(): void {
        $submission = $this->generator->create_submission([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
        ]);

        $this->expectException(\coding_exception::class);

        $event = \mod_redaction\event\grade_updated::create([
            'objectid' => $submission->id,
            'context' => $this->context,
            'userid' => $this->teacher->id,
            'other' => [
                'oldgrade' => null,
            ],
        ]);
        $event->trigger();
    }

    /**
     * Test grade_updated event get_objectid_mapping.
     */
    public function test_grade_updated_mapping(): void {
        $mapping = \mod_redaction\event\grade_updated::get_objectid_mapping();

        $this->assertEquals('redaction_submission', $mapping['db']);
    }

    /**
     * Test grade_updated event get_other_mapping.
     */
    public function test_grade_updated_other_mapping(): void {
        $mapping = \mod_redaction\event\grade_updated::get_other_mapping();

        $this->assertArrayHasKey('oldgrade', $mapping);
        $this->assertArrayHasKey('newgrade', $mapping);
    }

    /**
     * Test ai_evaluation_requested event fires correctly.
     */
    public function test_ai_evaluation_requested(): void {
        $submission = $this->generator->create_submission([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
        ]);

        $evaluation = $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $submission->id,
            'userid' => $this->student->id,
            'status' => 'pending',
        ]);

        $sink = $this->redirectEvents();

        $event = \mod_redaction\event\ai_evaluation_requested::create([
            'objectid' => $evaluation->id,
            'context' => $this->context,
            'userid' => $this->teacher->id,
            'other' => [
                'submissionid' => $submission->id,
            ],
        ]);
        $event->trigger();

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);

        $this->assertInstanceOf('\mod_redaction\event\ai_evaluation_requested', $event);
        $this->assertEquals($evaluation->id, $event->objectid);
        $this->assertEquals($this->teacher->id, $event->userid);
        $this->assertEquals('c', $event->crud);
        $this->assertEquals(\core\event\base::LEVEL_TEACHING, $event->edulevel);
        $this->assertNotEmpty($event->get_description());
        $this->assertNotEmpty($event->get_url());
    }

    /**
     * Test ai_evaluation_requested event requires submissionid in other.
     */
    public function test_ai_evaluation_requested_requires_submissionid(): void {
        $evaluation = $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => 1,
            'status' => 'pending',
        ]);

        $this->expectException(\coding_exception::class);

        $event = \mod_redaction\event\ai_evaluation_requested::create([
            'objectid' => $evaluation->id,
            'context' => $this->context,
            'userid' => $this->teacher->id,
            'other' => [],
        ]);
        $event->trigger();
    }

    /**
     * Test ai_evaluation_requested event get_objectid_mapping.
     */
    public function test_ai_evaluation_requested_mapping(): void {
        $mapping = \mod_redaction\event\ai_evaluation_requested::get_objectid_mapping();

        $this->assertEquals('redaction_ai_evaluations', $mapping['db']);
    }

    /**
     * Test ai_evaluation_completed event fires correctly.
     */
    public function test_ai_evaluation_completed(): void {
        $submission = $this->generator->create_submission([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
        ]);

        $evaluation = $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $submission->id,
            'userid' => $this->student->id,
            'status' => 'completed',
            'provider' => 'albert',
        ]);

        $sink = $this->redirectEvents();

        $event = \mod_redaction\event\ai_evaluation_completed::create([
            'objectid' => $evaluation->id,
            'context' => $this->context,
            'userid' => $this->student->id,
            'other' => [
                'submissionid' => $submission->id,
                'provider' => 'albert',
            ],
        ]);
        $event->trigger();

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);

        $this->assertInstanceOf('\mod_redaction\event\ai_evaluation_completed', $event);
        $this->assertEquals($evaluation->id, $event->objectid);
        $this->assertEquals('u', $event->crud);
        $this->assertEquals(\core\event\base::LEVEL_TEACHING, $event->edulevel);
        $this->assertNotEmpty($event->get_description());
        $this->assertNotEmpty($event->get_url());
    }

    /**
     * Test ai_evaluation_completed event requires submissionid in other.
     */
    public function test_ai_evaluation_completed_requires_submissionid(): void {
        $this->expectException(\coding_exception::class);

        $event = \mod_redaction\event\ai_evaluation_completed::create([
            'objectid' => 1,
            'context' => $this->context,
            'userid' => $this->student->id,
            'other' => [
                'provider' => 'albert',
            ],
        ]);
        $event->trigger();
    }

    /**
     * Test ai_evaluation_completed event requires provider in other.
     */
    public function test_ai_evaluation_completed_requires_provider(): void {
        $this->expectException(\coding_exception::class);

        $event = \mod_redaction\event\ai_evaluation_completed::create([
            'objectid' => 1,
            'context' => $this->context,
            'userid' => $this->student->id,
            'other' => [
                'submissionid' => 1,
            ],
        ]);
        $event->trigger();
    }

    /**
     * Test ai_evaluation_completed event get_objectid_mapping.
     */
    public function test_ai_evaluation_completed_mapping(): void {
        $mapping = \mod_redaction\event\ai_evaluation_completed::get_objectid_mapping();

        $this->assertEquals('redaction_ai_evaluations', $mapping['db']);
    }

    /**
     * Test ai_evaluation_completed event get_other_mapping.
     */
    public function test_ai_evaluation_completed_other_mapping(): void {
        $mapping = \mod_redaction\event\ai_evaluation_completed::get_other_mapping();

        $this->assertArrayHasKey('submissionid', $mapping);
        $this->assertArrayHasKey('provider', $mapping);
    }

    /**
     * Test ai_grade_applied event fires correctly.
     */
    public function test_ai_grade_applied(): void {
        $submission = $this->generator->create_submission([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
        ]);

        $evaluation = $this->generator->create_evaluation([
            'redactionid' => $this->redaction->id,
            'submissionid' => $submission->id,
            'userid' => $this->student->id,
            'status' => 'completed',
            'parsed_grade' => 16.0,
            'provider' => 'albert',
        ]);

        $sink = $this->redirectEvents();

        $event = \mod_redaction\event\ai_grade_applied::create([
            'objectid' => $submission->id,
            'context' => $this->context,
            'userid' => $this->teacher->id,
            'other' => [
                'evaluationid' => $evaluation->id,
                'grade' => 16.0,
                'provider' => 'albert',
            ],
        ]);
        $event->trigger();

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);

        $this->assertInstanceOf('\mod_redaction\event\ai_grade_applied', $event);
        $this->assertEquals($submission->id, $event->objectid);
        $this->assertEquals($this->teacher->id, $event->userid);
        $this->assertEquals('u', $event->crud);
        $this->assertEquals(\core\event\base::LEVEL_TEACHING, $event->edulevel);
        $this->assertNotEmpty($event->get_description());
        $this->assertNotEmpty($event->get_url());
    }

    /**
     * Test ai_grade_applied event requires evaluationid in other.
     */
    public function test_ai_grade_applied_requires_evaluationid(): void {
        $this->expectException(\coding_exception::class);

        $event = \mod_redaction\event\ai_grade_applied::create([
            'objectid' => 1,
            'context' => $this->context,
            'userid' => $this->teacher->id,
            'other' => [
                'grade' => 16.0,
                'provider' => 'albert',
            ],
        ]);
        $event->trigger();
    }

    /**
     * Test ai_grade_applied event requires grade in other.
     */
    public function test_ai_grade_applied_requires_grade(): void {
        $this->expectException(\coding_exception::class);

        $event = \mod_redaction\event\ai_grade_applied::create([
            'objectid' => 1,
            'context' => $this->context,
            'userid' => $this->teacher->id,
            'other' => [
                'evaluationid' => 1,
                'provider' => 'albert',
            ],
        ]);
        $event->trigger();
    }

    /**
     * Test ai_grade_applied event requires provider in other.
     */
    public function test_ai_grade_applied_requires_provider(): void {
        $this->expectException(\coding_exception::class);

        $event = \mod_redaction\event\ai_grade_applied::create([
            'objectid' => 1,
            'context' => $this->context,
            'userid' => $this->teacher->id,
            'other' => [
                'evaluationid' => 1,
                'grade' => 16.0,
            ],
        ]);
        $event->trigger();
    }

    /**
     * Test ai_grade_applied event get_objectid_mapping.
     */
    public function test_ai_grade_applied_mapping(): void {
        $mapping = \mod_redaction\event\ai_grade_applied::get_objectid_mapping();

        $this->assertEquals('redaction_submission', $mapping['db']);
    }

    /**
     * Test ai_grade_applied event get_other_mapping.
     */
    public function test_ai_grade_applied_other_mapping(): void {
        $mapping = \mod_redaction\event\ai_grade_applied::get_other_mapping();

        $this->assertArrayHasKey('evaluationid', $mapping);
        $this->assertArrayHasKey('grade', $mapping);
        $this->assertArrayHasKey('provider', $mapping);
    }

    /**
     * Test multiple events can be captured in sequence.
     */
    public function test_multiple_events_in_sequence(): void {
        $submission = $this->generator->create_submission([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
        ]);

        $sink = $this->redirectEvents();

        // Fire submission_created.
        $event1 = \mod_redaction\event\submission_created::create([
            'objectid' => $submission->id,
            'context' => $this->context,
            'userid' => $this->student->id,
        ]);
        $event1->trigger();

        // Fire submission_submitted.
        $event2 = \mod_redaction\event\submission_submitted::create([
            'objectid' => $submission->id,
            'context' => $this->context,
            'userid' => $this->student->id,
        ]);
        $event2->trigger();

        // Fire grade_updated.
        $event3 = \mod_redaction\event\grade_updated::create([
            'objectid' => $submission->id,
            'context' => $this->context,
            'userid' => $this->teacher->id,
            'other' => [
                'oldgrade' => null,
                'newgrade' => 14.0,
            ],
        ]);
        $event3->trigger();

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(3, $events);

        $this->assertInstanceOf('\mod_redaction\event\submission_created', $events[0]);
        $this->assertInstanceOf('\mod_redaction\event\submission_submitted', $events[1]);
        $this->assertInstanceOf('\mod_redaction\event\grade_updated', $events[2]);
    }
}
