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
 * Unit tests for mod_redaction lib.php functions.
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
 * Test class for mod_redaction lib.php functions.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \redaction_supports
 * @covers \redaction_add_instance
 * @covers \redaction_update_instance
 * @covers \redaction_delete_instance
 * @covers \redaction_get_or_create_submission
 * @covers \redaction_submit
 * @covers \redaction_revert_to_draft
 * @covers \redaction_save_history
 * @covers \redaction_get_history
 * @covers \redaction_count_history
 * @covers \redaction_consignes_complete
 * @covers \redaction_consignes_locked
 */
class lib_test extends \advanced_testcase {

    /** @var \stdClass Test course. */
    protected $course;

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
    }

    /**
     * Test redaction_supports returns true for supported features.
     */
    public function test_supports_mod_intro(): void {
        $this->assertTrue(redaction_supports(FEATURE_MOD_INTRO));
    }

    /**
     * Test redaction_supports returns true for FEATURE_BACKUP_MOODLE2.
     */
    public function test_supports_backup(): void {
        $this->assertTrue(redaction_supports(FEATURE_BACKUP_MOODLE2));
    }

    /**
     * Test redaction_supports returns true for FEATURE_SHOW_DESCRIPTION.
     */
    public function test_supports_show_description(): void {
        $this->assertTrue(redaction_supports(FEATURE_SHOW_DESCRIPTION));
    }

    /**
     * Test redaction_supports returns true for FEATURE_GROUPS.
     */
    public function test_supports_groups(): void {
        $this->assertTrue(redaction_supports(FEATURE_GROUPS));
    }

    /**
     * Test redaction_supports returns true for FEATURE_GROUPINGS.
     */
    public function test_supports_groupings(): void {
        $this->assertTrue(redaction_supports(FEATURE_GROUPINGS));
    }

    /**
     * Test redaction_supports returns true for FEATURE_GRADE_HAS_GRADE.
     */
    public function test_supports_grade_has_grade(): void {
        $this->assertTrue(redaction_supports(FEATURE_GRADE_HAS_GRADE));
    }

    /**
     * Test redaction_supports returns false for FEATURE_GRADE_OUTCOMES.
     */
    public function test_supports_grade_outcomes_false(): void {
        $this->assertFalse(redaction_supports(FEATURE_GRADE_OUTCOMES));
    }

    /**
     * Test redaction_supports returns true for FEATURE_COMPLETION_TRACKS_VIEWS.
     */
    public function test_supports_completion_tracks_views(): void {
        $this->assertTrue(redaction_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
    }

    /**
     * Test redaction_supports returns true for FEATURE_COMPLETION_HAS_RULES.
     */
    public function test_supports_completion_has_rules(): void {
        $this->assertTrue(redaction_supports(FEATURE_COMPLETION_HAS_RULES));
    }

    /**
     * Test redaction_supports returns null for unknown features.
     */
    public function test_supports_unknown_feature(): void {
        $this->assertNull(redaction_supports(-999));
    }

    /**
     * Test add_instance creates a redaction and related records.
     */
    public function test_add_instance(): void {
        global $DB;

        $redaction = $this->generator->create_instance(['course' => $this->course->id]);

        $this->assertNotEmpty($redaction->id);

        // Verify the record exists in the database.
        $record = $DB->get_record('redaction', ['id' => $redaction->id]);
        $this->assertNotFalse($record);
        $this->assertEquals($this->course->id, $record->course);

        // Verify consignes were created.
        $consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redaction->id]);
        $this->assertNotFalse($consignes);
        $this->assertEquals(0, $consignes->locked);

        // Verify correction was created.
        $correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
        $this->assertNotFalse($correction);
    }

    /**
     * Test add_instance sets default autosave interval.
     */
    public function test_add_instance_default_autosave(): void {
        global $DB;

        $redaction = $this->generator->create_instance(['course' => $this->course->id]);
        $record = $DB->get_record('redaction', ['id' => $redaction->id]);

        $this->assertEquals(30, $record->autosave_interval);
    }

    /**
     * Test add_instance with custom autosave interval.
     */
    public function test_add_instance_custom_autosave(): void {
        global $DB;

        $redaction = $this->generator->create_instance([
            'course' => $this->course->id,
            'autosave_interval' => 60,
        ]);
        $record = $DB->get_record('redaction', ['id' => $redaction->id]);

        $this->assertEquals(60, $record->autosave_interval);
    }

    /**
     * Test update_instance updates the record.
     */
    public function test_update_instance(): void {
        global $DB;

        $redaction = $this->generator->create_instance(['course' => $this->course->id]);

        $data = new \stdClass();
        $data->instance = $redaction->id;
        $data->name = 'Updated name';
        $data->course = $this->course->id;
        $data->intro = 'Updated intro';
        $data->introformat = FORMAT_HTML;
        $data->group_submission = 0;
        $data->autosave_interval = 45;
        $data->ai_enabled = 0;
        $data->ai_provider = '';
        $data->ai_api_key = '';
        $data->ai_auto_apply = 0;

        $result = redaction_update_instance($data);
        $this->assertTrue($result);

        $record = $DB->get_record('redaction', ['id' => $redaction->id]);
        $this->assertEquals('Updated name', $record->name);
        $this->assertEquals(45, $record->autosave_interval);
    }

    /**
     * Test delete_instance removes all related data.
     */
    public function test_delete_instance(): void {
        global $DB;

        $redaction = $this->generator->create_instance(['course' => $this->course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');

        // Create a submission.
        $submission = $this->generator->create_submission([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
        ]);

        // Create a history record.
        $this->generator->create_history([
            'submissionid' => $submission->id,
            'redactionid' => $redaction->id,
            'saved_by' => $student->id,
        ]);

        // Verify records exist.
        $this->assertTrue($DB->record_exists('redaction', ['id' => $redaction->id]));
        $this->assertTrue($DB->record_exists('redaction_consignes', ['redactionid' => $redaction->id]));
        $this->assertTrue($DB->record_exists('redaction_correction', ['redactionid' => $redaction->id]));
        $this->assertTrue($DB->record_exists('redaction_submission', ['redactionid' => $redaction->id]));
        $this->assertTrue($DB->record_exists('redaction_history', ['redactionid' => $redaction->id]));

        // Delete instance.
        $result = redaction_delete_instance($redaction->id);
        $this->assertTrue($result);

        // Verify all records are removed.
        $this->assertFalse($DB->record_exists('redaction', ['id' => $redaction->id]));
        $this->assertFalse($DB->record_exists('redaction_consignes', ['redactionid' => $redaction->id]));
        $this->assertFalse($DB->record_exists('redaction_correction', ['redactionid' => $redaction->id]));
        $this->assertFalse($DB->record_exists('redaction_submission', ['redactionid' => $redaction->id]));
        $this->assertFalse($DB->record_exists('redaction_history', ['redactionid' => $redaction->id]));
    }

    /**
     * Test delete_instance returns false for non-existent ID.
     */
    public function test_delete_instance_nonexistent(): void {
        $result = redaction_delete_instance(999999);
        $this->assertFalse($result);
    }

    /**
     * Test get_or_create_submission creates a new submission.
     */
    public function test_get_or_create_submission_creates(): void {
        global $DB;

        $redaction = $this->generator->create_instance([
            'course' => $this->course->id,
            'group_submission' => 0,
        ]);
        $record = $DB->get_record('redaction', ['id' => $redaction->id]);
        $student = $this->getDataGenerator()->create_user();

        $submission = redaction_get_or_create_submission($record, 0, $student->id);

        $this->assertNotEmpty($submission->id);
        $this->assertEquals($redaction->id, $submission->redactionid);
        $this->assertEquals($student->id, $submission->userid);
        $this->assertEquals(0, $submission->status);
    }

    /**
     * Test get_or_create_submission returns existing submission on second call.
     */
    public function test_get_or_create_submission_returns_existing(): void {
        global $DB;

        $redaction = $this->generator->create_instance([
            'course' => $this->course->id,
            'group_submission' => 0,
        ]);
        $record = $DB->get_record('redaction', ['id' => $redaction->id]);
        $student = $this->getDataGenerator()->create_user();

        $submission1 = redaction_get_or_create_submission($record, 0, $student->id);
        $submission2 = redaction_get_or_create_submission($record, 0, $student->id);

        $this->assertEquals($submission1->id, $submission2->id);
    }

    /**
     * Test submit changes status to submitted.
     */
    public function test_submit(): void {
        global $DB;

        $redaction = $this->generator->create_instance([
            'course' => $this->course->id,
            'group_submission' => 0,
        ]);
        $record = $DB->get_record('redaction', ['id' => $redaction->id]);
        $student = $this->getDataGenerator()->create_user();

        // Create submission first.
        $submission = redaction_get_or_create_submission($record, 0, $student->id);
        $this->assertEquals(0, $submission->status);

        // Submit.
        $result = redaction_submit($record, 0, $student->id);
        $this->assertTrue($result);

        // Verify status changed.
        $updated = $DB->get_record('redaction_submission', ['id' => $submission->id]);
        $this->assertEquals(1, $updated->status);
        $this->assertGreaterThan(0, $updated->timesubmitted);
    }

    /**
     * Test revert_to_draft changes status back to draft.
     */
    public function test_revert_to_draft(): void {
        global $DB;

        $redaction = $this->generator->create_instance([
            'course' => $this->course->id,
            'group_submission' => 0,
        ]);
        $record = $DB->get_record('redaction', ['id' => $redaction->id]);
        $student = $this->getDataGenerator()->create_user();

        // Create and submit.
        redaction_get_or_create_submission($record, 0, $student->id);
        redaction_submit($record, 0, $student->id);

        // Revert to draft.
        $result = redaction_revert_to_draft($record, 0, $student->id);
        $this->assertTrue($result);

        $submission = $DB->get_record('redaction_submission', [
            'redactionid' => $redaction->id,
            'userid' => $student->id,
        ]);
        $this->assertEquals(0, $submission->status);
    }

    /**
     * Test save_history creates a history record with version numbering.
     */
    public function test_save_history(): void {
        global $DB;

        $redaction = $this->generator->create_instance([
            'course' => $this->course->id,
            'group_submission' => 0,
        ]);
        $student = $this->getDataGenerator()->create_user();

        $submission = $this->generator->create_submission([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'titre' => 'My essay',
            'contenu' => '<p>Hello world content for testing.</p>',
        ]);

        // Save first version.
        $historyid1 = redaction_save_history($submission, $student->id);
        $this->assertGreaterThan(0, $historyid1);

        $history1 = $DB->get_record('redaction_history', ['id' => $historyid1]);
        $this->assertEquals(1, $history1->version_number);
        $this->assertEquals($student->id, $history1->saved_by);

        // Save second version.
        $historyid2 = redaction_save_history($submission, $student->id);
        $history2 = $DB->get_record('redaction_history', ['id' => $historyid2]);
        $this->assertEquals(2, $history2->version_number);
    }

    /**
     * Test save_history calculates word and character counts.
     */
    public function test_save_history_word_count(): void {
        global $DB;

        $redaction = $this->generator->create_instance(['course' => $this->course->id]);
        $student = $this->getDataGenerator()->create_user();

        $submission = $this->generator->create_submission([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'contenu' => '<p>One two three four five</p>',
        ]);

        $historyid = redaction_save_history($submission, $student->id);
        $history = $DB->get_record('redaction_history', ['id' => $historyid]);

        $this->assertEquals(5, $history->word_count);
        $this->assertGreaterThan(0, $history->char_count);
    }

    /**
     * Test get_history returns records ordered by version descending.
     */
    public function test_get_history(): void {
        $redaction = $this->generator->create_instance(['course' => $this->course->id]);
        $student = $this->getDataGenerator()->create_user();

        $submission = $this->generator->create_submission([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'contenu' => '<p>Content</p>',
        ]);

        // Create multiple history records.
        redaction_save_history($submission, $student->id);
        redaction_save_history($submission, $student->id);
        redaction_save_history($submission, $student->id);

        $history = redaction_get_history($submission->id);
        $this->assertCount(3, $history);

        // Should be in descending version order.
        $versions = array_values(array_map(function ($h) {
            return $h->version_number;
        }, $history));
        $this->assertEquals([3, 2, 1], $versions);
    }

    /**
     * Test get_history with limit.
     */
    public function test_get_history_with_limit(): void {
        $redaction = $this->generator->create_instance(['course' => $this->course->id]);
        $student = $this->getDataGenerator()->create_user();

        $submission = $this->generator->create_submission([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'contenu' => '<p>Content</p>',
        ]);

        redaction_save_history($submission, $student->id);
        redaction_save_history($submission, $student->id);
        redaction_save_history($submission, $student->id);

        $history = redaction_get_history($submission->id, 2);
        $this->assertCount(2, $history);
    }

    /**
     * Test count_history returns correct count.
     */
    public function test_count_history(): void {
        $redaction = $this->generator->create_instance(['course' => $this->course->id]);
        $student = $this->getDataGenerator()->create_user();

        $submission = $this->generator->create_submission([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'contenu' => '<p>Content</p>',
        ]);

        $this->assertEquals(0, redaction_count_history($submission->id));

        redaction_save_history($submission, $student->id);
        $this->assertEquals(1, redaction_count_history($submission->id));

        redaction_save_history($submission, $student->id);
        $this->assertEquals(2, redaction_count_history($submission->id));
    }

    /**
     * Test consignes_complete returns false when consignes are empty.
     */
    public function test_consignes_complete_false(): void {
        $redaction = $this->generator->create_instance(['course' => $this->course->id]);

        // Consignes are empty by default after create_instance.
        $this->assertFalse(redaction_consignes_complete($redaction->id));
    }

    /**
     * Test consignes_complete returns true when titre and consignes are filled.
     */
    public function test_consignes_complete_true(): void {
        global $DB;

        $redaction = $this->generator->create_instance(['course' => $this->course->id]);

        // Update consignes to have titre and consignes.
        $DB->set_field('redaction_consignes', 'titre', 'Test Title', ['redactionid' => $redaction->id]);
        $DB->set_field('redaction_consignes', 'consignes', 'Test instructions', ['redactionid' => $redaction->id]);

        $this->assertTrue(redaction_consignes_complete($redaction->id));
    }

    /**
     * Test consignes_locked returns false by default.
     */
    public function test_consignes_locked_false(): void {
        $redaction = $this->generator->create_instance(['course' => $this->course->id]);

        $this->assertFalse(redaction_consignes_locked($redaction->id));
    }

    /**
     * Test consignes_locked returns true when locked.
     */
    public function test_consignes_locked_true(): void {
        global $DB;

        $redaction = $this->generator->create_instance(['course' => $this->course->id]);
        $DB->set_field('redaction_consignes', 'locked', 1, ['redactionid' => $redaction->id]);

        $this->assertTrue(redaction_consignes_locked($redaction->id));
    }

    /**
     * Test correction_complete returns false when correction is empty.
     */
    public function test_correction_complete_false(): void {
        $redaction = $this->generator->create_instance(['course' => $this->course->id]);

        $this->assertFalse(redaction_correction_complete($redaction->id));
    }

    /**
     * Test correction_complete returns true when modele_reponse is filled.
     */
    public function test_correction_complete_with_model(): void {
        global $DB;

        $redaction = $this->generator->create_instance(['course' => $this->course->id]);
        $DB->set_field('redaction_correction', 'modele_reponse', 'Expected answer', ['redactionid' => $redaction->id]);

        $this->assertTrue(redaction_correction_complete($redaction->id));
    }

    /**
     * Test correction_complete returns true when ai_instructions is filled.
     */
    public function test_correction_complete_with_ai_instructions(): void {
        global $DB;

        $redaction = $this->generator->create_instance(['course' => $this->course->id]);
        $DB->set_field('redaction_correction', 'ai_instructions', 'Grade strictly', ['redactionid' => $redaction->id]);

        $this->assertTrue(redaction_correction_complete($redaction->id));
    }
}
