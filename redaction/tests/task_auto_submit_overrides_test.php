<?php
namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/redaction/lib.php');

/**
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_redaction\task\auto_submit_deadline
 */
final class task_auto_submit_overrides_test extends \advanced_testcase {

    public function test_cron_auto_submits_draft_when_user_override_passed(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_redaction');
        $redaction = $gen->create_instance(['course' => $course->id]);

        // Instance deadline in the future.
        redaction_create_correction($redaction->id);
        $DB->set_field('redaction_correction', 'deadline_date', time() + 86400,
            ['redactionid' => $redaction->id]);

        // User override in the past.
        $gen->create_override([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'deadline_date' => time() - 3600,
        ]);

        // Draft submission for that user.
        $draftid = $DB->insert_record('redaction_submission', (object) [
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'groupid' => 0,
            'titre' => 'x',
            'contenu' => '<p>Some content</p>',
            'contenuformat' => 1,
            'status' => 0,
            'training_count' => 0,
            'timesubmitted' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $task = new \mod_redaction\task\auto_submit_deadline();
        ob_start();
        $task->execute();
        ob_end_clean();

        $row = $DB->get_record('redaction_submission', ['id' => $draftid]);
        $this->assertEquals(1, (int) $row->status, 'Draft should be auto-submitted');
    }

    public function test_cron_skips_draft_when_no_effective_deadline_yet(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_redaction');
        $redaction = $gen->create_instance(['course' => $course->id]);

        // Instance deadline in the past.
        redaction_create_correction($redaction->id);
        $DB->set_field('redaction_correction', 'deadline_date', time() - 3600,
            ['redactionid' => $redaction->id]);

        // User override in the future.
        $gen->create_override([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'deadline_date' => time() + 86400,
        ]);

        $draftid = $DB->insert_record('redaction_submission', (object) [
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'groupid' => 0,
            'titre' => 'x',
            'contenu' => '<p>Some content</p>',
            'contenuformat' => 1,
            'status' => 0,
            'training_count' => 0,
            'timesubmitted' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $task = new \mod_redaction\task\auto_submit_deadline();
        ob_start();
        $task->execute();
        ob_end_clean();

        $row = $DB->get_record('redaction_submission', ['id' => $draftid]);
        $this->assertEquals(0, (int) $row->status, 'Draft must remain a draft');
    }
}
