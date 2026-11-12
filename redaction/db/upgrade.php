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
 * Upgrade script for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the redaction module.
 *
 * @param int $oldversion The old version of the module.
 * @return bool
 */
function xmldb_redaction_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    // Add AI summaries table for teacher dashboard.
    if ($oldversion < 2026012901) {
        // Define table redaction_ai_summaries.
        $table = new xmldb_table('redaction_ai_summaries');

        // Adding fields.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('redactionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('difficulties', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('strengths', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('recommendations', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('general_observation', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('submissions_analyzed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('provider', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('prompt_tokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('completion_tokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('redactionid', XMLDB_KEY_FOREIGN_UNIQUE, ['redactionid'], 'redaction', ['id']);

        // Create table if it doesn't exist.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Redaction savepoint reached.
        upgrade_mod_savepoint(true, 2026012901, 'redaction');
    }

    // Audit improvements: re-encrypt any legacy base64-encoded API keys,
    // add cache definition, and event system support.
    if ($oldversion < 2026020805) {
        // Re-encrypt any API keys that were stored with base64 fallback.
        $records = $DB->get_recordset('redaction', null, '', 'id, ai_api_key');
        foreach ($records as $record) {
            if (!empty($record->ai_api_key)) {
                // Try to detect base64-encoded keys (not encrypted with \core\encryption).
                $decoded = @base64_decode($record->ai_api_key, true);
                if ($decoded !== false && base64_encode($decoded) === $record->ai_api_key) {
                    // This looks like a base64-encoded key, re-encrypt it properly.
                    try {
                        $encrypted = \core\encryption::encrypt($decoded);
                        $DB->set_field('redaction', 'ai_api_key', $encrypted, ['id' => $record->id]);
                    } catch (\Exception $e) {
                        // Skip if encryption fails - key will need manual re-entry.
                        debugging('Failed to re-encrypt API key for redaction ' . $record->id . ': ' . $e->getMessage());
                    }
                }
            }
        }
        $records->close();

        // Redaction savepoint reached.
        upgrade_mod_savepoint(true, 2026020805, 'redaction');
    }

    if ($oldversion < 2026020806) {
        // Add scheduled_apply_at field to redaction_ai_evaluations for delayed auto-apply.
        $table = new xmldb_table('redaction_ai_evaluations');
        $field = new xmldb_field('scheduled_apply_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'applied_at');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026020806, 'redaction');
    }

    // Training mode and visual criteria editor.
    if ($oldversion < 2026021001) {
        // Table redaction: training mode fields.
        $table = new xmldb_table('redaction');

        $field = new xmldb_field('training_enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'ai_auto_apply');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('training_cooldown', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '900', 'training_enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('training_min_change', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '10', 'training_cooldown');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('training_max_attempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '5', 'training_min_change');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Table redaction_submission: training count and timing.
        $table = new xmldb_table('redaction_submission');

        $field = new xmldb_field('training_count', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('last_training_time', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'training_count');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Table redaction_ai_evaluations: training flag.
        $table = new xmldb_table('redaction_ai_evaluations');

        $field = new xmldb_field('is_training', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'scheduled_apply_at');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026021001, 'redaction');
    }

    // Drop obsolete training configuration fields.
    if ($oldversion < 2026050601) {
        $table = new xmldb_table('redaction');
        $field = new xmldb_field('training_cooldown');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        $field = new xmldb_field('training_min_change');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $table = new xmldb_table('redaction_submission');
        $field = new xmldb_field('last_training_time');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $table = new xmldb_table('redaction_ai_evaluations');
        $field = new xmldb_field('is_training');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026050601, 'redaction');
    }

    if ($oldversion < 2026050602) {
        $table = new xmldb_table('redaction_ai_summaries');

        // Add groupid field if not present.
        $field = new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0', 'redactionid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Drop the old foreign-unique key on redactionid (if it exists).
        $oldkey = new xmldb_key('redactionid', XMLDB_KEY_FOREIGN_UNIQUE,
            ['redactionid'], 'redaction', ['id']);
        if ($dbman->find_key_name($table, $oldkey)) {
            $dbman->drop_key($table, $oldkey);
        }

        // Add a plain foreign key on redactionid.
        $fkey = new xmldb_key('redactionid', XMLDB_KEY_FOREIGN,
            ['redactionid'], 'redaction', ['id']);
        if (!$dbman->find_key_name($table, $fkey)) {
            $dbman->add_key($table, $fkey);
        }

        // Add the composite unique key.
        $newkey = new xmldb_key('redactionid_groupid', XMLDB_KEY_UNIQUE,
            ['redactionid', 'groupid']);
        if (!$dbman->find_key_name($table, $newkey)) {
            $dbman->add_key($table, $newkey);
        }

        upgrade_mod_savepoint(true, 2026050602, 'redaction');
    }

    if ($oldversion < 2026051200) {
        $table = new xmldb_table('redaction_overrides');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('redactionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('deadline_date', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, null, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('redactionid', XMLDB_KEY_FOREIGN, ['redactionid'], 'redaction', ['id']);
        $table->add_key('groupid', XMLDB_KEY_FOREIGN, ['groupid'], 'groups', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('redactionid_userid', XMLDB_INDEX_NOTUNIQUE, ['redactionid', 'userid']);
        $table->add_index('redactionid_groupid', XMLDB_INDEX_NOTUNIQUE, ['redactionid', 'groupid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026051200, 'redaction');
    }

    if ($oldversion < 2026110600) {
        // Assigned subjects mode: option fields on the activity.
        $table = new xmldb_table('redaction');
        $field = new xmldb_field('sujets_enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'training_max_attempts');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('sujets_par_groupe', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '2', 'sujets_enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('sujets_seuil', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '16', 'sujets_par_groupe');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Teacher grade on a submission.
        $table = new xmldb_table('redaction_submission');
        $field = new xmldb_field('grade_enseignant', XMLDB_TYPE_NUMBER, '10, 2', null, null, null, null, 'grade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Per-subject results of an AI evaluation.
        $table = new xmldb_table('redaction_ai_evaluations');
        $field = new xmldb_field('sujets_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'criteria_json');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Subjects.
        $table = new xmldb_table('redaction_sujet');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('redactionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('titre', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contenu', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('contenuformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('corrige', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('redactionid', XMLDB_KEY_FOREIGN, ['redactionid'], 'redaction', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Subjects assigned to groups.
        $table = new xmldb_table('redaction_sujet_groupe');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('redactionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sujetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('rang', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('redactionid', XMLDB_KEY_FOREIGN, ['redactionid'], 'redaction', ['id']);
        $table->add_key('groupid', XMLDB_KEY_FOREIGN, ['groupid'], 'groups', ['id']);
        $table->add_key('sujetid', XMLDB_KEY_FOREIGN, ['sujetid'], 'redaction_sujet', ['id']);
        $table->add_key('redactionid_groupid_sujetid', XMLDB_KEY_UNIQUE, ['redactionid', 'groupid', 'sujetid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Answers per subject.
        $table = new xmldb_table('redaction_sujet_reponse');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('submissionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sujetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('contenu', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('contenuformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('submissionid', XMLDB_KEY_FOREIGN, ['submissionid'], 'redaction_submission', ['id']);
        $table->add_key('sujetid', XMLDB_KEY_FOREIGN, ['sujetid'], 'redaction_sujet', ['id']);
        $table->add_key('submissionid_sujetid', XMLDB_KEY_UNIQUE, ['submissionid', 'sujetid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Group members credited by an evaluation.
        $table = new xmldb_table('redaction_sujet_membre');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('evaluationid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('evaluationid', XMLDB_KEY_FOREIGN, ['evaluationid'], 'redaction_ai_evaluations', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('evaluationid_userid', XMLDB_KEY_UNIQUE, ['evaluationid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026110600, 'redaction');
    }

    if ($oldversion < 2026111100) {
        // Language mastery (note de service of 11/09/2026): on in every activity, 2 points out of 20.
        $table = new xmldb_table('redaction');
        $field = new xmldb_field('langue_enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'sujets_seuil');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('langue_points', XMLDB_TYPE_NUMBER, '5, 2', null, XMLDB_NOTNULL, null, '2', 'langue_enabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $DB->execute('UPDATE {redaction} SET langue_enabled = 1, langue_points = 2');

        // Setting each evaluation was graded with: null for the ones graded before, which no longer count.
        $table = new xmldb_table('redaction_ai_evaluations');
        $field = new xmldb_field('langue_json', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sujets_json');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('langue_points', XMLDB_TYPE_NUMBER, '5, 2', null, null, null, null, 'langue_json');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Assigned subjects mode: the old attempts no longer count, nor use up the maximum. The teacher grade stays.
        foreach ($DB->get_fieldset_select('redaction', 'id', 'sujets_enabled = 1') as $redactionid) {
            $DB->execute('UPDATE {redaction_submission} SET training_count = 0, grade = grade_enseignant WHERE redactionid = ?',
                [$redactionid]);
            $tache = new \mod_redaction\task\mettre_a_jour_carnet();
            $tache->set_custom_data(['redactionid' => (int) $redactionid]);
            \core\task\manager::queue_adhoc_task($tache);
        }

        upgrade_mod_savepoint(true, 2026111100, 'redaction');
    }

    if ($oldversion < 2026111200) {
        // One subject per student when the activity is individual (request 13).
        $table = new xmldb_table('redaction_sujet_eleve');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('redactionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sujetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('rang', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('redactionid', XMLDB_KEY_FOREIGN, ['redactionid'], 'redaction', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_key('sujetid', XMLDB_KEY_FOREIGN, ['sujetid'], 'redaction_sujet', ['id']);
        $table->add_key('redactionid_userid_sujetid', XMLDB_KEY_UNIQUE, ['redactionid', 'userid', 'sujetid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026111200, 'redaction');
    }

    if ($oldversion < 2026111201) {
        // Sentences expected in each answer (request 9): 2, a warning only.
        $table = new xmldb_table('redaction');
        $field = new xmldb_field('sujets_phrases', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '2', 'langue_points');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Words of the discipline expected for each subject (request 7).
        $table = new xmldb_table('redaction_sujet');
        $field = new xmldb_field('lexique', XMLDB_TYPE_TEXT, null, null, null, null, null, 'corrige');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026111201, 'redaction');
    }

    if ($oldversion < 2026111202) {
        // Grader text of each activity (request 14): what the student explains and the two help sentences.
        $table = new xmldb_table('redaction');
        foreach (['sujets_theme' => 'sujets_phrases', 'sujets_aide_partiel' => 'sujets_theme', 'sujets_aide_rien' => 'sujets_aide_partiel'] as $nom => $apres) {
            $field = new xmldb_field($nom, XMLDB_TYPE_TEXT, null, null, null, null, null, $apres);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // The activities already in the assigned subjects mode were written for "how a system steers" (ROB4):
        // they keep that wording, word for word, in the site language.
        $strings = get_string_manager();
        $lang = $CFG->lang ?? 'en';
        $textes = [
            'sujets_theme' => $strings->get_string('sujets_migration_theme', 'mod_redaction', null, $lang),
            'sujets_aide_partiel' => $strings->get_string('sujets_commentaire_fonctionnement', 'mod_redaction', null, $lang),
            'sujets_aide_rien' => $strings->get_string('sujets_commentaire_les_deux', 'mod_redaction', null, $lang),
        ];
        foreach ($DB->get_fieldset_select('redaction', 'id', 'sujets_enabled = 1') as $redactionid) {
            foreach ($textes as $champ => $texte) {
                $DB->set_field('redaction', $champ, $texte, ['id' => $redactionid]);
            }
        }

        upgrade_mod_savepoint(true, 2026111202, 'redaction');
    }

    if ($oldversion < 2026111203) {
        // Level the threshold validates, per activity (request 15): Fragile, as every activity said until now.
        $table = new xmldb_table('redaction');
        $field = new xmldb_field('sujets_niveau', XMLDB_TYPE_CHAR, '2', null, XMLDB_NOTNULL, null, 'F', 'sujets_phrases');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026111203, 'redaction');
    }

    if ($oldversion < 2026111204) {
        // Grades to the tenth, as shown (11.97 was shown 12 and did not validate a threshold of 12): work out again
        // every grade of the assigned subjects mode from its parts, then the grade of each copy and the gradebook.
        require_once($CFG->dirroot . '/mod/redaction/lib.php');
        foreach ($DB->get_records('redaction', ['sujets_enabled' => 1]) as $redaction) {
            $evaluations = $DB->get_records_select('redaction_ai_evaluations', 'redactionid = ? AND parsed_grade IS NOT NULL',
                [$redaction->id], 'id', 'id, parsed_grade, sujets_json, langue_json, langue_points');
            foreach ($evaluations as $evaluation) {
                $notes = array_map(fn($d) => isset($d['note']) ? (float) $d['note'] : null,
                    (array) json_decode((string) $evaluation->sujets_json, true));
                $langue = json_decode((string) $evaluation->langue_json, true);
                $p = $evaluation->langue_points === null ? 0.0 : (float) $evaluation->langue_points;
                $note = null;
                if ($notes) {
                    $note = \mod_redaction\notes_sujets::note_totale($notes,
                        $p > 0 && isset($langue['points']) ? (float) $langue['points'] : null, $p);
                }
                $note = $note ?? round((float) $evaluation->parsed_grade, 1);
                $DB->set_field('redaction_ai_evaluations', 'parsed_grade', $note, ['id' => $evaluation->id]);
            }

            // Grade of each copy: the best counted correction, or the teacher's.
            $reglage = \mod_redaction\notes_sujets::reglage_langue($redaction);
            foreach ($DB->get_records('redaction_submission', ['redactionid' => $redaction->id]) as $submission) {
                $notes = $DB->get_fieldset_select('redaction_ai_evaluations', 'parsed_grade',
                    "submissionid = ? AND status IN ('completed', 'applied') AND langue_points = ?", [$submission->id, $reglage]);
                $enseignant = $submission->grade_enseignant !== null ? (float) $submission->grade_enseignant : null;
                $DB->set_field('redaction_submission', 'grade', \mod_redaction\notes_sujets::meilleure($notes, $enseignant),
                    ['id' => $submission->id]);
            }
            redaction_update_grades($redaction);
        }

        upgrade_mod_savepoint(true, 2026111204, 'redaction');
    }

    return true;
}
