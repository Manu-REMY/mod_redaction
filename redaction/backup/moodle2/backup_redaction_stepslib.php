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
 * Backup steps for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2025 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Define the complete redaction structure for backup.
 *
 * @package    mod_redaction
 * @copyright  2025 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_redaction_activity_structure_step extends backup_activity_structure_step {

    /**
     * Define the structure of the backup file.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        // To know if we are including userinfo.
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element separated.
        $redaction = new backup_nested_element('redaction', ['id'], [
            'name',
            'intro',
            'introformat',
            'group_submission',
            'autosave_interval',
            'ai_enabled',
            'ai_provider',
            'ai_api_key',
            'ai_auto_apply',
            'training_enabled',
            'training_max_attempts',
            'timecreated',
            'timemodified',
        ]);

        $consignes = new backup_nested_element('consignes', ['id'], [
            'titre',
            'consignes',
            'consignesformat',
            'criteres',
            'criteresformat',
            'documents',
            'documentsformat',
            'locked',
            'timecreated',
            'timemodified',
        ]);

        $correction = new backup_nested_element('correction', ['id'], [
            'modele_reponse',
            'modele_reponseformat',
            'grille_criteres',
            'ai_instructions',
            'submission_date',
            'deadline_date',
            'timecreated',
            'timemodified',
        ]);

        $submissions = new backup_nested_element('submissions');
        $submission = new backup_nested_element('submission', ['id'], [
            'groupid',
            'userid',
            'titre',
            'contenu',
            'contenuformat',
            'status',
            'grade',
            'feedback',
            'feedbackformat',
            'training_count',
            'timesubmitted',
            'timecreated',
            'timemodified',
        ]);

        $histories = new backup_nested_element('histories');
        $history = new backup_nested_element('history', ['id'], [
            'groupid',
            'userid',
            'titre',
            'contenu',
            'contenuformat',
            'version_number',
            'word_count',
            'char_count',
            'saved_by',
            'timecreated',
        ]);

        $aievaluations = new backup_nested_element('ai_evaluations');
        $aievaluation = new backup_nested_element('ai_evaluation', ['id'], [
            'groupid',
            'userid',
            'provider',
            'model',
            'prompt_tokens',
            'completion_tokens',
            'raw_response',
            'parsed_grade',
            'parsed_feedback',
            'criteria_json',
            'status',
            'error_message',
            'applied_by',
            'applied_at',
            'timecreated',
            'timemodified',
        ]);

        $overrides = new backup_nested_element('overrides');
        $override = new backup_nested_element('override', ['id'], [
            'groupid',
            'userid',
            'deadline_date',
            'sortorder',
            'timecreated',
            'timemodified',
        ]);

        // Build the tree.
        $redaction->add_child($consignes);
        $redaction->add_child($correction);
        $redaction->add_child($submissions);
        $redaction->add_child($overrides);
        $overrides->add_child($override);
        $submissions->add_child($submission);
        $submission->add_child($histories);
        $histories->add_child($history);
        $submission->add_child($aievaluations);
        $aievaluations->add_child($aievaluation);

        // Define sources.
        $redaction->set_source_table('redaction', ['id' => backup::VAR_ACTIVITYID]);
        $consignes->set_source_table('redaction_consignes', ['redactionid' => backup::VAR_PARENTID]);
        $correction->set_source_table('redaction_correction', ['redactionid' => backup::VAR_PARENTID]);
        $override->set_source_table('redaction_overrides', ['redactionid' => backup::VAR_PARENTID]);

        // Only include user data if requested.
        if ($userinfo) {
            $submission->set_source_table('redaction_submission', ['redactionid' => backup::VAR_PARENTID]);
            $history->set_source_table('redaction_history', ['submissionid' => backup::VAR_PARENTID]);
            $aievaluation->set_source_table('redaction_ai_evaluations', ['submissionid' => backup::VAR_PARENTID]);
        }

        // Define ID annotations.
        $submission->annotate_ids('user', 'userid');
        $submission->annotate_ids('group', 'groupid');
        $history->annotate_ids('user', 'userid');
        $history->annotate_ids('user', 'saved_by');
        $history->annotate_ids('group', 'groupid');
        $aievaluation->annotate_ids('user', 'userid');
        $aievaluation->annotate_ids('user', 'applied_by');
        $aievaluation->annotate_ids('group', 'groupid');
        $override->annotate_ids('user', 'userid');
        $override->annotate_ids('group', 'groupid');

        // Define file annotations.
        $redaction->annotate_files('mod_redaction', 'intro', null);

        // Return the root element (redaction), wrapped into standard activity structure.
        return $this->prepare_activity_structure($redaction);
    }
}
