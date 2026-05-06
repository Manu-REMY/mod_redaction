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
 * Library of interface functions and constants for module redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Default training attempts quota when training_max_attempts is 0 (unlimited).
 */
const REDACTION_DEFAULT_TRAINING_ATTEMPTS = 5;

/** Default maximum grade for the activity. */
define('MOD_REDACTION_GRADEMAX', 20);

/**
 * Supported features
 */
function redaction_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        default:
            return null;
    }
}

/**
 * Add redaction instance.
 *
 * @param stdClass $data
 * @param mod_redaction_mod_form $mform
 * @return int new redaction instance id
 */
function redaction_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;

    // Set default autosave interval if not specified.
    if (!isset($data->autosave_interval)) {
        $data->autosave_interval = 30;
    }

    // Encrypt API key if provided.
    if (!empty($data->ai_api_key)) {
        $data->ai_api_key = \mod_redaction\ai_config::encrypt_api_key($data->ai_api_key);
    }

    $data->id = $DB->insert_record('redaction', $data);

    // Create empty consignes page.
    redaction_create_consignes($data->id);

    // Create empty correction model.
    redaction_create_correction($data->id);

    // Initialize gradebook.
    $redaction = $DB->get_record('redaction', ['id' => $data->id]);
    redaction_grade_item_update($redaction);

    return $data->id;
}

/**
 * Update redaction instance.
 *
 * @param stdClass $data
 * @param mod_redaction_mod_form $mform
 * @return bool true
 */
function redaction_update_instance($data, $mform = null) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    // Encrypt API key if provided and changed.
    if (!empty($data->ai_api_key)) {
        $existing = $DB->get_field('redaction', 'ai_api_key', ['id' => $data->id]);
        if ($data->ai_api_key !== $existing) {
            $data->ai_api_key = \mod_redaction\ai_config::encrypt_api_key($data->ai_api_key);
        }
    }

    $result = $DB->update_record('redaction', $data);

    // Update gradebook.
    $redaction = $DB->get_record('redaction', ['id' => $data->id]);
    redaction_update_grades($redaction);

    return $result;
}

/**
 * Delete redaction instance.
 *
 * @param int $id
 * @return bool true
 */
function redaction_delete_instance($id) {
    global $DB;

    if (!$redaction = $DB->get_record('redaction', ['id' => $id])) {
        return false;
    }

    // Delete all related data.
    $DB->delete_records('redaction_consignes', ['redactionid' => $id]);
    $DB->delete_records('redaction_correction', ['redactionid' => $id]);
    $DB->delete_records('redaction_history', ['redactionid' => $id]);
    $DB->delete_records('redaction_ai_evaluations', ['redactionid' => $id]);
    $DB->delete_records('redaction_ai_summaries', ['redactionid' => $id]);
    $DB->delete_records('redaction_submission', ['redactionid' => $id]);

    // Delete the instance.
    $DB->delete_records('redaction', ['id' => $id]);

    // Remove from gradebook.
    grade_update('mod/redaction', $redaction->course, 'mod', 'redaction', $id, 0, null, ['deleted' => 1]);

    return true;
}

/**
 * Create empty consignes page for a new instance.
 *
 * @param int $redactionid
 */
function redaction_create_consignes($redactionid) {
    global $DB;

    $time = time();

    $consignes = new stdClass();
    $consignes->redactionid = $redactionid;
    $consignes->locked = 0;
    $consignes->consignesformat = FORMAT_HTML;
    $consignes->criteresformat = FORMAT_HTML;
    $consignes->documentsformat = FORMAT_HTML;
    $consignes->timecreated = $time;
    $consignes->timemodified = $time;

    $DB->insert_record('redaction_consignes', $consignes);
}

/**
 * Create empty correction model for a new instance.
 *
 * @param int $redactionid
 */
function redaction_create_correction($redactionid) {
    global $DB;

    $time = time();

    $correction = new stdClass();
    $correction->redactionid = $redactionid;
    $correction->modele_reponseformat = FORMAT_HTML;
    $correction->timecreated = $time;
    $correction->timemodified = $time;

    $DB->insert_record('redaction_correction', $correction);
}

/**
 * Get user's group for this activity.
 *
 * @param stdClass $cm Course module object
 * @param int $userid User ID
 * @return int|false Group ID or 0 if no group
 */
