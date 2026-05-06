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
$string['task_auto_submit_deadline'] = 'Auto-submit drafts at deadline';
$string['auto_submitted_at_deadline'] = 'Automatically submitted at deadline';

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
$string['ai_confidence'] = 'Evaluation reliability';
$string['ai_strengths'] = 'Strengths';
$string['ai_weaknesses'] = 'Areas for improvement';
$string['ai_keywords'] = 'Keywords analysis';
$string['ai_keywords_found'] = 'Keywords identified';
$string['ai_keywords_missing'] = 'Missing keywords';
$string['ai_suggestions'] = 'Tips for improvement';
$string['ai_overall_appreciation'] = 'Overall appreciation';
$string['level_excellent'] = 'Excellent';
$string['level_good'] = 'Good';
$string['level_medium'] = 'Needs improvement';
$string['level_low'] = 'Insufficient';

// Index page.
$string['noredactions'] = 'There are no Writing activities in this course.';

// Privacy API.
$string['privacy:metadata:redaction_submission'] = 'Information about student submissions for Writing activities.';
$string['privacy:metadata:redaction_submission:userid'] = 'The ID of the user who made the submission.';
$string['privacy:metadata:redaction_submission:groupid'] = 'The ID of the group for group submissions.';
$string['privacy:metadata:redaction_submission:titre'] = 'The title of the submission.';
$string['privacy:metadata:redaction_submission:contenu'] = 'The content of the student\'s writing.';
$string['privacy:metadata:redaction_submission:status'] = 'The submission status (draft or submitted).';
$string['privacy:metadata:redaction_submission:grade'] = 'The grade received for the submission.';
$string['privacy:metadata:redaction_submission:feedback'] = 'The feedback provided by the teacher.';
$string['privacy:metadata:redaction_submission:timesubmitted'] = 'The time when the submission was made.';
$string['privacy:metadata:redaction_submission:timecreated'] = 'The time when the submission record was created.';
$string['privacy:metadata:redaction_submission:timemodified'] = 'The time when the submission was last modified.';

$string['privacy:metadata:redaction_history'] = 'Information about version history of student submissions.';
$string['privacy:metadata:redaction_history:userid'] = 'The ID of the user associated with the history entry.';
$string['privacy:metadata:redaction_history:titre'] = 'The title at the time of saving.';
$string['privacy:metadata:redaction_history:contenu'] = 'The content at the time of saving.';
$string['privacy:metadata:redaction_history:version_number'] = 'The version number of this save.';
$string['privacy:metadata:redaction_history:word_count'] = 'The word count at the time of saving.';
$string['privacy:metadata:redaction_history:char_count'] = 'The character count at the time of saving.';
$string['privacy:metadata:redaction_history:saved_by'] = 'The ID of the user who saved this version.';
$string['privacy:metadata:redaction_history:timecreated'] = 'The time when this version was saved.';

$string['privacy:metadata:redaction_ai_evaluations'] = 'Information about AI evaluations of student submissions.';
$string['privacy:metadata:redaction_ai_evaluations:userid'] = 'The ID of the user whose submission was evaluated.';
$string['privacy:metadata:redaction_ai_evaluations:provider'] = 'The AI provider used for evaluation.';
$string['privacy:metadata:redaction_ai_evaluations:model'] = 'The AI model used for evaluation.';
$string['privacy:metadata:redaction_ai_evaluations:raw_response'] = 'The raw response from the AI service.';
$string['privacy:metadata:redaction_ai_evaluations:parsed_grade'] = 'The grade extracted from the AI response.';
$string['privacy:metadata:redaction_ai_evaluations:parsed_feedback'] = 'The feedback extracted from the AI response.';
$string['privacy:metadata:redaction_ai_evaluations:criteria_json'] = 'The detailed criteria scores in JSON format.';
$string['privacy:metadata:redaction_ai_evaluations:status'] = 'The status of the AI evaluation.';
$string['privacy:metadata:redaction_ai_evaluations:applied_by'] = 'The ID of the teacher who applied the AI grade.';
$string['privacy:metadata:redaction_ai_evaluations:timecreated'] = 'The time when the AI evaluation was created.';

