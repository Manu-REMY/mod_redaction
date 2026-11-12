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
 * CLI check of the subject attribution to each student of an individual activity (no PHPUnit needed).
 *
 * Usage: php verif_eleve.php
 * It creates a throw-away individual activity, subjects, groups and memberships of fake user ids,
 * draws the subjects, checks the balance, the stability and the grades, then removes everything it created.
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

// Worker mode: php verif_eleve.php worker <redactionid> <userid> <start-timestamp-float>.
if (($argv[1] ?? '') === 'worker') {
    $redaction = $DB->get_record('redaction', ['id' => (int) $argv[2]], '*', MUST_EXIST);
    while (microtime(true) < (float) $argv[4]) {
        usleep(200);
    }
    echo json_encode(array_keys(\mod_redaction\sujets::de_eleve($redaction, (int) $argv[3]))) . "\n";
    exit(0);
}

$failures = 0;
$check = function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$now = time();
$rid = 0;
$groupids = [];
// Fake user ids: the attribution only reads the group memberships.
$base = 990000000;
$eleves9z = range($base + 1, $base + 24);
$eleves9y = range($base + 101, $base + 105);
try {
    $rid = $DB->insert_record('redaction', (object) [
        'course' => SITEID, 'name' => 'verif_eleve temporaire', 'timecreated' => $now, 'timemodified' => $now,
        'sujets_enabled' => 1, 'sujets_par_groupe' => 1, 'group_submission' => 0, 'sujets_seuil' => 12,
        'langue_enabled' => 1, 'langue_points' => 2,
    ]);
    $sujetids = [];
    foreach (['Compression', 'Traction', 'Flexion'] as $i => $titre) {
        $sujetids[] = $DB->insert_record('redaction_sujet', (object) [
            'redactionid' => $rid, 'sortorder' => $i + 1, 'titre' => $titre, 'contenu' => 'c', 'contenuformat' => 1,
            'corrige' => 'x', 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }
    // Class 9Z: a class group and work groups; class 9Y: a class group only, plus a group that is not a class.
    foreach (['9Z', '9Z-GRP01', '9Z-GRP02', '9Y', 'TIERS TEMPS'] as $nom) {
        $groupids[$nom] = $DB->insert_record('groups', (object) [
            'courseid' => SITEID, 'name' => $nom, 'timecreated' => $now, 'timemodified' => $now,
            'description' => '', 'descriptionformat' => 1,
        ]);
    }
    foreach ($eleves9z as $k => $uid) {
        $DB->insert_record('groups_members', (object) ['groupid' => $groupids['9Z'], 'userid' => $uid, 'timeadded' => $now]);
        $DB->insert_record('groups_members', (object) [
            'groupid' => $groupids[$k % 2 ? '9Z-GRP01' : '9Z-GRP02'], 'userid' => $uid, 'timeadded' => $now,
        ]);
    }
    foreach ($eleves9y as $uid) {
        $DB->insert_record('groups_members', (object) ['groupid' => $groupids['9Y'], 'userid' => $uid, 'timeadded' => $now]);
        $DB->insert_record('groups_members', (object) ['groupid' => $groupids['TIERS TEMPS'], 'userid' => $uid, 'timeadded' => $now]);
    }

    $redaction = $DB->get_record('redaction', ['id' => $rid], '*', MUST_EXIST);
    $check('the activity is individual', \mod_redaction\sujets::individuel($redaction));

    $classes = \mod_redaction\sujets::classes_des_eleves(SITEID);
    $check('class of a 9Z student is 9Z', ($classes[$eleves9z[0]] ?? '') === '9Z');
    $check('class of a 9Y student is 9Y (TIERS TEMPS ignored)', ($classes[$eleves9y[0]] ?? '') === '9Y');

    // Two processes on the same student, released at the same instant.
    $start = microtime(true) + 3;
    $procs = [];
    $pipes = [];
    foreach ([0, 1] as $k) {
        $cmd = [PHP_BINARY, __FILE__, 'worker', $rid, $eleves9z[0], sprintf('%.4F', $start)];
        $procs[$k] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
    }
    $out = [];
    foreach ($procs as $k => $p) {
        $out[$k] = trim(stream_get_contents($pipes[$k][1])) . trim(stream_get_contents($pipes[$k][2]));
        proc_close($p);
    }
    $rows = $DB->count_records('redaction_sujet_eleve', ['redactionid' => $rid, 'userid' => $eleves9z[0]]);
    $check("two concurrent calls leave 1 row (found {$rows})", $rows === 1);
    $check('both processes returned the same subject', $out[0] === $out[1] && $out[0] !== '');
    $again = json_encode(array_keys(\mod_redaction\sujets::de_eleve($redaction, $eleves9z[0])));
    $check('a later call gives the same subject', $again === $out[0]);

    // The whole class: 24 students, 3 subjects, 8 each.
    foreach ($eleves9z as $uid) {
        $sujets = \mod_redaction\sujets::de_eleve($redaction, $uid);
        if (count($sujets) !== 1) {
            $check("student {$uid} gets exactly one subject", false);
        }
    }
    $compte = $DB->get_records_sql_menu(
        'SELECT sujetid, COUNT(1) FROM {redaction_sujet_eleve} WHERE redactionid = ? AND userid >= ? AND userid <= ? GROUP BY sujetid',
        [$rid, $eleves9z[0], end($eleves9z)]
    );
    $check('24 students of 9Z: 8 per subject (' . json_encode($compte) . ')', count($compte) === 3 && min($compte) == 8 && max($compte) == 8);

    // Another class is shared out on its own: 5 students, 2 / 2 / 1.
    foreach ($eleves9y as $uid) {
        \mod_redaction\sujets::de_eleve($redaction, $uid);
    }
    $compte = $DB->get_records_sql_menu(
        'SELECT sujetid, COUNT(1) FROM {redaction_sujet_eleve} WHERE redactionid = ? AND userid >= ? AND userid <= ? GROUP BY sujetid',
        [$rid, $eleves9y[0], end($eleves9y)]
    );
    $check('5 students of 9Y: 2 / 2 / 1 (' . json_encode($compte) . ')', array_sum($compte) == 5 && max($compte) - min($compte) <= 1 && count($compte) === 3);

    // The submission of a student: subjects of the student, answered or not.
    $subid = $DB->insert_record('redaction_submission', (object) [
        'redactionid' => $rid, 'groupid' => 0, 'userid' => $eleves9z[0], 'status' => 0, 'contenuformat' => 1,
        'feedbackformat' => 1, 'timecreated' => $now, 'timemodified' => $now,
    ]);
    $submission = $DB->get_record('redaction_submission', ['id' => $subid], '*', MUST_EXIST);
    $check('de_soumission gives the subject of the student',
        json_encode(array_keys(\mod_redaction\sujets::de_soumission($redaction, $submission))) === $out[0]);
    $check('no answer yet: not answered', !redaction_sujets_answered($redaction, $submission));
    $sujetid = (int) json_decode($out[0], true)[0];
    $DB->insert_record('redaction_sujet_reponse', (object) [
        'submissionid' => $subid, 'sujetid' => $sujetid, 'contenu' => 'La poutre travaille en compression.', 'contenuformat' => 1,
        'userid' => $eleves9z[0], 'timemodified' => $now,
    ]);
    $check('answer written: answered', redaction_sujets_answered($redaction, $submission));

    // The grade goes to the student alone.
    $evalid = $DB->insert_record('redaction_ai_evaluations', (object) [
        'redactionid' => $rid, 'submissionid' => $subid, 'groupid' => 0, 'userid' => $eleves9z[0], 'provider' => 'albert',
        'model' => 'x', 'status' => 'applied', 'parsed_grade' => 13.5, 'langue_points' => 2, 'timecreated' => $now, 'timemodified' => $now,
    ]);
    $DB->insert_record('redaction_sujet_membre', (object) ['evaluationid' => $evalid, 'userid' => $eleves9z[0]]);
    $notes = redaction_get_user_grades_sujets($redaction);
    $check('the student gets 13.5', isset($notes[$eleves9z[0]]) && (float) $notes[$eleves9z[0]]->rawgrade === 13.5);
    $check('nobody else gets a grade', count($notes) === 1);
} finally {
    if ($rid) {
        $DB->delete_records_select('redaction_sujet_membre',
            'evaluationid IN (SELECT id FROM {redaction_ai_evaluations} WHERE redactionid = ?)', [$rid]);
        $DB->delete_records('redaction_ai_evaluations', ['redactionid' => $rid]);
        $DB->delete_records_select('redaction_sujet_reponse',
            'submissionid IN (SELECT id FROM {redaction_submission} WHERE redactionid = ?)', [$rid]);
        $DB->delete_records('redaction_submission', ['redactionid' => $rid]);
        $DB->delete_records('redaction_sujet_eleve', ['redactionid' => $rid]);
        $DB->delete_records('redaction_sujet', ['redactionid' => $rid]);
        $DB->delete_records('redaction', ['id' => $rid]);
    }
    if ($groupids) {
        [$insql, $params] = $DB->get_in_or_equal(array_values($groupids));
        $DB->delete_records_select('groups_members', "groupid $insql", $params);
        $DB->delete_records_list('groups', 'id', array_values($groupids));
    }
}

echo $failures ? "\n{$failures} failure(s)\n" : "\nAll checks passed\n";
exit($failures ? 1 : 0);
