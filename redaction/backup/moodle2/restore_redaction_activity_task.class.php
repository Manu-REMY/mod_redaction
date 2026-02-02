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
 * Restore task for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2025 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/redaction/backup/moodle2/restore_redaction_stepslib.php');

/**
 * Restore task that provides all the settings and steps to perform one complete restore of the activity.
 *
 * @package    mod_redaction
 * @copyright  2025 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_redaction_activity_task extends restore_activity_task {

    /**
     * Define particular settings for this activity.
     */
    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    /**
     * Define particular steps for the restore process.
     */
    protected function define_my_steps() {
        // Redaction only has one structure step.
        $this->add_step(new restore_redaction_activity_structure_step('redaction_structure', 'redaction.xml'));
    }

    /**
     * Define the contents in the activity that must be processed by the link decoder.
     *
     * @return array
     */
    public static function define_decode_contents() {
        $contents = [];

        $contents[] = new restore_decode_content('redaction', ['intro'], 'redaction');
        $contents[] = new restore_decode_content('redaction_consignes', ['consignes', 'criteres', 'documents'], 'redaction_consignes');
        $contents[] = new restore_decode_content('redaction_correction', ['modele_reponse', 'ai_instructions'], 'redaction_correction');
        $contents[] = new restore_decode_content('redaction_submission', ['contenu', 'feedback'], 'redaction_submission');
        $contents[] = new restore_decode_content('redaction_history', ['contenu'], 'redaction_history');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging to the activity.
     *
     * @return array
     */
    public static function define_decode_rules() {
        $rules = [];

        $rules[] = new restore_decode_rule('REDACTIONINDEX', '/mod/redaction/index.php?id=$1', 'course');
        $rules[] = new restore_decode_rule('REDACTIONVIEWBYID', '/mod/redaction/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('REDACTIONGRADING', '/mod/redaction/grading.php?id=$1', 'course_module');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied when restoring logs.
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        $rules = [];

        $rules[] = new restore_log_rule('redaction', 'add', 'view.php?id={course_module}', '{redaction}');
        $rules[] = new restore_log_rule('redaction', 'update', 'view.php?id={course_module}', '{redaction}');
        $rules[] = new restore_log_rule('redaction', 'view', 'view.php?id={course_module}', '{redaction}');
        $rules[] = new restore_log_rule('redaction', 'submit', 'view.php?id={course_module}', '{redaction}');
        $rules[] = new restore_log_rule('redaction', 'grade', 'grading.php?id={course_module}', '{redaction}');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied when restoring course logs.
     *
     * @return array
     */
    public static function define_restore_log_rules_for_course() {
        $rules = [];

        $rules[] = new restore_log_rule('redaction', 'view all', 'index.php?id={course}', null);

        return $rules;
    }
}
