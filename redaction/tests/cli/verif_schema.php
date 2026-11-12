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
 * CLI check of the "assigned subjects" schema (no PHPUnit needed).
 *
 * Usage: php verif_schema.php
 * Any record it creates is removed before it finishes.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Walk up from this file until a Moodle config.php is found.
$dir = __DIR__;
$configfile = null;
while ($dir !== dirname($dir)) {
    if (is_readable($dir . '/config.php') && is_readable($dir . '/version.php') && is_dir($dir . '/lib')) {
        $configfile = $dir . '/config.php';
        break;
    }
    $dir = dirname($dir);
}
if ($configfile === null) {
    fwrite(STDERR, "config.php not found\n");
    exit(2);
}
require($configfile);
require_once($CFG->dirroot . '/mod/redaction/lib.php');
require_once($CFG->libdir . '/gradelib.php');

$failures = 0;
$check = function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$dbman = $DB->get_manager();

// Expected schema.
$expectedfields = [
    'redaction' => ['sujets_enabled', 'sujets_par_groupe', 'sujets_seuil', 'langue_enabled', 'langue_points', 'sujets_phrases',
        'sujets_theme', 'sujets_aide_partiel', 'sujets_aide_rien'],
    'redaction_submission' => ['grade_enseignant', 'grade_enseignant_time'],
    'redaction_ai_evaluations' => ['sujets_json', 'langue_json', 'langue_points', 'finale', 'empreinte'],
    'redaction_sujet' => ['id', 'redactionid', 'sortorder', 'titre', 'contenu', 'contenuformat', 'corrige', 'lexique',
        'timecreated', 'timemodified'],
    'redaction_sujet_groupe' => ['id', 'redactionid', 'groupid', 'sujetid', 'rang', 'timecreated'],
    'redaction_sujet_eleve' => ['id', 'redactionid', 'userid', 'sujetid', 'rang', 'timecreated'],
    'redaction_sujet_reponse' => ['id', 'submissionid', 'sujetid', 'contenu', 'contenuformat', 'userid', 'timemodified'],
    'redaction_sujet_membre' => ['id', 'evaluationid', 'userid'],
];
foreach ($expectedfields as $tablename => $fields) {
    $table = new xmldb_table($tablename);
    $exists = $dbman->table_exists($table);
    $check("table {$tablename} exists", $exists);
    if (!$exists) {
        continue;
    }
    foreach ($fields as $fieldname) {
        $check("field {$tablename}.{$fieldname} exists", $dbman->field_exists($table, new xmldb_field($fieldname)));
    }
}

// Language part (2.4.0): every activity has it, with a setting between 2 and 10 points.
if ($dbman->field_exists(new xmldb_table('redaction'), new xmldb_field('langue_enabled'))) {
    $off = $DB->count_records_select('redaction', 'langue_enabled = 0');
    $check('right after the upgrade, every activity has the language part on (' . $off . ' off)', $off == 0);
    $horsbornes = $DB->count_records_select('redaction', 'langue_enabled = 1 AND (langue_points < 2 OR langue_points > 10)');
    $check('every language setting is between 2 and 10 points (' . $horsbornes . ' outside)', $horsbornes == 0);
}

// Existing activities are untouched: option off.
if ($dbman->field_exists(new xmldb_table('redaction'), new xmldb_field('sujets_enabled'))) {
    $enabled = $DB->count_records_select('redaction', 'sujets_enabled <> 0');
    $check('no existing activity has sujets_enabled on (' . $enabled . ' found)', $enabled == 0);
}

