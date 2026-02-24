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
 * Notification manager for mod_redaction.
 *
 * Handles sending Moodle notifications in response to plugin events.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages notification sending for key plugin events.
 */
class notification_manager {

    /**
     * Handle submission_submitted event - notify teachers.
     */
    public static function handle_submission_received(\core\event\base $event): void {
        global $DB;

        $submissionid = $event->objectid;
        $cmid = $event->contextinstanceid;
        $studentid = $event->userid;

        $submission = $DB->get_record('redaction_submission', ['id' => $submissionid]);
        if (!$submission) {
            return;
        }

        $cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $student = \core_user::get_user($studentid);
        if (!$student) {
            return;
        }

        $teachers = get_enrolled_users($context, 'mod/redaction:grade');

        foreach ($teachers as $teacher) {
            $message = new \core\message\message();
            $message->component = 'mod_redaction';
            $message->name = 'submission_received';
            $message->userfrom = $student;
            $message->userto = $teacher;
            $message->subject = get_string('notification_submission_subject', 'redaction', fullname($student));
            $message->fullmessage = get_string('notification_submission_body', 'redaction', (object) [
                'student' => fullname($student),
                'activity' => $cm->name,
                'course' => $course->fullname,
            ]);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = get_string('notification_submission_body_html', 'redaction', (object) [
                'student' => fullname($student),
                'activity' => $cm->name,
                'course' => $course->fullname,
            ]);
            $message->smallmessage = get_string('notification_submission_small', 'redaction', fullname($student));
            $message->notification = 1;
            $message->contexturl = (new \moodle_url('/mod/redaction/view.php', [
                'id' => $cm->id,
                'page' => 'grading',
            ]))->out(false);
            $message->contexturlname = get_string('view_submission', 'redaction');

            message_send($message);
        }
    }

    /**
     * Handle grade_updated event - notify student.
     */
    public static function handle_grade_released(\core\event\base $event): void {
        global $DB;

        $submissionid = $event->objectid;
        $cmid = $event->contextinstanceid;
        $teacherid = $event->userid;

        $submission = $DB->get_record('redaction_submission', ['id' => $submissionid]);
        if (!$submission || empty($submission->userid)) {
            return;
        }

        $cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $student = \core_user::get_user($submission->userid);
        $teacher = ($teacherid > 0) ? \core_user::get_user($teacherid) : \core_user::get_noreply_user();

        if (!$student) {
            return;
        }

        $grade = $event->other['newgrade'] ?? $submission->grade;

        $message = new \core\message\message();
        $message->component = 'mod_redaction';
        $message->name = 'grade_released';
        $message->userfrom = $teacher;
        $message->userto = $student;
        $message->subject = get_string('notification_grade_subject', 'redaction', $cm->name);
        $message->fullmessage = get_string('notification_grade_body', 'redaction', (object) [
            'activity' => $cm->name,
            'grade' => $grade,
            'course' => $course->fullname,
        ]);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = get_string('notification_grade_body_html', 'redaction', (object) [
            'activity' => $cm->name,
            'grade' => $grade,
            'course' => $course->fullname,
        ]);
        $message->smallmessage = get_string('notification_grade_small', 'redaction', (object) [
            'activity' => $cm->name,
            'grade' => $grade,
        ]);
        $message->notification = 1;
        $message->contexturl = (new \moodle_url('/mod/redaction/view.php', [
            'id' => $cm->id,
            'page' => 'redaction',
        ]))->out(false);
        $message->contexturlname = get_string('view_grade', 'redaction');

        message_send($message);
    }

    /**
     * Handle ai_evaluation_completed event - notify teachers.
     */
    public static function handle_ai_evaluation_complete(\core\event\base $event): void {
        global $DB;

        $evaluationid = $event->objectid;
        $cmid = $event->contextinstanceid;

        $evaluation = $DB->get_record('redaction_ai_evaluations', ['id' => $evaluationid]);
        if (!$evaluation) {
            return;
        }

        $submission = $DB->get_record('redaction_submission', ['id' => $evaluation->submissionid]);
        if (!$submission) {
            return;
        }

        $cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        // Determine student name.
        $studentname = '';
        if (!empty($submission->userid)) {
            $student = \core_user::get_user($submission->userid);
            $studentname = $student ? fullname($student) : '';
        } else if (!empty($submission->groupid)) {
            $group = groups_get_group($submission->groupid);
            $studentname = $group ? $group->name : '';
        }

        $teachers = get_enrolled_users($context, 'mod/redaction:grade');

        foreach ($teachers as $teacher) {
            $message = new \core\message\message();
            $message->component = 'mod_redaction';
            $message->name = 'ai_evaluation_complete';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $teacher;
            $message->subject = get_string('notification_ai_eval_subject', 'redaction');
            $message->fullmessage = get_string('notification_ai_eval_body', 'redaction', (object) [
                'student' => $studentname,
                'activity' => $cm->name,
                'grade' => $evaluation->parsed_grade ?? '?',
                'provider' => $evaluation->provider ?? 'AI',
            ]);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = get_string('notification_ai_eval_body_html', 'redaction', (object) [
                'student' => $studentname,
                'activity' => $cm->name,
                'grade' => $evaluation->parsed_grade ?? '?',
                'provider' => $evaluation->provider ?? 'AI',
            ]);
            $message->smallmessage = get_string('notification_ai_eval_small', 'redaction', $studentname);
            $message->notification = 1;
            $message->contexturl = (new \moodle_url('/mod/redaction/view.php', [
                'id' => $cm->id,
                'page' => 'grading',
                'submissionid' => $submission->id,
            ]))->out(false);
            $message->contexturlname = get_string('view_evaluation', 'redaction');

            message_send($message);
        }
    }

    /**
     * Handle ai_grade_applied event - notify student about auto-applied grade.
     */
    public static function handle_ai_grade_applied(\core\event\base $event): void {
        global $DB;

        $submissionid = $event->objectid;
        $cmid = $event->contextinstanceid;

        $submission = $DB->get_record('redaction_submission', ['id' => $submissionid]);
        if (!$submission || empty($submission->userid)) {
            return;
        }

        $cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $student = \core_user::get_user($submission->userid);
        if (!$student) {
            return;
        }

        $grade = $event->other['grade'] ?? $submission->grade;

        $message = new \core\message\message();
        $message->component = 'mod_redaction';
        $message->name = 'grade_released';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $student;
        $message->subject = get_string('notification_ai_grade_subject', 'redaction', $cm->name);
        $message->fullmessage = get_string('notification_ai_grade_body', 'redaction', (object) [
            'activity' => $cm->name,
            'grade' => $grade,
            'course' => $course->fullname,
        ]);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = get_string('notification_ai_grade_body_html', 'redaction', (object) [
            'activity' => $cm->name,
            'grade' => $grade,
            'course' => $course->fullname,
        ]);
        $message->smallmessage = get_string('notification_ai_grade_small', 'redaction', (object) [
            'activity' => $cm->name,
            'grade' => $grade,
        ]);
        $message->notification = 1;
        $message->contexturl = (new \moodle_url('/mod/redaction/view.php', [
            'id' => $cm->id,
            'page' => 'redaction',
        ]))->out(false);
        $message->contexturlname = get_string('view_grade', 'redaction');

        message_send($message);
    }
}
