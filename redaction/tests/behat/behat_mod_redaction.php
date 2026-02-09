<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Behat step definitions for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: No MOODLE_INTERNAL check here as this file is loaded by Behat.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;
use Behat\Gherkin\Node\TableNode;

/**
 * Custom Behat step definitions for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_redaction extends behat_base {

    /**
     * Creates a redaction submission for a given user.
     *
     * @Given /^the following "mod_redaction > submissions" exist:$/
     * @param TableNode $data
     */
    public function the_following_mod_redaction_submissions_exist(TableNode $data) {
        global $DB;

        foreach ($data->getColumnsHash() as $row) {
            // Resolve the redaction instance by name.
            $redaction = $DB->get_record('redaction', ['name' => $row['activity']], '*', MUST_EXIST);

            // Resolve user.
            $user = $DB->get_record('user', ['username' => $row['user']], '*', MUST_EXIST);

            $submission = new stdClass();
            $submission->redactionid = $redaction->id;
            $submission->userid = $user->id;
            $submission->groupid = 0;
            $submission->titre = $row['title'] ?? 'Test submission';
            $submission->contenu = $row['content'] ?? '<p>Test content.</p>';
            $submission->contenuformat = FORMAT_HTML;
            $submission->status = isset($row['status']) && $row['status'] === 'submitted' ? 1 : 0;
            $submission->feedbackformat = FORMAT_HTML;
            $submission->timecreated = time();
            $submission->timemodified = time();

            if ($submission->status === 1) {
                $submission->timesubmitted = time();
            } else {
                $submission->timesubmitted = 0;
            }

            $DB->insert_record('redaction_submission', $submission);
        }
    }

    /**
     * Locks the consignes for a given redaction activity.
     *
     * @Given /^the consignes for "(?P<activity_string>(?:[^"]|\\")*)" are locked$/
     * @param string $activityname
     */
    public function the_consignes_for_are_locked(string $activityname) {
        global $DB;

        $redaction = $DB->get_record('redaction', ['name' => $activityname], '*', MUST_EXIST);
        $DB->set_field('redaction_consignes', 'locked', 1, ['redactionid' => $redaction->id]);
    }

    /**
     * Sets consignes content for a given redaction activity.
     *
     * @Given /^the consignes for "(?P<activity_string>(?:[^"]|\\")*)" have title "(?P<title_string>(?:[^"]|\\")*)" and instructions "(?P<instructions_string>(?:[^"]|\\")*)"$/
     * @param string $activityname
     * @param string $title
     * @param string $instructions
     */
    public function the_consignes_for_have_title_and_instructions(string $activityname, string $title, string $instructions) {
        global $DB;

        $redaction = $DB->get_record('redaction', ['name' => $activityname], '*', MUST_EXIST);
        $DB->set_field('redaction_consignes', 'titre', $title, ['redactionid' => $redaction->id]);
        $DB->set_field('redaction_consignes', 'consignes', $instructions, ['redactionid' => $redaction->id]);
        $DB->set_field('redaction_consignes', 'timemodified', time(), ['redactionid' => $redaction->id]);
    }

    /**
     * Sets a grade for a student submission in a given activity.
     *
     * @Given /^the submission by "(?P<user_string>(?:[^"]|\\")*)" in "(?P<activity_string>(?:[^"]|\\")*)" has grade "(?P<grade_string>(?:[^"]|\\")*)"$/
     * @param string $username
     * @param string $activityname
     * @param string $grade
     */
    public function the_submission_by_in_has_grade(string $username, string $activityname, string $grade) {
        global $DB;

        $redaction = $DB->get_record('redaction', ['name' => $activityname], '*', MUST_EXIST);
        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);

        $submission = $DB->get_record('redaction_submission', [
            'redactionid' => $redaction->id,
            'userid' => $user->id,
        ]);

        if (!$submission) {
            throw new ExpectationException(
                "No submission found for user '{$username}' in activity '{$activityname}'.",
                $this->getSession()
            );
        }

        $submission->grade = (float) $grade;
        $submission->timemodified = time();
        $DB->update_record('redaction_submission', $submission);
    }

    /**
     * Checks that a submission exists for a given user in a given activity.
     *
     * @Then /^a submission should exist for "(?P<user_string>(?:[^"]|\\")*)" in "(?P<activity_string>(?:[^"]|\\")*)"$/
     * @param string $username
     * @param string $activityname
     */
    public function a_submission_should_exist_for_in(string $username, string $activityname) {
        global $DB;

        $redaction = $DB->get_record('redaction', ['name' => $activityname], '*', MUST_EXIST);
        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);

        $exists = $DB->record_exists('redaction_submission', [
            'redactionid' => $redaction->id,
            'userid' => $user->id,
        ]);

        if (!$exists) {
            throw new ExpectationException(
                "No submission found for user '{$username}' in activity '{$activityname}'.",
                $this->getSession()
            );
        }
    }

    /**
     * Checks the submission status for a given user in a given activity.
     *
     * @Then /^the submission by "(?P<user_string>(?:[^"]|\\")*)" in "(?P<activity_string>(?:[^"]|\\")*)" should have status "(?P<status_string>(?:[^"]|\\")*)"$/
     * @param string $username
     * @param string $activityname
     * @param string $status Either 'draft' or 'submitted'
     */
    public function the_submission_by_in_should_have_status(string $username, string $activityname, string $status) {
        global $DB;

        $redaction = $DB->get_record('redaction', ['name' => $activityname], '*', MUST_EXIST);
        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);

        $submission = $DB->get_record('redaction_submission', [
            'redactionid' => $redaction->id,
            'userid' => $user->id,
        ]);

        if (!$submission) {
            throw new ExpectationException(
                "No submission found for user '{$username}' in activity '{$activityname}'.",
                $this->getSession()
            );
        }

        $expectedstatus = ($status === 'submitted') ? 1 : 0;

        if ((int) $submission->status !== $expectedstatus) {
            throw new ExpectationException(
                "Expected submission status '{$status}' but got '" . ($submission->status == 1 ? 'submitted' : 'draft') . "'.",
                $this->getSession()
            );
        }
    }

    /**
     * Creates an AI evaluation record for a given submission.
     *
     * @Given /^an AI evaluation exists for "(?P<user_string>(?:[^"]|\\")*)" in "(?P<activity_string>(?:[^"]|\\")*)" with grade "(?P<grade_string>(?:[^"]|\\")*)"$/
     * @param string $username
     * @param string $activityname
     * @param string $grade
     */
    public function an_ai_evaluation_exists_for_in_with_grade(string $username, string $activityname, string $grade) {
        global $DB;

        $redaction = $DB->get_record('redaction', ['name' => $activityname], '*', MUST_EXIST);
        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);

        $submission = $DB->get_record('redaction_submission', [
            'redactionid' => $redaction->id,
            'userid' => $user->id,
        ], '*', MUST_EXIST);

        $evaluation = new stdClass();
        $evaluation->redactionid = $redaction->id;
        $evaluation->submissionid = $submission->id;
        $evaluation->groupid = 0;
        $evaluation->userid = $user->id;
        $evaluation->provider = 'albert';
        $evaluation->model = 'albert-large';
        $evaluation->prompt_tokens = 500;
        $evaluation->completion_tokens = 200;
        $evaluation->raw_response = json_encode(['grade' => (float) $grade, 'feedback' => 'AI generated feedback']);
        $evaluation->parsed_grade = (float) $grade;
        $evaluation->parsed_feedback = 'AI generated feedback for the submission.';
        $evaluation->status = 'completed';
        $evaluation->timecreated = time();
        $evaluation->timemodified = time();

        $DB->insert_record('redaction_ai_evaluations', $evaluation);
    }
}
