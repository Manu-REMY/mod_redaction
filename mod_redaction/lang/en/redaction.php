<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * English strings for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Module info.
$string['modulename'] = 'Writing';
$string['modulenameplural'] = 'Writings';
$string['pluginname'] = 'Writing';
$string['pluginadministration'] = 'Writing Administration';
$string['modulename_help'] = 'The Writing module allows teachers to set up a text writing activity with detailed instructions and evaluation (manual or AI-assisted).';

// Capabilities.
$string['redaction:addinstance'] = 'Add a new Writing activity';
$string['redaction:view'] = 'View Writing activity';
$string['redaction:editconsignes'] = 'Edit instructions';
$string['redaction:submit'] = 'Submit a writing';
$string['redaction:viewallsubmissions'] = 'View all submissions';
$string['redaction:grade'] = 'Grade writings';
$string['redaction:viewhistory'] = 'View version history';

// Settings.
$string['autosave_settings'] = 'Autosave';
$string['autosave_interval'] = 'Autosave interval';
$string['autosave_interval_help'] = 'How often student work is automatically saved (in seconds).';
$string['submissionsettings'] = 'Submission settings';
$string['groupsubmission'] = 'Group submission';
$string['groupsubmission_help'] = 'If enabled, students submit their work as a group. All group members share the same writing and grade.';

// AI Settings.
$string['ai_settings'] = 'AI Evaluation';
$string['ai_enabled'] = 'Enable AI evaluation';
$string['ai_enabled_help'] = 'Allows using artificial intelligence to automatically evaluate student writings.';
$string['ai_provider'] = 'AI Provider';
$string['ai_provider_help'] = 'Select the AI service to use for evaluation.';
$string['ai_provider_select'] = 'Select a provider...';
$string['ai_provider_builtin'] = 'Built-in key';
$string['ai_provider_required'] = 'Please select an AI provider.';
$string['ai_api_key'] = 'API Key';
$string['ai_api_key_help'] = 'Your API key for the selected provider. This key will be encrypted.';
$string['ai_api_key_builtin_notice'] = 'Albert uses a built-in API key. No configuration required.';
$string['ai_api_key_required'] = 'API key is required for this provider.';
$string['ai_test_connection'] = 'Test connection';
$string['ai_auto_apply'] = 'Automatically apply AI grades';
$string['ai_auto_apply_help'] = 'If enabled, AI-generated grades will be automatically applied without teacher validation.';

// Home page.
$string['home'] = 'Home';
$string['teacher_section'] = 'Teacher section';
$string['student_section'] = 'Student section';
$string['consignes'] = 'Instructions';
$string['consignes_desc'] = 'Define instructions and evaluation criteria for the activity.';
$string['correction_model'] = 'Correction model';
$string['correction_model_desc'] = 'Define the expected answer model and AI evaluation instructions.';
$string['my_redaction'] = 'My writing';
$string['my_redaction_desc'] = 'Write and submit your work.';
$string['grading'] = 'Grading';
$string['grading_desc'] = 'View and grade student submissions.';

// Consignes page.
$string['consignes_title'] = 'Activity title';
$string['consignes_content'] = 'Detailed instructions';
$string['consignes_content_help'] = 'Describe precisely what students should write.';
$string['consignes_criteres'] = 'Evaluation criteria';
$string['consignes_criteres_help'] = 'List the criteria that will be used to evaluate writings.';
$string['consignes_documents'] = 'Resources and documents';
$string['consignes_documents_help'] = 'Links to useful resources or documents for the writing.';
$string['consignes_locked'] = 'Instructions locked';
$string['consignes_unlocked'] = 'Instructions unlocked';
$string['lock_consignes'] = 'Lock instructions';
$string['unlock_consignes'] = 'Unlock instructions';
$string['consignes_not_ready'] = 'Instructions are not yet available.';
$string['confirm_lock'] = 'Are you sure you want to lock the instructions? Students will be able to see the content.';

// Redaction page.
$string['redaction'] = 'Writing';
$string['redaction_title'] = 'Title';
$string['redaction_title_placeholder'] = 'Give your writing a title';
$string['redaction_content'] = 'Content';
$string['redaction_content_placeholder'] = 'Write your text here...';
$string['status_draft'] = 'Draft';
$string['status_submitted'] = 'Submitted';
$string['submit_redaction'] = 'Submit writing';
$string['submit_confirm'] = 'Are you sure you want to submit your writing? You will not be able to edit it after submission.';
$string['submitted_on'] = 'Submitted on {$a}';
$string['unlock_submission'] = 'Unlock for editing';

// Correction model page.
$string['modele_reponse'] = 'Answer model';
$string['modele_reponse_help'] = 'Write an example of the expected answer. This will help the AI evaluate productions.';
$string['modele_reponse_placeholder'] = 'Write an example of the expected answer here...';
$string['grille_criteres'] = 'Criteria grid';
$string['grille_criteres_help'] = 'Define grading criteria with their weights (JSON format).';
$string['grille_criteres_placeholder'] = 'JSON format: [{"name": "Criteria", "weight": 5, "description": "..."}]';
$string['grille_criteres_example'] = 'JSON format example for the criteria grid:';
$string['ai_instructions'] = 'AI instructions';
$string['ai_instructions_help'] = 'Give specific instructions to the AI to guide its evaluation.';
$string['ai_instructions_placeholder'] = 'Example: Evaluate the relevance of the answer, the quality of argumentation...';
$string['ai_disabled_warning'] = 'AI evaluation is not enabled for this activity.';
$string['dates_section'] = 'Dates';
$string['submission_date'] = 'Expected submission date';
$string['deadline_date'] = 'Deadline';