function redaction_get_user_group($cm, $userid) {
    $groups = groups_get_activity_allowed_groups($cm, $userid);

    if (empty($groups)) {
        return 0;
    }

    return $groups ? array_key_first($groups) : 0;
}

/**
 * Get or create student submission record.
 *
 * @param stdClass $redaction The activity record
 * @param int $groupid
 * @param int $userid
 * @return stdClass
 */
function redaction_get_or_create_submission($redaction, $groupid, $userid) {
    global $DB;

    $isgroupsubmission = $redaction->group_submission;

    $params = ['redactionid' => $redaction->id];

    if ($isgroupsubmission && $groupid != 0) {
        $params['groupid'] = $groupid;
        $params['userid'] = 0;
    } else {
        $params['userid'] = $userid;
        $params['groupid'] = $groupid;
    }

    $record = $DB->get_record('redaction_submission', $params);

    if (!$record) {
        $record = new stdClass();
        $record->redactionid = $redaction->id;
        $record->groupid = $params['groupid'];
        $record->userid = $params['userid'];
        $record->status = 0; // Draft.
        $record->contenuformat = FORMAT_HTML;
        $record->feedbackformat = FORMAT_HTML;
        $record->timecreated = time();
        $record->timemodified = time();

        $record->id = $DB->insert_record('redaction_submission', $record);
    }

    return $record;
}

/**
 * Submit the redaction.
 *
 * @param stdClass $redaction
 * @param int $groupid
 * @param int $userid
 * @return bool
 */
function redaction_submit($redaction, $groupid, $userid) {
    global $DB;

    $submission = redaction_get_or_create_submission($redaction, $groupid, $userid);

    if (!$submission) {
        return false;
    }

    $submission->status = 1; // Submitted.
    $submission->timesubmitted = time();
    $submission->timemodified = time();

    return $DB->update_record('redaction_submission', $submission);
}

/**
 * Revert a submission to draft.
 *
 * @param stdClass $redaction
 * @param int $groupid
 * @param int $userid
 * @return bool
 */
function redaction_revert_to_draft($redaction, $groupid, $userid) {
    global $DB;

    $submission = redaction_get_or_create_submission($redaction, $groupid, $userid);

    if (!$submission) {
        return false;
    }

    $submission->status = 0; // Draft.
    $submission->timemodified = time();

    return $DB->update_record('redaction_submission', $submission);
}

/**
 * Save a version to history.
 *
 * @param stdClass $submission The submission record
 * @param int $savedby User ID who saved
 * @return int The new history record ID
 */
function redaction_save_history($submission, $savedby) {
    global $DB;

    // Get the next version number.
    $maxversion = $DB->get_field_sql(
        'SELECT MAX(version_number) FROM {redaction_history} WHERE submissionid = ?',
        [$submission->id]
    );
    $versionnumber = ($maxversion !== null) ? $maxversion + 1 : 1;

    // Count words and characters.
    $plaintext = strip_tags($submission->contenu ?? '');
    $wordcount = str_word_count($plaintext);
    $charcount = function_exists('mb_strlen') ? mb_strlen($plaintext) : strlen($plaintext);

    $history = new stdClass();
    $history->submissionid = $submission->id;
    $history->redactionid = $submission->redactionid;
    $history->groupid = $submission->groupid;
    $history->userid = $submission->userid;
    $history->titre = $submission->titre;
    $history->contenu = $submission->contenu;
    $history->contenuformat = $submission->contenuformat ?? FORMAT_HTML;
    $history->version_number = $versionnumber;
    $history->word_count = $wordcount;
    $history->char_count = $charcount;
    $history->saved_by = $savedby;
    $history->timecreated = time();

    return $DB->insert_record('redaction_history', $history);
}

/**
 * Get version history for a submission with optional pagination.
 *
 * @param int $submissionid
 * @param int $limit Max number of versions to return (0 = all)
 * @param int $page Page number for pagination (0-based)
 * @param int $perpage Number of records per page
 * @return array
 */
