<?php
namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use mod_redaction\privacy\provider;

/**
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_redaction\privacy\provider
 */
final class privacy_overrides_test extends \core_privacy\tests\provider_testcase {

    public function test_get_contexts_includes_override_context(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_redaction');
        $redaction = $gen->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('redaction', $redaction->id);
        $context = \context_module::instance($cm->id);

        $gen->create_override([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'deadline_date' => time() + 3600,
        ]);

        $list = provider::get_contexts_for_userid($student->id);
        $this->assertContains((int) $context->id, $list->get_contextids());
    }

    public function test_delete_data_for_user_removes_overrides(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_redaction');
        $redaction = $gen->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('redaction', $redaction->id);
        $context = \context_module::instance($cm->id);

        $gen->create_override([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'deadline_date' => time() + 3600,
        ]);

        $contextlist = new approved_contextlist($student, 'mod_redaction', [$context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists('redaction_overrides',
            ['userid' => $student->id, 'redactionid' => $redaction->id]));
    }

    public function test_delete_data_for_all_users_removes_overrides(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_redaction');
        $redaction = $gen->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('redaction', $redaction->id);
        $context = \context_module::instance($cm->id);

        $gen->create_override([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'deadline_date' => time() + 3600,
        ]);

        provider::delete_data_for_all_users_in_context($context);

        $this->assertFalse($DB->record_exists('redaction_overrides',
            ['redactionid' => $redaction->id]));
    }
}
