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
 * PHPUnit tests for the bulk_unlock external function.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/redaction/lib.php');

/**
 * @group mod_redaction
 */
final class bulk_unlock_test extends \advanced_testcase {

    public function test_unlocks_only_locked_submissions_belonging_to_the_module(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $student2 = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $redaction = $this->getDataGenerator()->create_module('redaction', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('redaction', $redaction->id);

        global $DB;

        // Locked submission for student1.
        $sub1 = (object)[
            'redactionid' => $redaction->id,
            'userid' => $student1->id,
            'groupid' => 0,
            'contenu' => 'Some content',
            'status' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $sub1->id = $DB->insert_record('redaction_submission', $sub1);

        // Draft submission for student2 (should be skipped).
        $sub2 = (object)[
            'redactionid' => $redaction->id,
            'userid' => $student2->id,
            'groupid' => 0,
            'contenu' => 'Draft content',
            'status' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $sub2->id = $DB->insert_record('redaction_submission', $sub2);

        $this->setUser($teacher);

        $result = bulk_unlock::execute($cm->id, [$sub1->id, $sub2->id]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['unlocked']);
        $this->assertSame(1, $result['skipped']);

        $this->assertSame(0, (int) $DB->get_field('redaction_submission', 'status', ['id' => $sub1->id]));
        $this->assertSame(0, (int) $DB->get_field('redaction_submission', 'status', ['id' => $sub2->id]));
    }

    public function test_requires_grade_capability(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $redaction = $this->getDataGenerator()->create_module('redaction', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('redaction', $redaction->id);

        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        bulk_unlock::execute($cm->id, [0]);
    }

    public function test_skips_submissions_from_other_redaction(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $r1 = $this->getDataGenerator()->create_module('redaction', ['course' => $course->id]);
        $r2 = $this->getDataGenerator()->create_module('redaction', ['course' => $course->id]);
        $cm1 = get_coursemodule_from_instance('redaction', $r1->id);

        global $DB;

        $foreignsub = (object)[
            'redactionid' => $r2->id,
            'userid' => $student->id,
            'groupid' => 0,
            'contenu' => 'x',
            'status' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $foreignsub->id = $DB->insert_record('redaction_submission', $foreignsub);

        $this->setUser($teacher);

        $result = bulk_unlock::execute($cm1->id, [$foreignsub->id]);

        $this->assertSame(0, $result['unlocked']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, (int) $DB->get_field('redaction_submission', 'status', ['id' => $foreignsub->id]));
    }
}