function redaction_get_history($submissionid, $limit = 0, int $page = 0, int $perpage = 20) {
    global $DB;

    $sql = 'SELECT h.*, u.firstname, u.lastname
            FROM {redaction_history} h
            LEFT JOIN {user} u ON u.id = h.saved_by
            WHERE h.submissionid = ?
            ORDER BY h.version_number DESC';

    if ($limit > 0) {
        return $DB->get_records_sql($sql, [$submissionid], 0, $limit);
    }

    return $DB->get_records_sql($sql, [$submissionid], $page * $perpage, $perpage);
}

/**
 * Count total version history records for a submission.
 *
 * Used for pagination UI alongside redaction_get_history().
 *
 * @param int $submissionid The submission ID
 * @return int Total number of history records
 */
function redaction_count_history(int $submissionid): int {
    global $DB;

    return $DB->count_records('redaction_history', ['submissionid' => $submissionid]);
}

/**
 * Get all submissions for grading.
 *
 * @param int $redactionid
 * @param int $courseid
 * @return array
 */
function redaction_get_submissions_for_grading($redactionid, $courseid) {
    global $DB;

    $redaction = $DB->get_record('redaction', ['id' => $redactionid], '*', MUST_EXIST);

    if ($redaction->group_submission) {
        // Group mode.
        $groups = groups_get_all_groups($courseid);
        $result = [];

        foreach ($groups as $group) {
            $submission = $DB->get_record('redaction_submission', [
                'redactionid' => $redactionid,
                'groupid' => $group->id,
                'userid' => 0
            ]);

            $result[] = [
                'group' => $group,
                'submission' => $submission,
                'has_submission' => ($submission && !empty($submission->contenu))
            ];
        }

        return $result;
    } else {
        // Individual mode.
        $context = context_course::instance($courseid);
        $users = get_enrolled_users($context, 'mod/redaction:submit');
        $result = [];

        foreach ($users as $user) {
            $submission = $DB->get_record('redaction_submission', [
                'redactionid' => $redactionid,
                'userid' => $user->id
            ]);

            $result[] = [
                'user' => $user,
                'submission' => $submission,
                'has_submission' => ($submission && !empty($submission->contenu))
            ];
        }

        return $result;
    }
}

/**
 * Update activity grades.
 *
 * @param stdClass $redaction The activity instance
 * @param int $userid Optional user ID (0 for all users)
 * @param bool $nullifnone If true, return null grade for users with no submission
 */
function redaction_update_grades($redaction, $userid = 0, $nullifnone = true) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $grades = redaction_get_user_grades($redaction, $userid);
    redaction_grade_item_update($redaction, $grades);
}

/**
 * Create or update grade item.
 *
 * @param stdClass $redaction The activity instance
 * @param mixed $grades Array of grades or 'reset'
 * @return int 0 if ok, error code otherwise
 */
function redaction_grade_item_update($redaction, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname' => $redaction->name,
        'idnumber' => $redaction->id
    ];

    $params['gradetype'] = GRADE_TYPE_VALUE;
    $params['grademax'] = defined('MOD_REDACTION_GRADEMAX') ? MOD_REDACTION_GRADEMAX : 20;
    $params['grademin'] = 0;

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update(
        'mod/redaction',
        $redaction->course,
        'mod',
        'redaction',
        $redaction->id,
        0,
        $grades,
        $params
    );
}

/**
 * Get user grades.
 *
 * @param stdClass $redaction
 * @param int $userid
 * @return array
 */
function redaction_get_user_grades($redaction, $userid = 0) {
    global $DB;

    $grades = [];
    $groups = groups_get_all_groups($redaction->course);

    // If no groups, use virtual group.
    if (empty($groups)) {
        $groups = [0 => (object) ['id' => 0]];
    }

    foreach ($groups as $group) {
        // Get group members.
        if ($group->id == 0) {
            $context = context_course::instance($redaction->course);
            $members = get_enrolled_users($context);
        } else {
            $members = groups_get_members($group->id, 'u.id');
        }

        if (empty($members)) {
            continue;
        }

        $isgroupsubmission = $redaction->group_submission;

        if (!$isgroupsubmission) {
            // Individual mode.
            foreach ($members as $member) {
                if ($userid != 0 && $userid != $member->id) {
                    continue;
                }

                $submission = $DB->get_record('redaction_submission', [
                    'redactionid' => $redaction->id,
                    'userid' => $member->id
                ]);

                if ($submission && $submission->grade !== null) {
                    $grades[$member->id] = new stdClass();
                    $grades[$member->id]->userid = $member->id;
                    $grades[$member->id]->rawgrade = $submission->grade;
                }
            }
        } else {
            // Group mode.
            $submission = $DB->get_record('redaction_submission', [
                'redactionid' => $redaction->id,
                'groupid' => $group->id,
                'userid' => 0
            ]);

            if ($submission && $submission->grade !== null) {
                foreach ($members as $member) {
                    if ($userid != 0 && $userid != $member->id) {
                        continue;
                    }
                    $grades[$member->id] = new stdClass();
                    $grades[$member->id]->userid = $member->id;
                    $grades[$member->id]->rawgrade = $submission->grade;
                }
            }
        }
    }

    return $grades;
}

