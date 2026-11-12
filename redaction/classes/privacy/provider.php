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
 * Privacy Subsystem implementation for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2025 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\deletion_criteria;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Implementation of the privacy subsystem plugin provider for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2025 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Returns meta data about this system.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        // The redaction_submission table stores user submissions.
        $collection->add_database_table(
            'redaction_submission',
            [
                'userid' => 'privacy:metadata:redaction_submission:userid',
                'groupid' => 'privacy:metadata:redaction_submission:groupid',
                'titre' => 'privacy:metadata:redaction_submission:titre',
                'contenu' => 'privacy:metadata:redaction_submission:contenu',
                'status' => 'privacy:metadata:redaction_submission:status',
                'grade' => 'privacy:metadata:redaction_submission:grade',
                'feedback' => 'privacy:metadata:redaction_submission:feedback',
                'timesubmitted' => 'privacy:metadata:redaction_submission:timesubmitted',
                'timecreated' => 'privacy:metadata:redaction_submission:timecreated',
                'timemodified' => 'privacy:metadata:redaction_submission:timemodified',
            ],
            'privacy:metadata:redaction_submission'
        );

        // The redaction_history table stores version history of submissions.
        $collection->add_database_table(
            'redaction_history',
            [
                'userid' => 'privacy:metadata:redaction_history:userid',
                'titre' => 'privacy:metadata:redaction_history:titre',
                'contenu' => 'privacy:metadata:redaction_history:contenu',
                'version_number' => 'privacy:metadata:redaction_history:version_number',
                'word_count' => 'privacy:metadata:redaction_history:word_count',
                'char_count' => 'privacy:metadata:redaction_history:char_count',
                'saved_by' => 'privacy:metadata:redaction_history:saved_by',
                'timecreated' => 'privacy:metadata:redaction_history:timecreated',
            ],
            'privacy:metadata:redaction_history'
        );

        // The redaction_ai_evaluations table stores AI evaluation results.
        $collection->add_database_table(
            'redaction_ai_evaluations',
            [
                'userid' => 'privacy:metadata:redaction_ai_evaluations:userid',
                'provider' => 'privacy:metadata:redaction_ai_evaluations:provider',
                'model' => 'privacy:metadata:redaction_ai_evaluations:model',
                'raw_response' => 'privacy:metadata:redaction_ai_evaluations:raw_response',
                'parsed_grade' => 'privacy:metadata:redaction_ai_evaluations:parsed_grade',
                'parsed_feedback' => 'privacy:metadata:redaction_ai_evaluations:parsed_feedback',
                'criteria_json' => 'privacy:metadata:redaction_ai_evaluations:criteria_json',
                'langue_json' => 'privacy:metadata:redaction_ai_evaluations:langue_json',
                'status' => 'privacy:metadata:redaction_ai_evaluations:status',
                'applied_by' => 'privacy:metadata:redaction_ai_evaluations:applied_by',
                'timecreated' => 'privacy:metadata:redaction_ai_evaluations:timecreated',
            ],
            'privacy:metadata:redaction_ai_evaluations'
        );

        // The redaction_overrides table stores per-user deadline overrides.
        $collection->add_database_table(
            'redaction_overrides',
            [
                'userid' => 'privacy:metadata:redaction_overrides:userid',
                'deadline_date' => 'privacy:metadata:redaction_overrides:deadline_date',
                'timecreated' => 'privacy:metadata:redaction_overrides:timecreated',
                'timemodified' => 'privacy:metadata:redaction_overrides:timemodified',
            ],
            'privacy:metadata:redaction_overrides'
        );

        // Answers to assigned subjects (the expected answer, "corrige", is teacher-only and not personal data).
        $collection->add_database_table(
            'redaction_sujet_reponse',
            [
                'userid' => 'privacy:metadata:redaction_sujet_reponse:userid',
                'contenu' => 'privacy:metadata:redaction_sujet_reponse:contenu',
                'timemodified' => 'privacy:metadata:redaction_sujet_reponse:timemodified',
            ],
            'privacy:metadata:redaction_sujet_reponse'
        );

        // Group members credited by an AI evaluation in assigned subjects mode.
        $collection->add_database_table(
            'redaction_sujet_membre',
            [
                'userid' => 'privacy:metadata:redaction_sujet_membre:userid',
            ],
            'privacy:metadata:redaction_sujet_membre'
        );

        // Subjects drawn for a student when the activity is individual.
        $collection->add_database_table(
            'redaction_sujet_eleve',
            [
                'userid' => 'privacy:metadata:redaction_sujet_eleve:userid',
                'sujetid' => 'privacy:metadata:redaction_sujet_eleve:sujetid',
                'timecreated' => 'privacy:metadata:redaction_sujet_eleve:timecreated',
            ],
            'privacy:metadata:redaction_sujet_eleve'
        );

        // External AI services that may process user data.
        $collection->add_external_location_link(
            'ai_provider',
            [
                'submission_content' => 'privacy:metadata:ai_provider:submission_content',
            ],
            'privacy:metadata:ai_provider'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Get contexts where user has submissions.
        $sql = "SELECT c.id
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_submission} rs ON rs.redactionid = r.id
                 WHERE rs.userid = :userid";

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'redaction',
            'userid' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        // Get contexts where user saved history versions.
        $sql = "SELECT c.id
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_history} rh ON rh.redactionid = r.id
                 WHERE rh.saved_by = :userid";

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'redaction',
            'userid' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        // Get contexts where user has AI evaluations.
        $sql = "SELECT c.id
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_ai_evaluations} rae ON rae.redactionid = r.id
                 WHERE rae.userid = :userid OR rae.applied_by = :appliedby";

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'redaction',
            'userid' => $userid,
            'appliedby' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        $sql = "SELECT c.id
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_overrides} ro ON ro.redactionid = r.id
                 WHERE ro.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'redaction',
            'userid' => $userid,
        ]);

        $sql = "SELECT c.id
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_submission} rs ON rs.redactionid = r.id
            INNER JOIN {redaction_sujet_reponse} rsr ON rsr.submissionid = rs.id
                 WHERE rsr.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'redaction',
            'userid' => $userid,
        ]);

        $sql = "SELECT c.id
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_ai_evaluations} rae ON rae.redactionid = r.id
            INNER JOIN {redaction_sujet_membre} rsm ON rsm.evaluationid = rae.id
                 WHERE rsm.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'redaction',
            'userid' => $userid,
        ]);

        $sql = "SELECT c.id
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_sujet_eleve} rse ON rse.redactionid = r.id
                 WHERE rse.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'redaction',
            'userid' => $userid,
        ]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $params = [
            'instanceid' => $context->instanceid,
            'modulename' => 'redaction',
        ];

        // Users with submissions.
        $sql = "SELECT rs.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {redaction} r ON r.id = cm.instance
                  JOIN {redaction_submission} rs ON rs.redactionid = r.id
                 WHERE cm.id = :instanceid AND rs.userid != 0";

        $userlist->add_from_sql('userid', $sql, $params);

        // Users who saved history versions.
        $sql = "SELECT rh.saved_by as userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {redaction} r ON r.id = cm.instance
                  JOIN {redaction_history} rh ON rh.redactionid = r.id
                 WHERE cm.id = :instanceid";

        $userlist->add_from_sql('userid', $sql, $params);

        // Users with AI evaluations.
        $sql = "SELECT rae.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {redaction} r ON r.id = cm.instance
                  JOIN {redaction_ai_evaluations} rae ON rae.redactionid = r.id
                 WHERE cm.id = :instanceid AND rae.userid != 0";

        $userlist->add_from_sql('userid', $sql, $params);

        // Users who applied AI grades.
        $sql = "SELECT rae.applied_by as userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {redaction} r ON r.id = cm.instance
                  JOIN {redaction_ai_evaluations} rae ON rae.redactionid = r.id
                 WHERE cm.id = :instanceid AND rae.applied_by IS NOT NULL";

        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT ro.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {redaction} r ON r.id = cm.instance
                  JOIN {redaction_overrides} ro ON ro.redactionid = r.id
                 WHERE cm.id = :instanceid AND ro.userid IS NOT NULL";
        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT rsr.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {redaction} r ON r.id = cm.instance
                  JOIN {redaction_submission} rs ON rs.redactionid = r.id
                  JOIN {redaction_sujet_reponse} rsr ON rsr.submissionid = rs.id
                 WHERE cm.id = :instanceid AND rsr.userid != 0";
        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT rsm.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {redaction} r ON r.id = cm.instance
                  JOIN {redaction_ai_evaluations} rae ON rae.redactionid = r.id
                  JOIN {redaction_sujet_membre} rsm ON rsm.evaluationid = rae.id
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);

        $sql = "SELECT rse.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {redaction} r ON r.id = cm.instance
                  JOIN {redaction_sujet_eleve} rse ON rse.redactionid = r.id
                 WHERE cm.id = :instanceid";
        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        list($contextsql, $contextparams) = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED);

        $sql = "SELECT cm.id AS cmid,
                       r.name AS activityname,
                       rs.titre,
                       rs.contenu,
                       rs.status,
                       rs.grade,
                       rs.feedback,
                       rs.timesubmitted,
                       rs.timecreated,
                       rs.timemodified
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_submission} rs ON rs.redactionid = r.id
                 WHERE c.id {$contextsql}
                   AND rs.userid = :userid
              ORDER BY cm.id";

        $params = ['userid' => $user->id] + $contextparams;
        $submissions = $DB->get_recordset_sql($sql, $params);

        foreach ($submissions as $submission) {
            $context = \context_module::instance($submission->cmid);
            $contextdata = helper::get_context_data($context, $user);

            $submissiondata = [
                'title' => $submission->titre,
                'content' => $submission->contenu,
                'status' => $submission->status == 1 ? get_string('status_submitted', 'mod_redaction') : get_string('status_draft', 'mod_redaction'),
                'grade' => $submission->grade,
                'feedback' => $submission->feedback,
                'timesubmitted' => $submission->timesubmitted ? transform::datetime($submission->timesubmitted) : null,
                'timecreated' => transform::datetime($submission->timecreated),
                'timemodified' => transform::datetime($submission->timemodified),
            ];

            $contextdata = (object) array_merge((array) $contextdata, ['submission' => $submissiondata]);
            writer::with_context($context)->export_data([], $contextdata);
        }
        $submissions->close();

        // Export history data.
        $sql = "SELECT cm.id AS cmid,
                       rh.titre,
                       rh.contenu,
                       rh.version_number,
                       rh.word_count,
                       rh.char_count,
                       rh.timecreated
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_history} rh ON rh.redactionid = r.id
                 WHERE c.id {$contextsql}
                   AND rh.saved_by = :userid
              ORDER BY cm.id, rh.version_number";

        $params = ['userid' => $user->id] + $contextparams;
        $histories = $DB->get_recordset_sql($sql, $params);

        $historybycontext = [];
        foreach ($histories as $history) {
            $historybycontext[$history->cmid][] = [
                'title' => $history->titre,
                'content' => $history->contenu,
                'version_number' => $history->version_number,
                'word_count' => $history->word_count,
                'char_count' => $history->char_count,
                'timecreated' => transform::datetime($history->timecreated),
            ];
        }
        $histories->close();

        foreach ($historybycontext as $cmid => $historydata) {
            $context = \context_module::instance($cmid);
            writer::with_context($context)->export_data(
                [get_string('version_history', 'mod_redaction')],
                (object) ['versions' => $historydata]
            );
        }

        // Export AI evaluation data.
        $sql = "SELECT cm.id AS cmid,
                       rae.provider,
                       rae.model,
                       rae.parsed_grade,
                       rae.parsed_feedback,
                       rae.criteria_json,
                       rae.langue_json,
                       rae.status,
                       rae.timecreated
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_ai_evaluations} rae ON rae.redactionid = r.id
                 WHERE c.id {$contextsql}
                   AND rae.userid = :userid
              ORDER BY cm.id, rae.timecreated";

        $params = ['userid' => $user->id] + $contextparams;
        $evaluations = $DB->get_recordset_sql($sql, $params);

        $evalbycontext = [];
        foreach ($evaluations as $evaluation) {
            $evalbycontext[$evaluation->cmid][] = [
                'provider' => $evaluation->provider,
                'model' => $evaluation->model,
                'grade' => $evaluation->parsed_grade,
                'feedback' => $evaluation->parsed_feedback,
                'criteria' => $evaluation->criteria_json,
                'language' => $evaluation->langue_json,
                'status' => $evaluation->status,
                'timecreated' => transform::datetime($evaluation->timecreated),
            ];
        }
        $evaluations->close();

        foreach ($evalbycontext as $cmid => $evaldata) {
            $context = \context_module::instance($cmid);
            writer::with_context($context)->export_data(
                [get_string('ai_evaluation', 'mod_redaction')],
                (object) ['evaluations' => $evaldata]
            );
        }

        $sql = "SELECT cm.id AS cmid,
                       ro.deadline_date,
                       ro.timecreated,
                       ro.timemodified
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_overrides} ro ON ro.redactionid = r.id
                 WHERE c.id {$contextsql}
                   AND ro.userid = :userid
              ORDER BY cm.id";
        $params = ['userid' => $user->id] + $contextparams;
        $overrides = $DB->get_recordset_sql($sql, $params);

        $bycontext = [];
        foreach ($overrides as $o) {
            $bycontext[$o->cmid][] = [
                'deadline' => $o->deadline_date ? transform::datetime($o->deadline_date) : null,
                'timecreated' => transform::datetime($o->timecreated),
                'timemodified' => transform::datetime($o->timemodified),
            ];
        }
        $overrides->close();

        foreach ($bycontext as $cmid => $data) {
            $context = \context_module::instance($cmid);
            writer::with_context($context)->export_data(
                [get_string('overrides', 'mod_redaction')],
                (object) ['overrides' => $data]
            );
        }

        // Answers to assigned subjects written by this user. The expected answer is never exported.
        $sql = "SELECT rsr.id,
                       cm.id AS cmid,
                       rsj.titre,
                       rsr.contenu,
                       rsr.timemodified
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_submission} rs ON rs.redactionid = r.id
            INNER JOIN {redaction_sujet_reponse} rsr ON rsr.submissionid = rs.id
            INNER JOIN {redaction_sujet} rsj ON rsj.id = rsr.sujetid
                 WHERE c.id {$contextsql}
                   AND rsr.userid = :userid
              ORDER BY cm.id, rsj.sortorder";
        $params = ['userid' => $user->id] + $contextparams;
        $answers = $DB->get_recordset_sql($sql, $params);

        $answersbycontext = [];
        foreach ($answers as $answer) {
            $answersbycontext[$answer->cmid][] = [
                'subject' => $answer->titre,
                'content' => $answer->contenu,
                'timemodified' => transform::datetime($answer->timemodified),
            ];
        }
        $answers->close();

        foreach ($answersbycontext as $cmid => $data) {
            $context = \context_module::instance($cmid);
            writer::with_context($context)->export_data(
                [get_string('sujets_answers', 'mod_redaction')],
                (object) ['answers' => $data]
            );
        }

        // The corrections that credited this user, whoever of the group wrote the answers.
        $sql = "SELECT rae.id,
                       cm.id AS cmid,
                       rae.parsed_grade,
                       rae.parsed_feedback,
                       rae.langue_json,
                       rae.status,
                       rae.timecreated
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_ai_evaluations} rae ON rae.redactionid = r.id
            INNER JOIN {redaction_sujet_membre} rsm ON rsm.evaluationid = rae.id
                 WHERE c.id {$contextsql}
                   AND rsm.userid = :userid
              ORDER BY cm.id, rae.timecreated, rae.id";
        $credited = $DB->get_recordset_sql($sql, $params);

        $creditedbycontext = [];
        foreach ($credited as $evaluation) {
            $creditedbycontext[$evaluation->cmid][] = [
                'grade' => $evaluation->parsed_grade,
                'feedback' => $evaluation->parsed_feedback,
                'language' => $evaluation->langue_json,
                'status' => $evaluation->status,
                'timecreated' => transform::datetime($evaluation->timecreated),
            ];
        }
        $credited->close();

        foreach ($creditedbycontext as $cmid => $data) {
            $context = \context_module::instance($cmid);
            writer::with_context($context)->export_data(
                [get_string('sujets_credited', 'mod_redaction')],
                (object) ['evaluations' => $data]
            );
        }

        // Subjects drawn for this user in an individual activity.
        $sql = "SELECT rse.id,
                       cm.id AS cmid,
                       rsj.titre,
                       rse.timecreated
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_sujet_eleve} rse ON rse.redactionid = r.id
            INNER JOIN {redaction_sujet} rsj ON rsj.id = rse.sujetid
                 WHERE c.id {$contextsql}
                   AND rse.userid = :userid
              ORDER BY cm.id, rse.rang";
        $drawn = $DB->get_recordset_sql($sql, $params);

        $drawnbycontext = [];
        foreach ($drawn as $ligne) {
            $drawnbycontext[$ligne->cmid][] = [
                'subject' => $ligne->titre,
                'timecreated' => transform::datetime($ligne->timecreated),
            ];
        }
        $drawn->close();

        foreach ($drawnbycontext as $cmid => $data) {
            $context = \context_module::instance($cmid);
            writer::with_context($context)->export_data(
                [get_string('sujets_drawn', 'mod_redaction')],
                (object) ['subjects' => $data]
            );
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('redaction', $context->instanceid);
        if (!$cm) {
            return;
        }

        $redactionid = $cm->instance;

        // Delete assigned subjects answers and credited members.
        $DB->delete_records_select('redaction_sujet_membre',
            'evaluationid IN (SELECT id FROM {redaction_ai_evaluations} WHERE redactionid = :rid)', ['rid' => $redactionid]);
        $DB->delete_records('redaction_sujet_eleve', ['redactionid' => $redactionid]);
        $DB->delete_records_select('redaction_sujet_reponse',
            'submissionid IN (SELECT id FROM {redaction_submission} WHERE redactionid = :rid)', ['rid' => $redactionid]);

        // Delete all AI evaluations.
        $DB->delete_records('redaction_ai_evaluations', ['redactionid' => $redactionid]);

        // Delete all history.
        $DB->delete_records('redaction_history', ['redactionid' => $redactionid]);

        // Delete all submissions.
        $DB->delete_records('redaction_submission', ['redactionid' => $redactionid]);

        $DB->delete_records('redaction_overrides', ['redactionid' => $redactionid]);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('redaction', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $redactionid = $cm->instance;

            // Get submission IDs for this user.
            $submissions = $DB->get_records('redaction_submission', [
                'redactionid' => $redactionid,
                'userid' => $userid,
            ]);

            // Credited-member rows of this user, wherever the evaluation belongs.
            $DB->delete_records_select('redaction_sujet_membre',
                'userid = :uid AND evaluationid IN (SELECT id FROM {redaction_ai_evaluations} WHERE redactionid = :rid)',
                ['uid' => $userid, 'rid' => $redactionid]);
            $DB->delete_records('redaction_sujet_eleve', ['redactionid' => $redactionid, 'userid' => $userid]);

            // Group answers stay (they belong to the group): only detach the author.
            $DB->set_field_select('redaction_sujet_reponse', 'userid', 0,
                'userid = :uid AND submissionid IN (SELECT id FROM {redaction_submission} WHERE redactionid = :rid)',
                ['uid' => $userid, 'rid' => $redactionid]);

            foreach ($submissions as $submission) {
                $DB->delete_records_select('redaction_sujet_membre',
                    'evaluationid IN (SELECT id FROM {redaction_ai_evaluations} WHERE submissionid = :sid)',
                    ['sid' => $submission->id]);
                $DB->delete_records('redaction_sujet_reponse', ['submissionid' => $submission->id]);

                // Delete AI evaluations for this submission.
                $DB->delete_records('redaction_ai_evaluations', [
                    'submissionid' => $submission->id,
                    'userid' => $userid,
                ]);

                // Delete history for this submission.
                $DB->delete_records('redaction_history', ['submissionid' => $submission->id]);
            }

            // Delete user submissions.
            $DB->delete_records('redaction_submission', [
                'redactionid' => $redactionid,
                'userid' => $userid,
            ]);

            // Anonymize history saved by this user (don't delete as it belongs to submissions).
            $DB->set_field_select(
                'redaction_history',
                'saved_by',
                0,
                'redactionid = :redactionid AND saved_by = :userid',
                ['redactionid' => $redactionid, 'userid' => $userid]
            );

            // Anonymize applied_by in AI evaluations.
            $DB->set_field_select(
                'redaction_ai_evaluations',
                'applied_by',
                null,
                'redactionid = :redactionid AND applied_by = :userid',
                ['redactionid' => $redactionid, 'userid' => $userid]
            );

            $DB->delete_records('redaction_overrides', [
                'redactionid' => $redactionid,
                'userid' => $userid,
            ]);
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('redaction', $context->instanceid);
        if (!$cm) {
            return;
        }

        $redactionid = $cm->instance;
        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // Get submission IDs for these users.
        $sql = "SELECT id FROM {redaction_submission}
                 WHERE redactionid = :redactionid AND userid {$usersql}";
        $params = ['redactionid' => $redactionid] + $userparams;
        $submissionids = $DB->get_fieldset_sql($sql, $params);

        // Credited-member rows of these users, and detach them as authors of group answers.
        $DB->delete_records_select('redaction_sujet_membre',
            "userid {$usersql} AND evaluationid IN (SELECT id FROM {redaction_ai_evaluations} WHERE redactionid = :rid)",
            $userparams + ['rid' => $redactionid]);
        $DB->delete_records_select('redaction_sujet_eleve',
            "userid {$usersql} AND redactionid = :rid",
            $userparams + ['rid' => $redactionid]);
        $DB->set_field_select('redaction_sujet_reponse', 'userid', 0,
            "userid {$usersql} AND submissionid IN (SELECT id FROM {redaction_submission} WHERE redactionid = :rid)",
            $userparams + ['rid' => $redactionid]);

        if (!empty($submissionids)) {
            list($subsql, $subparams) = $DB->get_in_or_equal($submissionids, SQL_PARAMS_NAMED);

            $DB->delete_records_select('redaction_sujet_membre',
                "evaluationid IN (SELECT id FROM {redaction_ai_evaluations} WHERE submissionid {$subsql})", $subparams);
            $DB->delete_records_select('redaction_sujet_reponse', "submissionid {$subsql}", $subparams);

            // Delete AI evaluations for these submissions.
            $DB->delete_records_select(
                'redaction_ai_evaluations',
                "submissionid {$subsql} AND userid {$usersql}",
                $subparams + $userparams
            );

            // Delete history for these submissions.
            $DB->delete_records_select('redaction_history', "submissionid {$subsql}", $subparams);
        }

        // Delete user submissions.
        $DB->delete_records_select(
            'redaction_submission',
            "redactionid = :redactionid AND userid {$usersql}",
            ['redactionid' => $redactionid] + $userparams
        );

        $DB->delete_records_select(
            'redaction_overrides',
            "redactionid = :redactionid AND userid {$usersql}",
            ['redactionid' => $redactionid] + $userparams
        );

        // Anonymize history saved by these users.
        $DB->set_field_select(
            'redaction_history',
            'saved_by',
            0,
            "redactionid = :redactionid AND saved_by {$usersql}",
            ['redactionid' => $redactionid] + $userparams
        );

        // Anonymize applied_by in AI evaluations.
        $DB->set_field_select(
            'redaction_ai_evaluations',
            'applied_by',
            null,
            "redactionid = :redactionid AND applied_by {$usersql}",
            ['redactionid' => $redactionid] + $userparams
        );
    }
}