$string['privacy:metadata:ai_provider'] = 'Student submission content is sent to external AI services for evaluation.';
$string['privacy:metadata:ai_provider:submission_content'] = 'The content of the student\'s writing is sent to the AI provider for evaluation.';

// Teacher Dashboard.
$string['dashboard_progress'] = 'Submission Progress';
$string['dashboard_submitted'] = 'Submitted';
$string['dashboard_graded'] = 'Graded';
$string['dashboard_average'] = 'Class Average';
$string['dashboard_no_grades'] = 'No grades available';
$string['dashboard_ai_stats'] = 'AI Evaluations';
$string['dashboard_pending'] = 'pending';
$string['dashboard_completed'] = 'completed';
$string['dashboard_applied'] = 'applied';
$string['dashboard_ai_summary'] = 'AI Feedback Synthesis';
$string['dashboard_refresh'] = 'Refresh';
$string['dashboard_difficulties'] = 'Identified Difficulties';
$string['dashboard_strengths'] = 'Strengths';
$string['dashboard_recommendations'] = 'Pedagogical Recommendations';
$string['dashboard_no_difficulties'] = 'No difficulties identified';
$string['dashboard_no_strengths'] = 'No strengths identified';
$string['dashboard_no_recommendations'] = 'No recommendations';
$string['dashboard_observation'] = 'General Observation';
$string['dashboard_analyzed'] = 'Analyzed';
$string['dashboard_submissions'] = 'submissions';
$string['dashboard_provider'] = 'Provider';
$string['dashboard_no_summary'] = 'No summary available';
$string['dashboard_summary_hint'] = 'Summary will be automatically generated after a few AI evaluations.';
$string['dashboard_token_usage'] = 'AI Token Usage';
$string['dashboard_total_tokens'] = 'Total Tokens';
$string['dashboard_prompt_tokens'] = 'Prompt Tokens';
$string['dashboard_completion_tokens'] = 'Completion Tokens';
$string['dashboard_evaluations'] = 'Evaluations';
$string['dashboard_by_provider'] = 'By Provider';
$string['dashboard_requests'] = 'Requests';
$string['dashboard_tokens'] = 'Tokens';
$string['dashboard_students'] = 'Students';
$string['dashboard_grade_distribution'] = 'Grade Distribution';
$string['dashboard_hide'] = 'Hide Dashboard';
$string['dashboard_show'] = 'Show Dashboard';
$string['dashboard_no_data'] = 'Not enough data to generate a summary.';
$string['dashboard_summary_generated'] = 'Summary generated successfully.';

// Unknown user/group.
$string['unknowngroup'] = 'Unknown group';
$string['unknownuser'] = 'Unknown user';

// AJAX messages.
$string['error:empty_content'] = 'Content cannot be empty.';
$string['error:rate_limit_exceeded'] = 'Rate limit exceeded. Please wait before requesting another evaluation.';
$string['error:evaluation_cooldown'] = 'Please wait a few minutes before requesting another evaluation for this submission.';
$string['error:encryption_unavailable'] = 'Moodle encryption is not available. This plugin requires Moodle 4.5+.';
$string['ajax:submitted'] = 'Submitted';
$string['ajax:submit_failed'] = 'Failed to submit';
$string['ajax:invalid_submission'] = 'Invalid submission';
$string['ajax:unlocked'] = 'Unlocked';
$string['ajax:unlock_failed'] = 'Failed to unlock';
$string['ajax:invalid_action'] = 'Invalid action';
$string['ajax:invalid_json'] = 'Invalid JSON data';
$string['ajax:lock_updated'] = 'Lock status updated';
$string['ajax:consignes_locked'] = 'Instructions are locked';
$string['ajax:saved'] = 'Saved';
$string['ajax:already_submitted'] = 'Already submitted';
$string['ajax:invalid_page'] = 'Invalid page';
$string['ai_provider_admin_key'] = 'Admin key';
$string['ai_albert_no_key'] = 'Albert API key is not configured. Please contact your administrator.';