/**
 * Check if consignes are complete.
 *
 * @param int $redactionid
 * @return bool
 */
function redaction_consignes_complete($redactionid) {
    global $DB;

    $consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redactionid]);

    return $consignes && !empty($consignes->titre) && !empty($consignes->consignes);
}

/**
 * Check if consignes are locked.
 *
 * @param int $redactionid
 * @return bool
 */
function redaction_consignes_locked($redactionid) {
    global $DB;

    $consignes = $DB->get_record('redaction_consignes', ['redactionid' => $redactionid]);

    return $consignes && $consignes->locked;
}

/**
 * Check if correction model is complete.
 *
 * @param int $redactionid
 * @return bool
 */
function redaction_correction_complete($redactionid) {
    global $DB;

    $correction = $DB->get_record('redaction_correction', ['redactionid' => $redactionid]);

    return $correction && (!empty($correction->modele_reponse) || !empty($correction->ai_instructions));
}

/**
 * Render the teacher dashboard for the grading page.
 *
 * @param stdClass $cm Course module object
 * @param stdClass $redaction The redaction instance
 * @return string HTML output
 */
function redaction_render_teacher_dashboard($cm, $redaction) {
    global $OUTPUT, $PAGE;

    // Get submission statistics.
    $submissionstats = new \mod_redaction\dashboard\submission_stats($redaction->id);
    $stats = $submissionstats->get_stats();

    // Prepare template context.
    $context = [
        'cmid' => $cm->id,
        'ai_enabled' => (bool) $redaction->ai_enabled,
        'stats' => [
            'total_expected' => $stats->total_expected,
            'submitted' => $stats->submitted,
            'graded' => $stats->graded,
            'drafts' => $stats->drafts,
            'not_started' => $stats->not_started,
            'submitted_percent' => $stats->submitted_percent,
            'graded_percent' => $stats->graded_percent,
            'draft_percent' => $stats->draft_percent,
            'average_grade' => $stats->average_grade,
            'min_grade' => $stats->min_grade,
            'max_grade' => $stats->max_grade,
            'ai_pending' => $stats->ai_pending,
            'ai_completed' => $stats->ai_completed,
            'ai_applied' => $stats->ai_applied,
            'ai_failed' => $stats->ai_failed,
        ],
        'grade_distribution_json' => json_encode($stats->grade_distribution),
    ];

    // Get AI summary if AI is enabled.
    if ($redaction->ai_enabled) {
        $summarygenerator = new \mod_redaction\dashboard\ai_summary_generator($redaction->id);
        $summary = $summarygenerator->get_summary();

        if ($summary) {
            $context['summary'] = [
                'difficulties' => $summary->difficulties,
                'strengths' => $summary->strengths,
                'recommendations' => $summary->recommendations,
                'general_observation' => $summary->general_observation,
                'submissions_analyzed' => $summary->submissions_analyzed,
                'provider' => $summary->provider,
                'model' => $summary->model,
                'timemodified' => $summary->timemodified,
            ];
        }

        // Get token statistics.
        $tokenstats = new \mod_redaction\dashboard\token_stats($redaction->id);
        $tokens = $tokenstats->get_stats();

        $context['token_stats'] = [
            'total_tokens' => $tokens->total_tokens,
            'prompt_tokens' => $tokens->prompt_tokens,
            'completion_tokens' => $tokens->completion_tokens,
            'evaluation_count' => $tokens->evaluation_count,
            'by_provider' => $tokens->by_provider,
        ];
    }

    // Render the template.
    return $OUTPUT->render_from_template('mod_redaction/dashboard_teacher', $context);
}

