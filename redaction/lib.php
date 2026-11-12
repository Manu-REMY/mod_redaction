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

    redaction_force_sujets_settings($data);

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

    redaction_force_sujets_settings($data);

    $avant = $DB->get_record('redaction', ['id' => $data->id], '*', MUST_EXIST);

    $result = $DB->update_record('redaction', $data);

    // Update gradebook.
    $redaction = $DB->get_record('redaction', ['id' => $data->id]);
    $reglagechange = abs(\mod_redaction\notes_sujets::reglage_langue($avant) - \mod_redaction\notes_sujets::reglage_langue($redaction))
        >= 0.005;
    if (!empty($redaction->sujets_enabled) && $reglagechange) {
        // Only the attempts graded with the new language setting count from now on.
        redaction_sujets_recompter($redaction);
    } else {
        redaction_update_grades($redaction);
    }

    return $result;
}

/**
 * Assigned subjects mode: start the attempts again after a change of the language setting.
 *
 * The attempts graded with another setting no longer count, nor use up the maximum. The teacher grade stays.
 *
 * @param stdClass $redaction Activity record, with its current setting.
 */
function redaction_sujets_recompter($redaction) {
    global $DB;

    $reglage = \mod_redaction\notes_sujets::reglage_langue($redaction);
    foreach ($DB->get_records('redaction_submission', ['redactionid' => $redaction->id]) as $submission) {
        $notes = $DB->get_fieldset_select(
            'redaction_ai_evaluations',
            'parsed_grade',
            "submissionid = ? AND status IN ('completed', 'applied') AND langue_points = ?",
            [$submission->id, $reglage]
        );
        $enseignant = $submission->grade_enseignant !== null ? (float) $submission->grade_enseignant : null;
        $DB->update_record('redaction_submission', (object) [
            'id' => $submission->id,
            'training_count' => 0,
            'grade' => \mod_redaction\notes_sujets::meilleure($notes, $enseignant),
            'timemodified' => time(),
        ]);
    }
    redaction_update_grades($redaction);
}

/**
 * Delete redaction instance.
 *
 * @param int $id
 * @return bool true
 */
function redaction_delete_instance($id) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if (!$redaction = $DB->get_record('redaction', ['id' => $id])) {
        return false;
    }

    // Assigned subjects data hangs off submissions and AI evaluations: clear it first.
    $DB->delete_records_select('redaction_sujet_membre',
        'evaluationid IN (SELECT id FROM {redaction_ai_evaluations} WHERE redactionid = :rid)', ['rid' => $id]);
    $DB->delete_records_select('redaction_sujet_reponse',
        'submissionid IN (SELECT id FROM {redaction_submission} WHERE redactionid = :rid)', ['rid' => $id]);
    $DB->delete_records('redaction_sujet_groupe', ['redactionid' => $id]);
    $DB->delete_records('redaction_sujet_eleve', ['redactionid' => $id]);
    $DB->delete_records('redaction_sujet', ['redactionid' => $id]);

    // Delete all related data.
    $DB->delete_records('redaction_consignes', ['redactionid' => $id]);
    $DB->delete_records('redaction_correction', ['redactionid' => $id]);
    $DB->delete_records('redaction_history', ['redactionid' => $id]);
    $DB->delete_records('redaction_ai_evaluations', ['redactionid' => $id]);
    $DB->delete_records('redaction_ai_summaries', ['redactionid' => $id]);
    $DB->delete_records('redaction_submission', ['redactionid' => $id]);
    $DB->delete_records('redaction_overrides', ['redactionid' => $id]);

    // Delete the instance.
    $DB->delete_records('redaction', ['id' => $id]);

    // Remove from gradebook.
    grade_update('mod/redaction', $redaction->course, 'mod', 'redaction', $id, 0, null, ['deleted' => 1]);

    return true;
}

/**
 * Delete a subject of an activity, unless it is still in use.
 *
 * A subject that is assigned to a group or has an answer is kept, so that no
 * student work can be erased by deleting a subject. A subject that belongs to
 * another activity is never touched.
 *
 * @param int $redactionid Activity the subject must belong to.
 * @param int $sujetid Subject to delete.
 * @return bool True if the subject was deleted, false if it is missing, foreign or in use.
 */
function redaction_delete_sujet(int $redactionid, int $sujetid): bool {
    global $DB;

    if (!$DB->record_exists('redaction_sujet', ['id' => $sujetid, 'redactionid' => $redactionid])) {
        return false;
    }
    if (
        $DB->record_exists('redaction_sujet_groupe', ['sujetid' => $sujetid])
        || $DB->record_exists('redaction_sujet_eleve', ['sujetid' => $sujetid])
        || $DB->record_exists('redaction_sujet_reponse', ['sujetid' => $sujetid])
    ) {
        return false;
    }
    $DB->delete_records('redaction_sujet', ['id' => $sujetid, 'redactionid' => $redactionid]);
    return true;
}