// Rate limiting settings.
$string['settings_rate_limit'] = 'AI evaluation rate limit';
$string['settings_rate_limit_desc'] = 'Maximum number of AI evaluations per hour per activity. Set to 0 for no limit (not recommended).';

// Token pricing settings.
$string['settings_token_pricing_heading'] = 'Token Pricing';
$string['settings_token_pricing_heading_desc'] = 'Configure the pricing per 1 million tokens for each AI provider. These values are used to estimate costs on the teacher dashboard. Update them when providers change their pricing.';
$string['settings_token_pricing'] = 'Token pricing (JSON)';
$string['settings_token_pricing_desc'] = 'Pricing per 1M tokens in USD, in JSON format. Each provider must have "input" and "output" values. Example: {"openai": {"input": 2.50, "output": 10.00}, "anthropic": {"input": 3.00, "output": 15.00}}';

// Events.
$string['event_submission_created'] = 'Submission created';
$string['event_submission_submitted'] = 'Submission submitted';
$string['event_grade_updated'] = 'Grade updated';
$string['event_ai_evaluation_requested'] = 'AI evaluation requested';
$string['event_ai_evaluation_completed'] = 'AI evaluation completed';
$string['event_ai_grade_applied'] = 'AI grade applied';

// Grading JS strings.
$string['js:evaluating'] = 'Evaluating...';
$string['js:evaluate_with_ai'] = 'Evaluate with AI';
$string['js:words'] = 'words';
$string['js:characters'] = 'characters';
$string['js:no_history'] = 'No history available.';
$string['js:loading_error'] = 'Loading error.';
$string['js:connection_error'] = 'Connection error';

// Auto-apply delay.
$string['settings_auto_apply_delay'] = 'Auto-apply delay (minutes)';
$string['settings_auto_apply_delay_desc'] = 'Delay in minutes before automatically applying AI grades. Set to 0 for immediate application. This gives teachers time to review AI grades before they are applied.';
$string['task_apply_ai_grade'] = 'Apply pending AI grades';
$string['status_pending_apply'] = 'Pending application';

// Notifications.
$string['notification_submission_subject'] = 'New submission from {$a}';
$string['notification_submission_body'] = '{$a->student} has submitted their writing in the activity "{$a->activity}" in course "{$a->course}".';
$string['notification_submission_body_html'] = '<p><strong>{$a->student}</strong> has submitted their writing in the activity "<em>{$a->activity}</em>" in course "<em>{$a->course}</em>".</p>';
$string['notification_submission_small'] = 'New submission from {$a}';
$string['notification_grade_subject'] = 'Your writing "{$a}" has been graded';
$string['notification_grade_body'] = 'Your writing in the activity "{$a->activity}" has been graded: {$a->grade}/20 in course "{$a->course}".';
$string['notification_grade_body_html'] = '<p>Your writing in the activity "<em>{$a->activity}</em>" has been graded: <strong>{$a->grade}/20</strong> in course "<em>{$a->course}</em>".</p>';
$string['notification_grade_small'] = '{$a->activity}: {$a->grade}/20';
$string['notification_ai_eval_subject'] = 'AI evaluation completed';
$string['notification_ai_eval_body'] = 'The AI evaluation for {$a->student} in activity "{$a->activity}" is complete. Suggested grade: {$a->grade}/20 (provider: {$a->provider}).';
$string['notification_ai_eval_body_html'] = '<p>The AI evaluation for <strong>{$a->student}</strong> in activity "<em>{$a->activity}</em>" is complete. Suggested grade: <strong>{$a->grade}/20</strong> (provider: {$a->provider}).</p>';
$string['notification_ai_eval_small'] = 'AI evaluation completed for {$a}';
$string['notification_ai_grade_subject'] = 'AI grade applied for "{$a}"';
$string['notification_ai_grade_body'] = 'An AI-generated grade has been automatically applied to your writing in "{$a->activity}": {$a->grade}/20 in course "{$a->course}".';
$string['notification_ai_grade_body_html'] = '<p>An AI-generated grade has been automatically applied to your writing in "<em>{$a->activity}</em>": <strong>{$a->grade}/20</strong> in course "<em>{$a->course}</em>".</p>';
$string['notification_ai_grade_small'] = '{$a->activity}: AI grade {$a->grade}/20';
$string['view_evaluation'] = 'View evaluation';
$string['messageprovider:submission_received'] = 'Notification when a student submits their writing';
$string['messageprovider:grade_released'] = 'Notification when a grade is released';
$string['messageprovider:ai_evaluation_complete'] = 'Notification when an AI evaluation is complete';