/**
 * Returns the effective maximum training attempts for an instance.
 *
 * If training_max_attempts is 0 (legacy unlimited), the default constant applies.
 *
 * @param stdClass $redaction
 * @return int
 */
function redaction_effective_max_attempts($redaction) {
    $max = (int) ($redaction->training_max_attempts ?? 0);
    return $max > 0 ? $max : REDACTION_DEFAULT_TRAINING_ATTEMPTS;
}

/**
 * Determine whether the student is allowed to submit another attempt.
 *
 * Replaces the previous training_submit gate. Cooldown and min_change checks
 * are removed; quota and pending evaluation are the only remaining gates.
 *
 * @param stdClass $redaction
 * @param stdClass $submission
 * @param stdClass|null $correction
 * @return array ['allowed' => bool, 'reason' => string]
 */
function redaction_can_submit_attempt($redaction, $submission, $correction = null) {
    global $DB;

    if (empty($redaction->training_enabled) || empty($redaction->ai_enabled)) {
        return ['allowed' => false, 'reason' => 'training_not_enabled'];
    }

    if ((int) $submission->status === 1) {
        return ['allowed' => false, 'reason' => 'already_submitted'];
    }

    if ($correction && !empty($correction->deadline_date) && time() > $correction->deadline_date) {
        return ['allowed' => false, 'reason' => 'deadline_passed'];
    }

    $maxeffective = redaction_effective_max_attempts($redaction);
    $used = (int) ($submission->training_count ?? 0);
    if ($used >= $maxeffective) {
        return ['allowed' => false, 'reason' => 'max_attempts_reached'];
    }

    $pending = $DB->record_exists_select(
        'redaction_ai_evaluations',
        'submissionid = ? AND status IN (?, ?)',
        [$submission->id, 'pending', 'processing']
    );
    if ($pending) {
        return ['allowed' => false, 'reason' => 'evaluation_pending'];
    }

    return ['allowed' => true, 'reason' => ''];
}

/**
 * Backward-compat alias. Prefer redaction_can_submit_attempt().
 *
 * @deprecated since 2.1.0
 */
function redaction_can_training_submit($redaction, $submission, $correction = null) {
    return redaction_can_submit_attempt($redaction, $submission, $correction);
}

/**
 * Check if content has changed enough from last training version.
 *
 * @param string $newcontent New content (HTML)
 * @param int $submissionid Submission ID
 * @param int $minchangepercent Minimum change percentage (0-100)
 * @return bool True if content has changed enough
 */
function redaction_check_min_change($newcontent, $submissionid, $minchangepercent) {
    global $DB;

    // Get the last history entry.
    $lasthistory = $DB->get_records_sql(
        'SELECT contenu FROM {redaction_history} WHERE submissionid = ? ORDER BY version_number DESC',
        [$submissionid],
        0,
        1
    );

    if (empty($lasthistory)) {
        return true; // First submission, always allowed.
    }

    $lastcontent = strip_tags(reset($lasthistory)->contenu ?? '');
    $newplain = strip_tags($newcontent);

    if (empty($lastcontent)) {
        return true;
    }

    // Calculate similarity percentage.
    similar_text($lastcontent, $newplain, $similarity);
    $changepercent = 100 - $similarity;

    return $changepercent >= $minchangepercent;
}

/**
 * Get all training evaluations for a submission, most recent first.
 *
 * @param int $submissionid Submission ID
 * @return array Array of evaluation records
 */
function redaction_get_training_evaluations($submissionid) {
    global $DB;

    return $DB->get_records_sql(
        'SELECT * FROM {redaction_ai_evaluations}
         WHERE submissionid = ? AND is_training = 1
         ORDER BY timecreated DESC',
        [$submissionid]
    );
}

/**
 * Get the latest completed training evaluation for a submission.
 *
 * @param int $submissionid Submission ID
 * @return object|null
 */
function redaction_get_latest_training_evaluation($submissionid) {
    global $DB;

    $records = $DB->get_records_sql(
        'SELECT * FROM {redaction_ai_evaluations}
         WHERE submissionid = ? AND is_training = 1 AND status = ?
         ORDER BY timecreated DESC',
        [$submissionid, 'completed'],
        0,
        1
    );

    return !empty($records) ? reset($records) : null;
}