/**
 * Apply the settings the assigned subjects mode imposes.
 *
 * Subjects are answered with training attempts and an AI grade applied automatically, so those options
 * cannot be turned off. Group submission stays the teacher's choice: without it each student gets
 * their own subjects.
 *
 * @param stdClass $data Instance data coming from the settings form.
 */
function redaction_force_sujets_settings($data) {
    // An update that does not mention the option leaves it alone.
    if (!isset($data->sujets_enabled)) {
        return;
    }
    if (empty($data->sujets_enabled)) {
        $data->sujets_enabled = 0;
        return;
    }
    $data->training_enabled = 1;
    $data->ai_auto_apply = 1;
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

    // In the assigned subjects mode two first openings at once must not create two rows.
    $lock = null;
    if (!$record && !empty($redaction->sujets_enabled)) {
        $factory = \core\lock\lock_config::get_lock_factory('mod_redaction');
        $lock = $factory->get_lock('redaction_submission_' . $redaction->id . '_' . $params['groupid'] . '_' . $params['userid'], 5);
        if (!$lock) {
            throw new \moodle_exception('sujets_verrou', 'mod_redaction');
        }
    }

    try {
        if ($lock) {
            $record = $DB->get_record('redaction_submission', $params);
        }

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
    } finally {
        if ($lock) {
            $lock->release();
        }
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
 * @return int Result of grade_update(), one of the GRADE_UPDATE_* codes
 */
function redaction_update_grades($redaction, $userid = 0, $nullifnone = true) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $grades = redaction_get_user_grades($redaction, $userid);
    return redaction_grade_item_update($redaction, $grades);
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

    $result = grade_update(
        'mod/redaction',
        $redaction->course,
        'mod',
        'redaction',
        $redaction->id,
        0,
        $grades,
        $params
    );

    // With two grade items, grade_item::fetch() would throw instead of letting the caller read the code.
    if (!empty($redaction->sujets_enabled) && $result !== GRADE_UPDATE_MULTIPLE) {
        redaction_set_gradepass($redaction);
    }

    return $result;
}

/**
 * Set the pass grade of the grade item from the threshold of the assigned subjects mode.
 *
 * grade_update() ignores gradepass, so the grade item is written directly.
 *
 * @param stdClass $redaction The activity instance
 */
function redaction_set_gradepass($redaction) {
    $item = grade_item::fetch([
        'courseid' => $redaction->course,
        'itemtype' => 'mod',
        'itemmodule' => 'redaction',
        'iteminstance' => $redaction->id,
        'itemnumber' => 0,
    ]);
    $seuil = (float) ($redaction->sujets_seuil ?? 16);
    if ($item && (float) $item->gradepass !== $seuil) {
        $item->gradepass = $seuil;
        $item->update('mod/redaction');
    }
}

/**
 * Get user grades.
 *
 * Prefers the manual grade (submission.grade) if set by the teacher.
 * Falls back to the latest completed/applied AI evaluation grade when no manual grade exists.
 *
 * @param stdClass $redaction
 * @param int $userid
 * @return array
 */
function redaction_get_user_grades($redaction, $userid = 0) {
    global $DB;

    if (!empty($redaction->sujets_enabled)) {
        return redaction_get_user_grades_sujets($redaction, $userid, true);
    }

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

                if (!$submission) {
                    continue;
                }

                // Prefer manual grade; fall back to latest completed/applied AI evaluation.
                if ($submission->grade !== null) {
                    $rawgrade = $submission->grade;
                } else {
                    $rawgrade = $DB->get_field_sql(
                        "SELECT e.parsed_grade
                           FROM {redaction_ai_evaluations} e
                          WHERE e.submissionid = :submissionid
                            AND e.status IN ('completed', 'applied')
                       ORDER BY e.timecreated DESC, e.id DESC
                          LIMIT 1",
                        ['submissionid' => $submission->id]
                    );
                }

                if ($rawgrade !== null && $rawgrade !== false) {
                    $grades[$member->id] = new stdClass();
                    $grades[$member->id]->userid = $member->id;
                    $grades[$member->id]->rawgrade = $rawgrade;
                }
            }
        } else {
            // Group mode.
            $submission = $DB->get_record('redaction_submission', [
                'redactionid' => $redaction->id,
                'groupid' => $group->id,
                'userid' => 0
            ]);

            if (!$submission) {
                continue;
            }

            // Prefer manual grade; fall back to latest completed/applied AI evaluation.
            if ($submission->grade !== null) {
                $rawgrade = $submission->grade;
            } else {
                $rawgrade = $DB->get_field_sql(
                    "SELECT e.parsed_grade
                       FROM {redaction_ai_evaluations} e
                      WHERE e.submissionid = :submissionid
                        AND e.status IN ('completed', 'applied')
                   ORDER BY e.timecreated DESC, e.id DESC
                      LIMIT 1",
                    ['submissionid' => $submission->id]
                );
            }

            if ($rawgrade !== null && $rawgrade !== false) {
                foreach ($members as $member) {
                    if ($userid != 0 && $userid != $member->id) {
                        continue;
                    }
                    $grades[$member->id] = new stdClass();
                    $grades[$member->id]->userid = $member->id;
                    $grades[$member->id]->rawgrade = $rawgrade;
                }
            }
        }
    }

    return $grades;
}

