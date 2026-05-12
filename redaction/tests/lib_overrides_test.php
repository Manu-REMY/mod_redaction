<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/redaction/lib.php');

/**
 * Tests for override helpers and effective deadline resolution.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::redaction_get_user_override
 * @covers     ::redaction_get_group_override
 * @covers     ::redaction_get_group_overrides_for_user
 * @covers     ::redaction_get_effective_deadline
 */
final class lib_overrides_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $redaction;
    /** @var \stdClass */
    private $correction;
    /** @var \stdClass */
    private $student;
    /** @var \stdClass */
    private $group;
    /** @var \mod_redaction_generator */
    private $gen;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        global $DB;

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $this->group->id,
            'userid' => $this->student->id,
        ]);

        $this->gen = $this->getDataGenerator()->get_plugin_generator('mod_redaction');
        $this->redaction = $this->gen->create_instance(['course' => $this->course->id]);

        // Set instance deadline to a known value (T = 1000).
        $this->correction = $DB->get_record('redaction_correction', ['redactionid' => $this->redaction->id]);
        if (!$this->correction) {
            redaction_create_correction($this->redaction->id);
            $this->correction = $DB->get_record('redaction_correction', ['redactionid' => $this->redaction->id]);
        }
        $DB->set_field('redaction_correction', 'deadline_date', 1000, ['id' => $this->correction->id]);
    }

    public function test_get_user_override_returns_null_when_absent(): void {
        $result = redaction_get_user_override($this->redaction->id, $this->student->id);
        $this->assertNull($result);
    }

    public function test_get_user_override_returns_record_when_present(): void {
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
            'deadline_date' => 2000,
        ]);
        $result = redaction_get_user_override($this->redaction->id, $this->student->id);
        $this->assertNotNull($result);
        $this->assertEquals(2000, (int) $result->deadline_date);
    }

    public function test_get_user_override_returns_null_for_zero_userid(): void {
        $result = redaction_get_user_override($this->redaction->id, 0);
        $this->assertNull($result);
    }

    public function test_get_group_override_returns_null_for_zero_groupid(): void {
        $result = redaction_get_group_override($this->redaction->id, 0);
        $this->assertNull($result);
    }

    public function test_get_group_override_returns_null_when_absent(): void {
        $result = redaction_get_group_override($this->redaction->id, $this->group->id);
        $this->assertNull($result);
    }

    public function test_get_group_override_returns_record(): void {
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $this->group->id,
            'deadline_date' => 3000,
            'sortorder' => 5,
        ]);
        $result = redaction_get_group_override($this->redaction->id, $this->group->id);
        $this->assertNotNull($result);
        $this->assertEquals(3000, (int) $result->deadline_date);
    }

    public function test_group_overrides_for_user_sorted_by_priority(): void {
        $g2 = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $g2->id,
            'userid' => $this->student->id,
        ]);

        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $this->group->id,
            'deadline_date' => 3000,
            'sortorder' => 10,
        ]);
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $g2->id,
            'deadline_date' => 4000,
            'sortorder' => 1,
        ]);

        $rows = redaction_get_group_overrides_for_user($this->redaction->id, $this->student->id);
        $this->assertCount(2, $rows);
        $first = reset($rows);
        $this->assertEquals(1, (int) $first->sortorder);
        $this->assertEquals(4000, (int) $first->deadline_date);
    }

    public function test_effective_deadline_returns_instance_when_no_override(): void {
        $effective = redaction_get_effective_deadline($this->redaction, $this->student->id);
        $this->assertEquals(1000, $effective);
    }

    public function test_effective_deadline_user_override_wins(): void {
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
            'deadline_date' => 2000,
        ]);
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $this->group->id,
            'deadline_date' => 9999,
            'sortorder' => 1,
        ]);
        $effective = redaction_get_effective_deadline($this->redaction, $this->student->id);
        $this->assertEquals(2000, $effective);
    }

    public function test_effective_deadline_group_override_when_no_user_override(): void {
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $this->group->id,
            'deadline_date' => 5000,
            'sortorder' => 1,
        ]);
        $effective = redaction_get_effective_deadline($this->redaction, $this->student->id);
        $this->assertEquals(5000, $effective);
    }

    public function test_effective_deadline_group_lowest_sortorder_wins(): void {
        $g2 = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $g2->id,
            'userid' => $this->student->id,
        ]);

        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $this->group->id,
            'deadline_date' => 5000,
            'sortorder' => 10,
        ]);
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $g2->id,
            'deadline_date' => 6000,
            'sortorder' => 1,
        ]);

        $effective = redaction_get_effective_deadline($this->redaction, $this->student->id);
        $this->assertEquals(6000, $effective);
    }

    public function test_effective_deadline_null_override_is_ignored(): void {
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
            'deadline_date' => null,
        ]);
        $effective = redaction_get_effective_deadline($this->redaction, $this->student->id);
        $this->assertEquals(1000, $effective);
    }

    public function test_effective_deadline_for_group_draft(): void {
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $this->group->id,
            'deadline_date' => 7000,
            'sortorder' => 1,
        ]);
        $effective = redaction_get_effective_deadline($this->redaction, 0, $this->group->id);
        $this->assertEquals(7000, $effective);
    }

    /**
     * @covers ::redaction_can_submit_attempt
     */
    public function test_can_submit_blocked_when_effective_deadline_passed(): void {
        global $DB;
        // Enable training so submit attempt is even considered.
        $DB->set_field('redaction', 'training_enabled', 1, ['id' => $this->redaction->id]);
        $DB->set_field('redaction', 'ai_enabled', 1, ['id' => $this->redaction->id]);

        // Make instance deadline far in the future, but user override in the past.
        $DB->set_field('redaction_correction', 'deadline_date', time() + 86400, ['id' => $this->correction->id]);
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
            'deadline_date' => time() - 3600,
        ]);

        $submission = (object) [
            'id' => 0,
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
            'groupid' => 0,
            'status' => 0,
            'training_count' => 0,
        ];
        $redaction = $DB->get_record('redaction', ['id' => $this->redaction->id]);
        $correction = $DB->get_record('redaction_correction', ['redactionid' => $this->redaction->id]);

        $result = redaction_can_submit_attempt($redaction, $submission, $correction);
        $this->assertFalse($result['allowed']);
        $this->assertEquals('deadline_passed', $result['reason']);
    }
}
