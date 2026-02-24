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
 * AI evaluation requested event.
 *
 * This event is fired when an AI evaluation is queued
 * for a student submission in a redaction activity.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\event;

defined('MOODLE_INTERNAL') || die();

/**
 * AI evaluation requested event class.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_evaluation_requested extends \core\event\base {

    /**
     * Init method.
     */
    protected function init() {
        $this->data['objecttable'] = 'redaction_ai_evaluations';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Return localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_ai_evaluation_requested', 'redaction');
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        $submissionid = $this->other['submissionid'] ?? 'unknown';
        return "The user with id '$this->userid' requested an AI evaluation with id '$this->objectid' " .
            "for submission id '$submissionid' " .
            "in the redaction activity with course module id '$this->contextinstanceid'.";
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

        if (!isset($this->other['submissionid'])) {
            throw new \coding_exception('The \'submissionid\' value must be set in other.');
        }
    }

    /**
     * Get the mapping of objectid to the database table.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'redaction_ai_evaluations', 'restore' => 'redaction_ai_evaluations'];
    }

    /**
     * Get the mapping of other fields.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [
            'submissionid' => ['db' => 'redaction_submission', 'restore' => 'redaction_submission'],
        ];
    }
}