// Grading page.
$string['grade'] = 'Grade';
$string['grade_outof'] = 'Grade out of {$a}';
$string['feedback'] = 'Feedback';
$string['feedback_placeholder'] = 'Enter your feedback for the student...';
$string['save_grade'] = 'Save grade';
$string['grade_saved'] = 'Grade saved';
$string['no_submission'] = 'No submission';
$string['not_submitted'] = 'Not submitted';
$string['view_submission'] = 'View submission';
$string['evaluate_ai'] = 'Evaluate with AI';
$string['apply_ai_grade'] = 'Apply AI grade';
$string['ai_evaluation'] = 'AI Evaluation';
$string['ai_evaluation_pending'] = 'AI evaluation in progress...';
$string['ai_evaluation_complete'] = 'AI evaluation complete';
$string['ai_evaluation_failed'] = 'AI evaluation failed';
$string['ai_grade'] = 'AI grade';
$string['ai_feedback'] = 'AI feedback';
$string['no_ai_evaluation'] = 'No AI evaluation has been performed yet for this submission.';
$string['reevaluate'] = 'Re-evaluate';
$string['unlock_confirm'] = 'Are you sure you want to unlock this submission? The student will be able to modify it again.';

// History.
$string['version_history'] = 'Version history';
$string['version'] = 'Version {$a}';
$string['version_saved_by'] = 'Saved by {$a->name} on {$a->date}';
$string['word_count'] = '{$a} words';
$string['char_count'] = '{$a} characters';
$string['view_version'] = 'View this version';
$string['compare_versions'] = 'Compare versions';
$string['no_history'] = 'No history available';

// Autosave messages.
$string['saving'] = 'Saving...';
$string['saved'] = 'Saved';
$string['save_error'] = 'Save error';
$string['unsaved_changes'] = 'Unsaved changes';

// Errors.
$string['error:noconsignes'] = 'Instructions have not yet been defined by the teacher.';
$string['error:nosubmission'] = 'No submission found.';
$string['error:alreadysubmitted'] = 'This writing has already been submitted.';
$string['error:notsubmitted'] = 'This writing has not been submitted yet.';
$string['error:cannotgrade'] = 'You do not have permission to grade.';
$string['error:invalidgrade'] = 'Grade must be between 0 and 20.';

// Misc.
$string['group'] = 'Group';
$string['student'] = 'Student';
$string['lastmodified'] = 'Last modified';
$string['actions'] = 'Actions';
$string['back_to_home'] = 'Back to home';
$string['all_groups'] = 'All groups';
$string['all_students'] = 'All students';
$string['filter'] = 'Filter';
$string['search'] = 'Search';
$string['complete'] = 'Complete';
$string['incomplete'] = 'Incomplete';

// Task.
$string['task_evaluate_submission'] = 'Evaluate submission with AI';

// AI Errors.
$string['ai_not_enabled'] = 'AI evaluation is not enabled for this activity.';
$string['ai_request_failed'] = 'AI request failed';
$string['ai_invalid_response'] = 'Invalid AI response';
$string['ai_parse_error'] = 'Error parsing AI response';
$string['ai_unknown_provider'] = 'Unknown AI provider: {$a}';

// AI Generation.
$string['ai_generate_criteria'] = 'AI-assisted generation';
$string['ai_generate_criteria_help'] = 'Use AI to automatically generate evaluation criteria and instructions based on the consignes you have defined.';
$string['ai_generate_button'] = 'Generate with AI';
$string['ai_generate_need_consignes'] = 'Please define and lock the consignes first before generating criteria.';
$string['ai_generate_confirm_overwrite'] = 'This will overwrite the existing criteria grid and AI instructions. Continue?';
$string['ai_generate_success'] = 'Criteria and instructions generated successfully. Please review and adjust as needed.';
$string['ai_generate_error'] = 'Error generating criteria: {$a}';
$string['ai_generating'] = 'Generating...';

// Admin Settings.
$string['settings_albert_heading'] = 'Albert (Etalab) Configuration';
$string['settings_albert_heading_desc'] = 'Albert is the French sovereign AI. Configure the API key here to allow teachers to use Albert without providing their own key.';
$string['settings_albert_api_key'] = 'Albert API Key';
$string['settings_albert_api_key_desc'] = 'API key for the Albert (Etalab) service. This key will be used for all activities using Albert as AI provider.';

// AI Evaluation Details.
$string['ai_criteria_details'] = 'Evaluation criteria details';
$string['ai_general_feedback'] = 'General feedback';
$string['level_excellent'] = 'Excellent';
$string['level_good'] = 'Good';
$string['level_medium'] = 'Needs improvement';
$string['level_low'] = 'Insufficient';