// Bulk operations.
$string['bulk_evaluate'] = 'Evaluate all with AI';
$string['bulk_apply_grade'] = 'Apply all AI grades';
$string['js:bulk_evaluating'] = 'Evaluating all submissions...';
$string['js:bulk_evaluate_success'] = '{$a->queued} evaluation(s) queued, {$a->skipped} skipped.';
$string['js:bulk_applying'] = 'Applying grades...';
$string['js:bulk_apply_success'] = '{$a->applied} grade(s) applied, {$a->skipped} skipped.';
$string['js:bulk_apply_confirm'] = 'Apply all completed AI grades? This action will update the gradebook.';
$string['js:no_evaluations'] = 'No completed evaluations to apply.';

// Plagiarism detection.
$string['settings_plagiarism_heading'] = 'Similarity Detection';
$string['settings_plagiarism_heading_desc'] = 'Configure the similarity detection threshold between student submissions. Uses Jaccard similarity coefficient.';
$string['settings_plagiarism_threshold'] = 'Alert threshold (%)';
$string['settings_plagiarism_threshold_desc'] = 'Similarity percentage above which an alert is displayed. Default: 70%.';
$string['check_similarity'] = 'Check similarity';
$string['similarity_results'] = 'Similarity results';
$string['similarity_alert'] = 'High similarity detected';
$string['no_similar_submissions'] = 'No similar submissions found.';

// Mobile support.
$string['mobile_view_title'] = 'Writing Activity';
$string['mobile_consignes_title'] = 'Instructions';
$string['mobile_submission_title'] = 'My Submission';
$string['mobile_evaluation_title'] = 'Evaluation';

// Training mode.
$string['training_settings'] = 'Training mode';
$string['training_enabled'] = 'Enable training mode';
$string['training_enabled_help'] = 'Allows students to submit their work multiple times to receive immediate AI feedback before their final submission. Requires AI evaluation to be enabled. Note: AI token usage will be multiplied by the number of attempts.';
$string['training_max_attempts'] = 'Maximum attempts';
$string['training_max_attempts_help'] = 'Maximum number of training submissions allowed. 0 = unlimited.';
$string['training_requires_ai'] = 'Training mode requires AI evaluation to be enabled.';
$string['training_submitted'] = 'Training submission sent. Feedback will be available shortly.';
$string['training_history'] = 'Feedback history';
$string['training_attempt'] = 'Attempt {$a}';
$string['training_remaining'] = 'Remaining attempts: {$a}';
$string['training_status'] = 'Training mode active';
$string['training_error_training_not_enabled'] = 'Training mode is not enabled for this activity.';
$string['training_error_already_submitted'] = 'The writing has already been submitted as final.';
$string['training_error_deadline_passed'] = 'The deadline has passed.';
$string['training_error_max_attempts_reached'] = 'Maximum number of attempts reached.';
$string['training_error_evaluation_pending'] = 'An evaluation is in progress. Wait for the result before submitting again.';
$string['training_evaluating'] = 'Evaluating...';
$string['training_feedback_title'] = 'Training feedback';
$string['training_no_feedback'] = 'No training feedback available.';
$string['unlimited'] = 'Unlimited';

// Attempt strings.
$string['attempt_button_first'] = 'Submit';
$string['attempt_button_last'] = 'Submit final version (last attempt)';
$string['attempt_button_remaining'] = 'Resubmit ({$a->used}/{$a->max} used)';
$string['attempt_last_confirm'] = 'This is your last attempt. You will not be able to edit afterwards. Continue?';
$string['attempts_exhausted'] = 'You have used all {$a} attempts. Final grade recorded.';

