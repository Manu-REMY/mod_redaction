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
 * Restore steps for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2025 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Structure step to restore one redaction activity.
 *
 * @package    mod_redaction
 * @copyright  2025 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_redaction_activity_structure_step extends restore_activity_structure_step {

    /**
     * Define the structure of the restore file.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('redaction', '/activity/redaction');
        $paths[] = new restore_path_element('redaction_consignes', '/activity/redaction/consignes');
        $paths[] = new restore_path_element('redaction_correction', '/activity/redaction/correction');

        if ($userinfo) {
            $paths[] = new restore_path_element('redaction_submission', '/activity/redaction/submissions/submission');
            $paths[] = new restore_path_element('redaction_history', '/activity/redaction/submissions/submission/histories/history');
            $paths[] = new restore_path_element('redaction_ai_evaluation', '/activity/redaction/submissions/submission/ai_evaluations/ai_evaluation');
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Process the redaction element.
     *
     * @param array $data
     */
    protected function process_redaction($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // Insert the redaction record.
        $newitemid = $DB->insert_record('redaction', $data);

        // Immediately after inserting the course_module, record the mapping.
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Process the consignes element.
     *
     * @param array $data
     */
    protected function process_redaction_consignes($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->redactionid = $this->get_new_parentid('redaction');
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('redaction_consignes', $data);
        $this->set_mapping('redaction_consignes', $oldid, $newitemid);
    }

    /**
     * Process the correction element.
     *
     * @param array $data
     */
    protected function process_redaction_correction($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->redactionid = $this->get_new_parentid('redaction');
        $data->submission_date = $this->apply_date_offset($data->submission_date);
        $data->deadline_date = $this->apply_date_offset($data->deadline_date);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('redaction_correction', $data);
        $this->set_mapping('redaction_correction', $oldid, $newitemid);
    }

    /**
     * Process the submission element.
     *
     * @param array $data
     */
    protected function process_redaction_submission($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->redactionid = $this->get_new_parentid('redaction');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->groupid = $this->get_mappingid('group', $data->groupid);
        $data->last_training_time = !empty($data->last_training_time) ? $this->apply_date_offset($data->last_training_time) : null;
        $data->timesubmitted = $this->apply_date_offset($data->timesubmitted);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('redaction_submission', $data);
        $this->set_mapping('redaction_submission', $oldid, $newitemid);
    }

    /**
     * Process the history element.
     *
     * @param array $data
     */
    protected function process_redaction_history($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->submissionid = $this->get_new_parentid('redaction_submission');
        $data->redactionid = $this->get_new_parentid('redaction');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->groupid = $this->get_mappingid('group', $data->groupid);
        $data->saved_by = $this->get_mappingid('user', $data->saved_by);
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        $newitemid = $DB->insert_record('redaction_history', $data);
        $this->set_mapping('redaction_history', $oldid, $newitemid);
    }

    /**
     * Process the AI evaluation element.
     *
     * @param array $data
     */
    protected function process_redaction_ai_evaluation($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->redactionid = $this->get_new_parentid('redaction');
        $data->submissionid = $this->get_new_parentid('redaction_submission');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->groupid = $this->get_mappingid('group', $data->groupid);
        $data->applied_by = $this->get_mappingid('user', $data->applied_by);
        $data->applied_at = $this->apply_date_offset($data->applied_at);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('redaction_ai_evaluations', $data);
        $this->set_mapping('redaction_ai_evaluations', $oldid, $newitemid);
    }

    /**
     * Define post-execution actions.
     */
    protected function after_execute() {
        // Add redaction related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_redaction', 'intro', null);
    }
}