// Round trip on a throw-away activity: defaults, unique keys, cleanup on delete.
$allthere = true;
foreach (['redaction_sujet', 'redaction_sujet_groupe', 'redaction_sujet_eleve', 'redaction_sujet_reponse', 'redaction_sujet_membre'] as $t) {
    $allthere = $allthere && $dbman->table_exists(new xmldb_table($t));
}
if ($allthere && $dbman->field_exists(new xmldb_table('redaction'), new xmldb_field('sujets_enabled'))) {
    $now = time();
    $rid = 0;
    try {
        $rid = $DB->insert_record('redaction', (object) [
            'course' => 0, 'name' => 'verif_schema temporaire', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $rec = $DB->get_record('redaction', ['id' => $rid], '*', MUST_EXIST);
        $check('default sujets_enabled = 0', (int) $rec->sujets_enabled === 0);
        $check('default sujets_par_groupe = 2', (int) $rec->sujets_par_groupe === 2);
        $check('default sujets_seuil = 16', (float) $rec->sujets_seuil == 16.0);
        $check('default langue_enabled = 1', (int) $rec->langue_enabled === 1);
        $check('default langue_points = 2', (float) $rec->langue_points == 2.0);

        $sid = $DB->insert_record('redaction_sujet', (object) [
            'redactionid' => $rid, 'sortorder' => 1, 'titre' => 'S1', 'contenu' => 'c', 'contenuformat' => 1,
            'corrige' => 'x', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('redaction_sujet_groupe', (object) [
            'redactionid' => $rid, 'groupid' => 1, 'sujetid' => $sid, 'rang' => 1, 'timecreated' => $now,
        ]);
        $subid = $DB->insert_record('redaction_submission', (object) [
            'redactionid' => $rid, 'groupid' => 1, 'userid' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $check('grade_enseignant is null by default',
            $DB->get_field('redaction_submission', 'grade_enseignant', ['id' => $subid]) === null);
        $DB->insert_record('redaction_sujet_reponse', (object) [
            'submissionid' => $subid, 'sujetid' => $sid, 'contenu' => 'r', 'contenuformat' => 1,
            'userid' => 0, 'timemodified' => $now,
        ]);
        $evalid = $DB->insert_record('redaction_ai_evaluations', (object) [
            'redactionid' => $rid, 'submissionid' => $subid, 'groupid' => 1, 'userid' => 0,
            'provider' => 'x', 'model' => 'x', 'sujets_json' => '[]', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('redaction_sujet_membre', (object) ['evaluationid' => $evalid, 'userid' => 0]);
        $ev = $DB->get_record('redaction_ai_evaluations', ['id' => $evalid]);
        $check('a new evaluation has no language part by default', $ev->langue_json === null && $ev->langue_points === null);

        // Unique keys must refuse duplicates.
        $uniques = [
            'redaction_sujet_groupe' => (object) ['redactionid' => $rid, 'groupid' => 1, 'sujetid' => $sid, 'rang' => 2,
                'timecreated' => $now],
            'redaction_sujet_reponse' => (object) ['submissionid' => $subid, 'sujetid' => $sid, 'contenu' => 'r2',
                'contenuformat' => 1, 'userid' => 0, 'timemodified' => $now],
            'redaction_sujet_membre' => (object) ['evaluationid' => $evalid, 'userid' => 0],
        ];
        foreach ($uniques as $t => $dup) {
            $refused = false;
            try {
                $DB->insert_record($t, $dup);
            } catch (dml_exception $e) {
                $refused = true;
            }
            $check("{$t} refuses a duplicate", $refused);
        }

        // Subject deletion: refused while in use, and never across activities.
        $other = $DB->insert_record('redaction', (object) [
            'course' => 0, 'name' => 'verif_schema temporaire 2', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        try {
            $check('delete_sujet refuses a subject held by a group', !redaction_delete_sujet($rid, $sid));
            $check('... and keeps it', $DB->record_exists('redaction_sujet', ['id' => $sid]));
            $check('delete_sujet refuses a subject of another activity', !redaction_delete_sujet($other, $sid));
            $check('... and keeps the answers', $DB->record_exists('redaction_sujet_reponse', ['sujetid' => $sid]));
            $check('... and keeps the attribution', $DB->record_exists('redaction_sujet_groupe', ['sujetid' => $sid]));

            $DB->delete_records('redaction_sujet_groupe', ['sujetid' => $sid]);
            $check('delete_sujet refuses a subject that has an answer', !redaction_delete_sujet($rid, $sid));
            $DB->delete_records('redaction_sujet_reponse', ['sujetid' => $sid]);
            $check('delete_sujet deletes an unused subject', redaction_delete_sujet($rid, $sid));
            $check('... which is gone', !$DB->record_exists('redaction_sujet', ['id' => $sid]));

            // Restore a subject, answer and attribution so the instance cleanup below is still exercised.
            $sid = $DB->insert_record('redaction_sujet', (object) [
                'redactionid' => $rid, 'sortorder' => 1, 'titre' => 'S1', 'contenu' => 'c', 'contenuformat' => 1,
                'corrige' => 'x', 'timecreated' => $now, 'timemodified' => $now,
            ]);
            $DB->insert_record('redaction_sujet_groupe', (object) [
                'redactionid' => $rid, 'groupid' => 1, 'sujetid' => $sid, 'rang' => 1, 'timecreated' => $now,
            ]);
            $DB->insert_record('redaction_sujet_eleve', (object) [
                'redactionid' => $rid, 'userid' => 2, 'sujetid' => $sid, 'rang' => 1, 'timecreated' => $now,
            ]);
            $DB->insert_record('redaction_sujet_reponse', (object) [
                'submissionid' => $subid, 'sujetid' => $sid, 'contenu' => 'r', 'contenuformat' => 1,
                'userid' => 0, 'timemodified' => $now,
            ]);
        } finally {
            redaction_delete_instance($other);
        }

        // sujets_enabled is left alone by an update that does not mention it.
        $upd = (object) ['sujets_enabled' => 1];
        redaction_force_sujets_settings($upd);
        $check('enabled forces training and auto-apply, leaves group submission to the teacher',
            $upd->training_enabled == 1 && $upd->ai_auto_apply == 1 && !isset($upd->group_submission));
        $upd = (object) ['name' => 'x'];
        redaction_force_sujets_settings($upd);
        $check('absent sujets_enabled stays absent', !isset($upd->sujets_enabled));

        redaction_delete_instance($rid);
        $check('delete_instance removes sujets', !$DB->record_exists('redaction_sujet', ['redactionid' => $rid]));
        $check('delete_instance removes sujet_groupe', !$DB->record_exists('redaction_sujet_groupe', ['redactionid' => $rid]));
        $check('delete_instance removes sujet_eleve', !$DB->record_exists('redaction_sujet_eleve', ['redactionid' => $rid]));
        $check('delete_instance removes sujet_reponse', !$DB->record_exists('redaction_sujet_reponse', ['submissionid' => $subid]));
        $check('delete_instance removes sujet_membre', !$DB->record_exists('redaction_sujet_membre', ['evaluationid' => $evalid]));
        $check('delete_instance removes the activity', !$DB->record_exists('redaction', ['id' => $rid]));
    } finally {
        // Safety net if anything failed half-way.
        if ($rid && $DB->record_exists('redaction', ['id' => $rid])) {
            redaction_delete_instance($rid);
        }
    }
}

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL PASS\n";
exit($failures ? 1 : 0);
