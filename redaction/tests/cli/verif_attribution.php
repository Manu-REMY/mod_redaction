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
 * CLI check of the subject attribution under real concurrency (no PHPUnit needed).
 *
 * Usage: php verif_attribution.php
 * It creates a throw-away activity, subjects and groups, launches two processes on the same group,
 * counts the rows, then removes everything it created.
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

// Worker mode: php verif_attribution.php worker <redactionid> <groupid> <start-timestamp-float>.
if (($argv[1] ?? '') === 'worker') {
    $redaction = $DB->get_record('redaction', ['id' => (int) $argv[2]], '*', MUST_EXIST);
    while (microtime(true) < (float) $argv[4]) {
        usleep(200);
    }
    $ids = array_keys(\mod_redaction\sujets::du_groupe($redaction, (int) $argv[3]));
    echo json_encode($ids) . "\n";
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
try {
    $rid = $DB->insert_record('redaction', (object) [
        'course' => SITEID, 'name' => 'verif_attribution temporaire', 'timecreated' => $now, 'timemodified' => $now,
        'sujets_enabled' => 1, 'sujets_par_groupe' => 2,
    ]);
    for ($i = 1; $i <= 8; $i++) {
        $DB->insert_record('redaction_sujet', (object) [
            'redactionid' => $rid, 'sortorder' => $i, 'titre' => 'S' . $i, 'contenu' => 'c', 'contenuformat' => 1,
            'corrige' => 'x', 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }
    foreach (['9Z-GRP01', '9Z-GRP02', '9Y-GRP01'] as $nom) {
        $groupids[$nom] = $DB->insert_record('groups', (object) [
            'courseid' => SITEID, 'name' => $nom, 'timecreated' => $now, 'timemodified' => $now,
            'description' => '', 'descriptionformat' => 1,
        ]);
    }
    // Two processes on the same group, released at the same instant.
    $start = microtime(true) + 3;
    $procs = [];
    foreach ([0, 1] as $k) {
        $cmd = [PHP_BINARY, __FILE__, 'worker', $rid, $groupids['9Z-GRP01'], sprintf('%.4F', $start)];
        $procs[$k] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
    }
    $out = [];
    foreach ($procs as $k => $p) {
        $out[$k] = trim(stream_get_contents($pipes[$k][1])) . trim(stream_get_contents($pipes[$k][2]));
        proc_close($p);
    }
    echo 'worker 0: ' . $out[0] . "\nworker 1: " . $out[1] . "\n";

    $rows = $DB->count_records('redaction_sujet_groupe', ['redactionid' => $rid, 'groupid' => $groupids['9Z-GRP01']]);
    $check("two concurrent calls leave 2 rows (found {$rows})", $rows === 2);
    $check('both processes returned the same subjects', $out[0] === $out[1] && $out[0] !== '');

    // Stable: a later call returns the same subjects.
    $redaction = $DB->get_record('redaction', ['id' => $rid], '*', MUST_EXIST);
    $again = json_encode(array_keys(\mod_redaction\sujets::du_groupe($redaction, $groupids['9Z-GRP01'])));
    $check('a later call gives the same subjects', $again === $out[0]);

    // Same class: another pair; other class: independent.
    $second = array_keys(\mod_redaction\sujets::du_groupe($redaction, $groupids['9Z-GRP02']));
    $first = json_decode($out[0], true);
    $check('second group has no subject in common with the first (8 subjects available)', !array_intersect($second, $first));
    $other = \mod_redaction\sujets::du_groupe($redaction, $groupids['9Y-GRP01']);
    $check('group of another class gets 2 subjects', count($other) === 2);

    // No subject: exception, nothing written.
    $DB->delete_records('redaction_sujet_groupe', ['redactionid' => $rid]);
    $DB->delete_records('redaction_sujet', ['redactionid' => $rid]);
    $threw = false;
    try {
        \mod_redaction\sujets::du_groupe($redaction, $groupids['9Z-GRP01']);
    } catch (moodle_exception $e) {
        $threw = true;
    }
    $check('no subject: exception and no row', $threw && !$DB->count_records('redaction_sujet_groupe', ['redactionid' => $rid]));
} finally {
    if ($rid) {
        $DB->delete_records('redaction_sujet_groupe', ['redactionid' => $rid]);
        $DB->delete_records('redaction_sujet', ['redactionid' => $rid]);
        $DB->delete_records('redaction', ['id' => $rid]);
    }
    if ($groupids) {
        $DB->delete_records_list('groups', 'id', array_values($groupids));
    }
}

echo $failures ? "\n{$failures} failure(s)\n" : "\nAll checks passed\n";
exit($failures ? 1 : 0);
