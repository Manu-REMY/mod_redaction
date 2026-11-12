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
 * CLI check of the backup and restore of the assigned subjects mode, with user data (no PHPUnit needed).
 *
 * Usage: php verif_sauvegarde.php
 * It creates a throw-away course with users, a group, subjects, answers and a correction, backs it up
 * and restores it into a second course, compares, then removes everything.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

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
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

use mod_redaction\ai_evaluator;

$failures = 0;
$check = function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$admin = get_admin();
\core\session\manager::set_user($admin);

$now = time();
$courses = [];
$users = [];
$rids = [];
$studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
$moduleid = (int) $DB->get_field('modules', 'id', ['name' => 'redaction'], MUST_EXIST);
$folder = 'verif_sauvegarde_' . $now;

try {
    $course = create_course((object) [
        'fullname' => 'verif_sauvegarde temporaire',
        'shortname' => 'verif_sauvegarde_' . $now,
        'category' => core_course_category::get_default()->id,
    ]);
    $courses[] = $course->id;

    foreach (['A', 'B'] as $lettre) {
        $id = user_create_user((object) [
            'username' => 'verifsav' . strtolower($lettre) . $now, 'firstname' => 'Prenom' . $lettre, 'lastname' => 'Nom' . $lettre,
            'email' => "verifsav{$lettre}{$now}@example.invalid", 'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
        ], false, false);
        $users[$lettre] = $id;
        enrol_try_internal_enrol($course->id, $id, $studentrole);
    }
    [$a, $b] = array_values($users);
    $groupe = groups_create_group((object) ['courseid' => $course->id, 'name' => '9Z-GRP01']);
    groups_add_member($groupe, $a);
    groups_add_member($groupe, $b);

    $id = redaction_add_instance((object) [
        'course' => $course->id, 'name' => 'verif_sauvegarde temporaire', 'intro' => '', 'introformat' => FORMAT_HTML,
        'ai_enabled' => 1, 'ai_provider' => 'simule', 'training_enabled' => 1, 'group_submission' => 1, 'training_max_attempts' => 5,
        'sujets_enabled' => 1, 'sujets_par_groupe' => 2, 'sujets_seuil' => 16, 'langue_enabled' => 1, 'langue_points' => 3, 'sujets_phrases' => 3,
    ]);
    $rids[] = $id;
    $cmid = add_course_module((object) ['course' => $course->id, 'module' => $moduleid, 'instance' => $id, 'section' => 0]);
    course_add_cm_to_section($course->id, $cmid, 0);
    $redaction = $DB->get_record('redaction', ['id' => $id], '*', MUST_EXIST);

    $sujets = [];
    foreach ([1, 2] as $i) {
        $sujets[$i] = $DB->insert_record('redaction_sujet', (object) [
            'redactionid' => $redaction->id, 'sortorder' => $i, 'titre' => 'Systeme' . $i, 'contenu' => 'Enonce' . $i,
            'contenuformat' => FORMAT_HTML, 'corrige' => 'Corrige' . $i, 'lexique' => 'mot' . $i, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('redaction_sujet_groupe', (object) [
            'redactionid' => $redaction->id, 'groupid' => $groupe, 'sujetid' => $sujets[$i], 'rang' => $i, 'timecreated' => $now,
        ]);
    }
    $submission = redaction_get_or_create_submission($redaction, $groupe, $a);
    foreach ($sujets as $i => $sujetid) {
        $DB->insert_record('redaction_sujet_reponse', (object) [
            'submissionid' => $submission->id, 'sujetid' => $sujetid, 'contenu' => 'Reponse' . $i, 'contenuformat' => FORMAT_PLAIN,
            'userid' => $a, 'timemodified' => $now,
        ]);
    }
    $evaluation = ai_evaluator::queue_evaluation($redaction->id, $submission->id, (int) $groupe, $a, true);
    foreach ($DB->get_records('task_adhoc', ['classname' => '\mod_redaction\task\evaluate_submission']) as $tache) {
        if ((int) (json_decode($tache->customdata)->evaluationid ?? 0) === $evaluation) {
            $DB->delete_records('task_adhoc', ['id' => $tache->id]);
        }
    }
    $languejson = '{"paliers":{"orthographe":"I","syntaxe":"S","lexique":"S","organisation":"TS"},"fautes":[],"remarques":[],'
        . '"ecartees":0,"points":2,"sur":3}';
    $DB->update_record('redaction_ai_evaluations', (object) ['id' => $evaluation, 'status' => 'applied', 'parsed_grade' => 17,
        'langue_json' => $languejson, 'langue_points' => 3]);
    $membres = $DB->count_records('redaction_sujet_membre', ['evaluationid' => $evaluation]);
    $check('source: 2 subjects, 2 attributions, 2 answers, 2 credited members',
        $DB->count_records('redaction_sujet', ['redactionid' => $redaction->id]) === 2
        && $DB->count_records('redaction_sujet_groupe', ['redactionid' => $redaction->id]) === 2
        && $DB->count_records('redaction_sujet_reponse', ['submissionid' => $submission->id]) === 2
        && $membres === 2);

    // Backup with user data.
    $bc = new backup_controller(backup::TYPE_1COURSE, $course->id, backup::FORMAT_MOODLE, backup::INTERACTIVE_NO,
        backup::MODE_GENERAL, $admin->id);
    $bc->get_plan()->get_setting('users')->set_value(true);
    $bc->get_plan()->get_setting('anonymize')->set_value(false);
    $bc->execute_plan();
    $fichier = $bc->get_results()['backup_destination'];
    $check('backup produced a file', !empty($fichier));
    $fichier->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $CFG->tempdir . '/backup/' . $folder);
    $bc->destroy();

    // Restore into a new course.
    $neuf = restore_dbops::create_new_course('verif_sauvegarde restauree', 'verif_sauvegarde_r' . $now, core_course_category::get_default()->id);
    $courses[] = $neuf;
    $rc = new restore_controller($folder, $neuf, backup::INTERACTIVE_NO, backup::MODE_GENERAL, $admin->id, backup::TARGET_NEW_COURSE);
    $rc->get_plan()->get_setting('users')->set_value(true);
    $check('restore precheck', $rc->execute_precheck());
    $rc->execute_plan();
    $rc->destroy();

    $copie = $DB->get_record('redaction', ['course' => $neuf]);
    $check('restored activity found', (bool) $copie);
    if ($copie) {
        $nouveaux = $DB->get_records('redaction_sujet', ['redactionid' => $copie->id], 'sortorder');
        $check('restored: 2 subjects, 2 attributions',
            count($nouveaux) === 2 && $DB->count_records('redaction_sujet_groupe', ['redactionid' => $copie->id]) === 2);
        $sub = $DB->get_record('redaction_submission', ['redactionid' => $copie->id]);
        $check('restored: the submission', (bool) $sub);
        $reponses = $sub ? $DB->get_records('redaction_sujet_reponse', ['submissionid' => $sub->id], 'sujetid') : [];
        $check('restored: the 2 answers, on the restored subjects',
            count($reponses) === 2 && !array_diff(array_column($reponses, 'sujetid'), array_keys($nouveaux)));
        $check('restored: the answers keep their text and author',
            count($reponses) === 2 && array_column($reponses, 'contenu') !== [] && in_array('Reponse1', array_column($reponses, 'contenu'))
            && in_array('Reponse2', array_column($reponses, 'contenu')) && (int) reset($reponses)->userid === $a);
        $ev = $sub ? $DB->get_records('redaction_ai_evaluations', ['submissionid' => $sub->id]) : [];
        $check('restored: the evaluation and its 2 credited members',
            count($ev) === 1 && $DB->count_records('redaction_sujet_membre', ['evaluationid' => reset($ev)->id]) === 2);
        $check('restored: the language setting (on, 3 points)', (int) $copie->langue_enabled === 1 && (float) $copie->langue_points == 3.0);
        $check('restored: the sentences expected (3)', (int) $copie->sujets_phrases === 3);
        $check('restored: the vocabulary of each subject', array_column($nouveaux, 'lexique') == ['mot1', 'mot2']);
        $check('restored: the language assessment of the evaluation',
            count($ev) === 1 && reset($ev)->langue_json === $languejson && (float) reset($ev)->langue_points == 3.0);
    }
} finally {
    foreach ($courses as $courseid) {
        $c = $DB->get_record('course', ['id' => $courseid]);
        if ($c) {
            ob_start();
            delete_course($c, false);
            ob_end_clean();
        }
    }
    foreach ($users as $id) {
        delete_user($DB->get_record('user', ['id' => $id]));
        $DB->delete_records('user', ['id' => $id]);
    }
    remove_dir($CFG->tempdir . '/backup/' . $folder);
}

if ($rids) {
    [$insql, $inparams] = $DB->get_in_or_equal($rids);
    $reste = $DB->count_records_select('redaction', "id $insql", $inparams)
        + $DB->count_records_select('redaction_submission', "redactionid $insql", $inparams)
        + $DB->count_records_select('redaction_sujet', "redactionid $insql", $inparams);
    $copies = $DB->count_records_select('redaction', "name = 'verif_sauvegarde temporaire'");
    $check("nothing left behind (found {$reste}, copies {$copies})", $reste === 0 && $copies === 0);
}

echo $failures ? "\n{$failures} failure(s)\n" : "\nAll checks passed\n";
exit($failures ? 1 : 0);
