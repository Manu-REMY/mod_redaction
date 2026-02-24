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
 * mod_redaction data generator for testing.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Redaction module data generator class.
 */
class mod_redaction_generator extends testing_module_generator {

    /**
     * Create a new redaction module instance.
     */
    public function create_instance($record = null, array $options = null) {
        $record = (object) (array) ($record ?? []);

        if (!isset($record->name)) {
            $record->name = 'Test redaction ' . $this->instancecount;
        }
        if (!isset($record->intro)) {
            $record->intro = 'Test redaction description';
        }
        if (!isset($record->introformat)) {
            $record->introformat = FORMAT_HTML;
        }
        if (!isset($record->group_submission)) {
            $record->group_submission = 0;
        }
        if (!isset($record->autosave_interval)) {
            $record->autosave_interval = 30;
        }
        if (!isset($record->ai_enabled)) {
            $record->ai_enabled = 1;
        }
        if (!isset($record->ai_provider)) {
            $record->ai_provider = 'albert';
        }
        if (!isset($record->ai_api_key)) {
            $record->ai_api_key = '';
        }
        if (!isset($record->ai_auto_apply)) {
            $record->ai_auto_apply = 0;
        }

        return parent::create_instance($record, $options);
    }

    /**
     * Create a submission record.
     */
    public function create_submission(array $record): \stdClass {
        global $DB;

        if (empty($record['redactionid'])) {
            throw new \coding_exception('redactionid is required');
        }

        $submission = new \stdClass();
        $submission->redactionid = $record['redactionid'];
        $submission->userid = $record['userid'] ?? 0;
        $submission->groupid = $record['groupid'] ?? 0;
        $submission->titre = $record['titre'] ?? 'Test submission';
        $submission->contenu = $record['contenu'] ?? '<p>Test content.</p>';
        $submission->contenuformat = $record['contenuformat'] ?? FORMAT_HTML;
        $submission->status = $record['status'] ?? 0;
        $submission->grade = $record['grade'] ?? null;
        $submission->feedback = $record['feedback'] ?? null;
        $submission->feedbackformat = $record['feedbackformat'] ?? FORMAT_HTML;
        $submission->timesubmitted = $record['timesubmitted'] ?? 0;
        $submission->timecreated = $record['timecreated'] ?? time();
        $submission->timemodified = $record['timemodified'] ?? time();

        $submission->id = $DB->insert_record('redaction_submission', $submission);
        return $submission;
    }

    /**
     * Create an AI evaluation record.
     */
    public function create_evaluation(array $record): \stdClass {
        global $DB;

        if (empty($record['redactionid']) || empty($record['submissionid'])) {
            throw new \coding_exception('redactionid and submissionid are required');
        }

        $evaluation = new \stdClass();
        $evaluation->redactionid = $record['redactionid'];
        $evaluation->submissionid = $record['submissionid'];
        $evaluation->groupid = $record['groupid'] ?? 0;
        $evaluation->userid = $record['userid'] ?? 0;
        $evaluation->provider = $record['provider'] ?? 'albert';
        $evaluation->model = $record['model'] ?? 'albert-large';
        $evaluation->prompt_tokens = $record['prompt_tokens'] ?? 500;
        $evaluation->completion_tokens = $record['completion_tokens'] ?? 200;
        $evaluation->raw_response = $record['raw_response'] ?? '{"grade":15}';
        $evaluation->parsed_grade = $record['parsed_grade'] ?? 15.0;
        $evaluation->parsed_feedback = $record['parsed_feedback'] ?? 'Good work.';
        $evaluation->criteria_json = $record['criteria_json'] ?? null;
        $evaluation->status = $record['status'] ?? 'completed';
        $evaluation->error_message = $record['error_message'] ?? null;
        $evaluation->applied_by = $record['applied_by'] ?? null;
        $evaluation->applied_at = $record['applied_at'] ?? null;
        $evaluation->timecreated = $record['timecreated'] ?? time();
        $evaluation->timemodified = $record['timemodified'] ?? time();

        $evaluation->id = $DB->insert_record('redaction_ai_evaluations', $evaluation);
        return $evaluation;
    }

    /**
     * Create a history record.
     */
    public function create_history(array $record): \stdClass {
        global $DB;

        if (empty($record['submissionid']) || empty($record['redactionid'])) {
            throw new \coding_exception('submissionid and redactionid are required');
        }

        $history = new \stdClass();
        $history->submissionid = $record['submissionid'];
        $history->redactionid = $record['redactionid'];
        $history->groupid = $record['groupid'] ?? 0;
        $history->userid = $record['userid'] ?? 0;
        $history->titre = $record['titre'] ?? 'Test title';
        $history->contenu = $record['contenu'] ?? '<p>Test content</p>';
        $history->contenuformat = $record['contenuformat'] ?? FORMAT_HTML;
        $history->version_number = $record['version_number'] ?? 1;
        $history->word_count = $record['word_count'] ?? 10;
        $history->char_count = $record['char_count'] ?? 50;
        $history->saved_by = $record['saved_by'] ?? 0;
        $history->timecreated = $record['timecreated'] ?? time();

        $history->id = $DB->insert_record('redaction_history', $history);
        return $history;
    }
}