// Visual criteria editor.
$string['grille_criteres_visual_help'] = 'Define your evaluation criteria. The sum of weights should ideally equal 20.';
$string['add_criterion'] = 'Add criterion';
$string['remove_criterion'] = 'Remove';
$string['criterion_name'] = 'Criterion name';
$string['criterion_name_placeholder'] = 'E.g.: Relevance, Structure, Expression...';
$string['criterion_description'] = 'Description';
$string['criterion_description_placeholder'] = 'Describe what this criterion evaluates...';
$string['criterion_weight'] = 'Weight';
$string['total_weight'] = 'Total weight';
$string['show_json'] = 'Show raw JSON (advanced)';
$string['weight_warning_under'] = 'Total weight ({$a}) is less than 20.';
$string['weight_warning_over'] = 'Total weight ({$a}) exceeds 20.';
$string['weight_ok'] = 'Total: {$a}/20';

// Training mode - grading view.
$string['training_attempts_count'] = 'Training attempts: {$a}';
$string['training_history_teacher'] = 'Training history';
$string['training_no_attempts'] = 'No training attempts';
$string['training_attempt_date'] = 'Attempt {$a->num} - {$a->date}';
$string['training_grade_label'] = 'Training grade';

// Training timeline.
$string['training_timeline_title'] = 'Training timeline';
$string['training_timeline_progress'] = 'Progress';
$string['training_timeline_no_data'] = 'No training attempts';
$string['training_timeline_final'] = 'Final submission';
$string['training_timeline_attempt'] = 'Attempt {$a}';

// Home page additional strings.
$string['submissions_count'] = '{$a} submission(s)';
$string['no_group_error'] = 'You are not in any group. Contact your teacher.';
$string['group_working'] = 'You are working in a group:';
$string['view_consignes'] = 'View instructions';
$string['consultation'] = 'Viewing';
$string['to_complete'] = 'To complete';
$string['view_my_redaction'] = 'View my writing';
$string['work_on_it'] = 'Work';
$string['grade_label'] = 'Grade:';

// Redaction page additional strings.
$string['group_required'] = 'You must belong to a group to access this activity.';
$string['group_label'] = 'Group:';
$string['submitted_graded'] = '{$a} - Graded';

// Consignes page additional strings.
$string['criteres_placeholder'] = '- Criterion 1\n- Criterion 2\n- Criterion 3';

// AI prompt builder strings.
$string['ai_default_criterion_relevance'] = 'Relevance';
$string['ai_default_criterion_relevance_desc'] = 'The answer is relevant to the topic';
$string['ai_default_criterion_structure'] = 'Structure';
$string['ai_default_criterion_structure_desc'] = 'Logical and clear organisation of the text';
$string['ai_default_criterion_expression'] = 'Expression';
$string['ai_default_criterion_expression_desc'] = 'Quality of written expression (spelling, grammar, vocabulary)';
$string['ai_default_criterion_argumentation'] = 'Argumentation';
$string['ai_default_criterion_argumentation_desc'] = 'Quality and relevance of the arguments presented';
$string['ai_criterion_default'] = 'Criterion';

