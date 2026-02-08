<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Grade updated event.
 *
 * This event is fired when a grade is changed for a submission,
 * either manually by a teacher or through AI evaluation.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Grade updated event class.
 *
 * The 'other' data must contain:
 * - oldgrade (float|null): The previous grade value.
 * - newgrade (float|null): The new grade value.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grade_updated extends \core\event\base {

    /**
     * Init method.
     */
    protected function init() {
        $this->data['objecttable'] = 'redaction_submission';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Return localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_grade_updated', 'redaction');
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        $oldgrade = $this->other['oldgrade'] ?? 'none';
        $newgrade = $this->other['newgrade'] ?? 'none';
        return "The user with id '$this->userid' updated the grade for submission with id '$this->objectid' " .
            "from '$oldgrade' to '$newgrade' " .
            "for the redaction activity with course module id '$this->contextinstanceid'.";
    }

    /**
     * Get URL related to the action.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/redaction/view.php', [
            'id' => $this->contextinstanceid,
            'page' => 'grading',
        ]);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->other['oldgrade'])) {
            throw new \coding_exception('The \'oldgrade\' value must be set in other.');
        }
        if (!isset($this->other['newgrade'])) {
            throw new \coding_exception('The \'newgrade\' value must be set in other.');
        }
    }

    /**
     * Get the mapping of objectid to the database table.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'redaction_submission', 'restore' => 'redaction_submission'];
    }

    /**
     * Get the mapping of other fields.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [
            'oldgrade' => \core\event\base::NOT_MAPPED,
            'newgrade' => \core\event\base::NOT_MAPPED,
        ];
    }
}
