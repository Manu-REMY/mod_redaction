<?php
namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \restore_redaction_activity_structure_step
 */
final class backup_restore_overrides_test extends \advanced_testcase {

    public function test_user_override_is_preserved_across_backup_restore(): void {
        $this->resetAfterTest();
        global $DB, $USER, $CFG;
        $CFG->backup_release = '4.5';
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_redaction');
        $redaction = $gen->create_instance(['course' => $course->id]);

        $gen->create_override([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'deadline_date' => 1234567890,
        ]);

        // Backup.
        $bc = new \backup_controller(\backup::TYPE_1ACTIVITY,
            $redaction->cmid, \backup::FORMAT_MOODLE, \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL, $USER->id);
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $bc->destroy();

        // Restore to a new course.
        $newcourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_and_enrol($newcourse, 'student');
        $tempdir = make_request_directory();
        $file->extract_to_pathname(\get_file_packer('application/vnd.moodle.backup'), $tempdir);
        $rc = new \restore_controller(basename($tempdir), $newcourse->id, \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL, $USER->id, \backup::TARGET_NEW_COURSE);
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $count = $DB->count_records('redaction_overrides');
        $this->assertGreaterThanOrEqual(2, $count, 'Both original and restored overrides should exist');
    }
}