/**
 * Get user grades in the assigned subjects mode.
 *
 * Each evaluation credits the members recorded when it was queued. A teacher grade counts for the
 * current members of the group and for anyone an evaluation of that submission credited. Someone who
 * worked in two groups keeps the higher of the two results, except that a teacher grade on the group
 * they belong to now wins, even when lower. A current member without a grade gets a null one, so that
 * a teacher grade once removed leaves the gradebook. For the gradebook, a grade the teacher locked there is
 * left out: grade_update() refuses it and answers GRADE_UPDATE_FAILED for the whole activity. For display
 * nothing is left out.
 *
 * @param stdClass $redaction
 * @param int $userid Optional user ID (0 for all users)
 * @param bool $pourcarnet True when the grades are about to be written to the gradebook: the locked ones are left out.
 * @return array
 */
function redaction_get_user_grades_sujets($redaction, $userid = 0, $pourcarnet = false) {
    global $DB;

    $meilleures = [];
    $imposees = [];
    // Request 16: only the latest successful final correction grades a copy, whatever the language setting is now.
    foreach ($DB->get_records('redaction_submission', ['redactionid' => $redaction->id]) as $submission) {
        $evaluations = $DB->get_records_select_menu(
            'redaction_ai_evaluations',
            "submissionid = ? AND finale = 1 AND status IN ('completed', 'applied') AND parsed_grade IS NOT NULL",
            [$submission->id],
            'id DESC',
            'id, parsed_grade',
            0,
            1
        );

        $membres = [];
        if ($evaluations) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($evaluations));
            foreach ($DB->get_records_select('redaction_sujet_membre', "evaluationid $insql", $params) as $ligne) {
                $membres[$ligne->evaluationid][] = (int) $ligne->userid;
            }
        }

        // The student of an individual submission, or the current members of the group.
        $courants = \mod_redaction\sujets::individuel($redaction)
            ? ($submission->userid ? [(int) $submission->userid] : [])
            : array_keys(groups_get_members($submission->groupid, 'u.id'));
        $enseignant = [];
        if ($submission->grade_enseignant !== null) {
            $enseignant = array_fill_keys(array_merge($courants, ...array_values($membres)), (float) $submission->grade_enseignant);
            $imposees += array_fill_keys($courants, (float) $submission->grade_enseignant);
        }

        foreach (\mod_redaction\notes_sujets::pour_membres($evaluations, $membres, $enseignant) as $membre => $note) {
            $meilleures[$membre] = max($meilleures[$membre] ?? $note, $note);
        }
        $meilleures += array_fill_keys($courants, null);
    }
    $meilleures = $imposees + $meilleures;

    $verrouilles = !$pourcarnet ? [] : $DB->get_fieldset_sql(
        "SELECT gg.userid
           FROM {grade_grades} gg
           JOIN {grade_items} gi ON gi.id = gg.itemid
          WHERE gi.itemtype = 'mod' AND gi.itemmodule = 'redaction' AND gi.iteminstance = ? AND gi.itemnumber = 0
            AND (gg.locked > 0 OR (gg.locktime > 0 AND gg.locktime < ?))",
        [$redaction->id, time()]
    );

    $grades = [];
    foreach (array_diff_key($meilleures, array_flip($verrouilles)) as $membre => $note) {
        if ($userid == 0 || $userid == $membre) {
            $grades[$membre] = (object) ['userid' => $membre, 'rawgrade' => $note];
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
function redaction_render_teacher_dashboard($cm, $redaction, int $groupid = 0) {
    global $OUTPUT, $PAGE;

    // Pre-load JS strings used by the dashboard AMD module via M.util.get_string.
    $PAGE->requires->strings_for_js(['dashboard_grade_distribution'], 'mod_redaction');

    // Get submission statistics for the current group filter (0 = all).
    $submissionstats = new \mod_redaction\dashboard\submission_stats($redaction->id, $groupid);
    $stats = $submissionstats->get_stats();

    // Prepare template context.
    $context = [
        'cmid' => $cm->id,
        'groupid' => $groupid,
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
        $summarygenerator = new \mod_redaction\dashboard\ai_summary_generator($redaction->id, $groupid);
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
 * @param stdClass|null $correction Retained for backward compatibility; unused.
 *                                  Deadline resolution now goes through
 *                                  redaction_get_effective_deadline().
 * @return array ['allowed' => bool, 'reason' => string]
 */
function redaction_can_submit_attempt($redaction, $submission, $correction = null) {
    global $DB;

    if (empty($redaction->training_enabled) || empty($redaction->ai_enabled)) {
        return ['allowed' => false, 'reason' => 'training_not_enabled'];
    }

    // In the assigned subjects mode there is no final version and the date only informs.
    $sujetsenabled = !empty($redaction->sujets_enabled);

    if (!$sujetsenabled && (int) $submission->status === 1) {
        return ['allowed' => false, 'reason' => 'already_submitted'];
    }

    if (!$sujetsenabled) {
        $effectivedeadline = redaction_get_effective_deadline(
            $redaction,
            (int) ($submission->userid ?? 0),
            (int) ($submission->groupid ?? 0)
        );
        if (!empty($effectivedeadline) && time() > $effectivedeadline) {
            return ['allowed' => false, 'reason' => 'deadline_passed'];
        }
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

    if ($sujetsenabled && !redaction_sujets_answered($redaction, $submission)) {
        return ['allowed' => false, 'reason' => 'empty_answer'];
    }

    return ['allowed' => true, 'reason' => ''];
}

/**
 * Whether a submission holds a non-empty answer to every subject of its group.
 *
 * @param stdClass $redaction
 * @param stdClass $submission
 * @return bool
 */
function redaction_sujets_answered($redaction, $submission) {
    global $DB;

    if (empty($submission->groupid) && !\mod_redaction\sujets::individuel($redaction)) {
        return false;
    }

    $reponses = $DB->get_records('redaction_sujet_reponse', ['submissionid' => $submission->id], '', 'sujetid, contenu, contenuformat');
    foreach (\mod_redaction\sujets::de_soumission($redaction, $submission) as $sujet) {
        $reponse = $reponses[$sujet->id] ?? null;
        if (!$reponse || \mod_redaction\notes_sujets::texte_reponse($reponse->contenu, (int) $reponse->contenuformat) === '') {
            return false;
        }
    }
    return true;
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
 * Get all evaluations for a submission, most recent first.
 *
 * @param int $submissionid Submission ID
 * @return array Array of evaluation records
 */
function redaction_get_training_evaluations($submissionid) {
    global $DB;

    return $DB->get_records_sql(
        'SELECT * FROM {redaction_ai_evaluations}
         WHERE submissionid = ?
         ORDER BY timecreated DESC',
        [$submissionid]
    );
}

/**
 * Get the latest completed evaluation for a submission.
 *
 * @param int $submissionid Submission ID
 * @return object|null
 */
function redaction_get_latest_training_evaluation($submissionid) {
    global $DB;

    $records = $DB->get_records_sql(
        'SELECT * FROM {redaction_ai_evaluations}
         WHERE submissionid = ? AND status = ?
         ORDER BY timecreated DESC',
        [$submissionid, 'completed'],
        0,
        1
    );

    return !empty($records) ? reset($records) : null;
}

/**
 * Returns the groups available for filtering on the grading page.
 *
 * If the activity has a grouping assigned, only its groups are returned.
 * Otherwise all course groups are returned.
 *
 * @param stdClass $cm Course module record (with groupingid)
 * @param int $courseid
 * @return array array of group records keyed by id
 */
function redaction_get_grading_filter_groups($cm, int $courseid): array {
    if (!empty($cm->groupingid)) {
        return groups_get_all_groups($courseid, 0, $cm->groupingid);
    }
    return groups_get_all_groups($courseid);
}

/**
 * Returns the user IDs belonging to a group, restricted to those with
 * the redaction:submit capability in the course.
 *
 * @param int $courseid
 * @param int $groupid 0 means no filter; returns all enrolled with the capability
 * @return int[] array of user IDs
 */
function redaction_get_filtered_userids(int $courseid, int $groupid): array {
    $coursecontext = context_course::instance($courseid);
    $users = get_enrolled_users($coursecontext, 'mod/redaction:submit', $groupid, 'u.id');
    return array_keys($users);
}

/**
 * Return the user-specific override record for a redaction, if any.
 *
 * @param int $redactionid
 * @param int $userid
 * @return stdClass|null
 */
function redaction_get_user_override($redactionid, $userid) {
    global $DB;
    if (empty($userid)) {
        return null;
    }
    $record = $DB->get_record('redaction_overrides', [
        'redactionid' => $redactionid,
        'userid' => $userid,
    ]);
    return $record ?: null;
}

/**
 * Return the group-specific override for a given group, if any.
 *
 * @param int $redactionid
 * @param int $groupid
 * @return stdClass|null
 */
function redaction_get_group_override($redactionid, $groupid) {
    global $DB;
    if (empty($groupid)) {
        return null;
    }
    $record = $DB->get_record('redaction_overrides', [
        'redactionid' => $redactionid,
        'groupid' => $groupid,
    ]);
    return $record ?: null;
}

/**
 * Return all group overrides applicable to a user, ordered by sortorder asc.
 *
 * @param int $redactionid
 * @param int $userid
 * @return array<int,stdClass> Records keyed by id; lowest sortorder first.
 */
function redaction_get_group_overrides_for_user($redactionid, $userid) {
    global $DB;
    if (empty($userid)) {
        return [];
    }
    $sql = "SELECT o.*
              FROM {redaction_overrides} o
              JOIN {groups_members} gm ON gm.groupid = o.groupid AND gm.userid = :userid
             WHERE o.redactionid = :redactionid
               AND o.groupid IS NOT NULL
          ORDER BY COALESCE(o.sortorder, 0) ASC, o.id ASC";
    return $DB->get_records_sql($sql, [
        'userid' => $userid,
        'redactionid' => $redactionid,
    ]);
}

/**
 * Return the effective deadline for a user/group draft on a redaction instance.
 *
 * Precedence:
 *   1. User override (deadline_date NOT NULL) — short-circuit.
 *   2. Lowest-sortorder group override the user is member of.
 *   3. Instance deadline from redaction_correction.
 *
 * For a group draft (userid = 0, groupid > 0): steps 1 and 2 are skipped;
 * only the direct group override for that groupid is consulted.
 *
 * @param stdClass $redaction
 * @param int $userid 0 for group drafts
 * @param int $groupid 0 for individual drafts
 * @return int|null Timestamp; null if no deadline applies
 */
function redaction_get_effective_deadline($redaction, $userid, $groupid = 0) {
    global $DB;

    if (!empty($userid)) {
        $user = redaction_get_user_override($redaction->id, $userid);
        if ($user && !empty($user->deadline_date)) {
            return (int) $user->deadline_date;
        }

        $groupoverrides = redaction_get_group_overrides_for_user($redaction->id, $userid);
        foreach ($groupoverrides as $row) {
            if (!empty($row->deadline_date)) {
                return (int) $row->deadline_date;
            }
        }
    } else if (!empty($groupid)) {
        $g = redaction_get_group_override($redaction->id, $groupid);
        if ($g && !empty($g->deadline_date)) {
            return (int) $g->deadline_date;
        }
    }

    $correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
    if ($correction && !empty($correction->deadline_date)) {
        return (int) $correction->deadline_date;
    }
    return null;
}

/**
 * Add overrides entries to the module admin menu.
 *
 * @param settings_navigation $settings
 * @param navigation_node $redactionnode
 */
function redaction_extend_settings_navigation(settings_navigation $settings, navigation_node $redactionnode) {
    global $PAGE;

    $cm = $PAGE->cm;
    if (!$cm) {
        return;
    }
    $context = context_module::instance($cm->id);

    if (!has_capability('mod/redaction:manageoverrides', $context)) {
        return;
    }

    $useruri = new moodle_url('/mod/redaction/pages/overrides.php',
        ['id' => $cm->id, 'mode' => 'user']);
    $redactionnode->add(
        get_string('useroverrides', 'mod_redaction'),
        $useruri,
        navigation_node::TYPE_SETTING,
        null,
        'mod_redaction_overrides_user'
    );

    $course = $PAGE->course;
    if ($course && groups_get_all_groups($course->id)) {
        $groupuri = new moodle_url('/mod/redaction/pages/overrides.php',
            ['id' => $cm->id, 'mode' => 'group']);
        $redactionnode->add(
            get_string('groupoverrides', 'mod_redaction'),
            $groupuri,
            navigation_node::TYPE_SETTING,
            null,
            'mod_redaction_overrides_group'
        );
    }
}
