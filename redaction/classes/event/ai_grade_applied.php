<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AI grade applied event.
 *
 * This event is fired when an AI-generated grade is applied
 * to the gradebook for a submission in a redaction activity.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\event;

defined('MOODLE_INTERNAL') || die();

/**
 * AI grade applied event class.
 *
 * The 'other' data must contain:
 * - evaluationid (int): The ID of the AI evaluation record.
 * - grade (float): The grade that was applied.
 * - provider (string): The AI provider used (e.g. openai, anthropic, mistral, albert).
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_grade_applied extends \core\event\base {

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
        return get_string('event_ai_grade_applied', 'redaction');
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        $evaluationid = $this->other['evaluationid'] ?? 'unknown';
        $grade = $this->other['grade'] ?? 'unknown';
        $provider = $this->other['provider'] ?? 'unknown';
        return "The user with id '$this->userid' applied AI grade '$grade' " .
            "from evaluation id '$evaluationid' (provider: '$provider') " .
            "to submission with id '$this->objectid' " .
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

        if (!isset($this->other['evaluationid'])) {
            throw new \coding_exception('The \'evaluationid\' value must be set in other.');
        }
        if (!isset($this->other['grade'])) {
            throw new \coding_exception('The \'grade\' value must be set in other.');
        }
        if (!isset($this->other['provider'])) {
            throw new \coding_exception('The \'provider\' value must be set in other.');
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
            'evaluationid' => ['db' => 'redaction_ai_evaluations', 'restore' => 'redaction_ai_evaluations'],
            'grade' => \core\event\base::NOT_MAPPED,
            'provider' => \core\event\base::NOT_MAPPED,
        ];
    }
}