// AI prompt system strings.
$string['ai_prompt_system_intro'] = 'You are an expert educational assistant in evaluating student writings. You must evaluate a student\'s work fairly, kindly and constructively.';
$string['ai_prompt_activity_context'] = 'Activity context';
$string['ai_prompt_title_label'] = 'Title:';
$string['ai_prompt_criteria_section'] = 'Evaluation criteria';
$string['ai_prompt_specific_instructions'] = 'Specific instructions';
$string['ai_prompt_response_format'] = 'Response format';
$string['ai_prompt_response_format_intro'] = 'You MUST respond in JSON with the following structure:';
$string['ai_prompt_grade_desc'] = 'grade from 0 to 20';
$string['ai_prompt_feedback_desc'] = 'detailed and constructive comment addressed directly to the student';
$string['ai_prompt_criterion_name_desc'] = 'criterion name';
$string['ai_prompt_criterion_comment_desc'] = 'detailed comment on this criterion';
$string['ai_prompt_strengths_desc'] = 'strength';
$string['ai_prompt_weaknesses_desc'] = 'area for improvement';
$string['ai_prompt_keywords_found_desc'] = 'keywords found';
$string['ai_prompt_keywords_missing_desc'] = 'expected keywords but absent';
$string['ai_prompt_suggestions_desc'] = 'concrete and actionable improvement advice';
$string['ai_prompt_appreciation_desc'] = 'short overall appreciation, 1-2 sentences, encouraging';
$string['ai_prompt_confidence_desc'] = '0.0 to 1.0';
$string['ai_prompt_training_context'] = 'CONTEXT: TRAINING MODE';
$string['ai_prompt_training_intro'] = 'This evaluation is formative feedback to help the student improve BEFORE their final submission.';
$string['ai_prompt_training_detailed'] = 'Be particularly detailed in your improvement suggestions.';
$string['ai_prompt_training_identify'] = 'Clearly identify what needs to be reworked.';
$string['ai_prompt_training_examples'] = 'Give concrete examples of possible reformulations or additions.';
$string['ai_prompt_training_indicative'] = 'The grade is only indicative, emphasise areas for improvement.';
$string['ai_prompt_important_instructions'] = 'Important instructions';
$string['ai_prompt_address_student'] = 'Address the student directly with kindness and encouragement (use "you").';
$string['ai_prompt_start_positive'] = 'ALWAYS start by highlighting positive points before areas for improvement.';
$string['ai_prompt_level_criteria'] = 'For each criterion, assign a level: "excellent" (>=80%), "good" (>=60%), "medium" (>=40%), "low" (<40%).';
$string['ai_prompt_list_strengths'] = 'List 2 to 4 strengths and 2 to 4 areas for improvement.';
$string['ai_prompt_give_suggestions'] = 'Give 2 to 4 concrete, actionable and achievable suggestions for improvement.';
$string['ai_prompt_appreciation_instructions'] = 'The overall appreciation must be encouraging and summarise the essentials in 1-2 sentences.';
$string['ai_prompt_grade_coherence'] = 'The grade must be consistent with the criteria scores.';
$string['ai_prompt_feedback_structured'] = 'The feedback must be structured and readable.';
$string['ai_prompt_student_instructions'] = 'Instructions given to students';
$string['ai_prompt_criteria_communicated'] = 'Criteria communicated to students:';
$string['ai_prompt_model_answer'] = 'Expected answer model';
$string['ai_prompt_student_work'] = 'Student\'s work';
$string['ai_prompt_content_label'] = 'Content:';
$string['ai_prompt_evaluate_instruction'] = 'Evaluate this work according to the defined criteria and provide your response in JSON.';

// AI response parser display strings.
$string['ai_display_grade'] = 'Grade:';
$string['ai_display_strengths'] = 'Strengths:';
$string['ai_display_weaknesses'] = 'Areas for improvement:';
$string['ai_display_comments'] = 'Comments:';
$string['ai_display_criteria'] = 'Criteria:';
$string['ai_display_suggestions'] = 'Suggestions:';

// AI criteria generation prompts.
$string['ai_generate_criteria_system_prompt'] = 'You are an expert educational assistant. Your task is to generate evaluation criteria for a student writing activity. Respond ONLY in JSON format with the following structure: {"grille_criteres": [{"name": "Criterion name", "weight": 5, "description": "What this criterion evaluates"}], "ai_instructions": "Specific instructions for the AI evaluator"}. The total weight should equal 20. Generate 3 to 5 relevant criteria.';
$string['ai_generate_criteria_user_prompt'] = 'Generate evaluation criteria for the following writing activity:\n\nTitle: {$a->titre}\n\nInstructions: {$a->consignes}\n\nExisting criteria: {$a->criteres}\n\nGenerate a criteria grid and AI evaluation instructions adapted to this activity.';
