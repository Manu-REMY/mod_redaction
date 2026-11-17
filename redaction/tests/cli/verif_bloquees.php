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
 * CLI check of the release of the corrections left running by a process the server killed (17/11/2026).
 *
 * Usage: php verif_bloquees.php
 * It writes throw-away activity, copy, evaluation and task rows, then removes them. No AI call happens.
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

use mod_redaction\ai_evaluator;

$failures = 0;
$check = function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$maintenant = time();
$activites = [];
$copies = [];

/** Throw-away activity row. */
$activite = function (bool $sujets) use ($DB, &$activites): int {
    $id = $DB->insert_record('redaction', (object) ['name' => 'verif_bloquees', 'sujets_enabled' => $sujets ? 1 : 0]);
    $activites[] = $id;
    return $id;
};

/**
 * Copy with one evaluation in the given state, and its adhoc task when $tache is given.
 *
 * @return array [submissionid, evaluationid]
 */
$cas = function (int $redactionid, string $statut, int $age, ?array $tache, int $finale = 0)
        use ($DB, $maintenant, &$copies): array {
    $submissionid = $DB->insert_record('redaction_submission', (object) [
        'redactionid' => $redactionid, 'training_count' => 2, 'status' => $finale ? 1 : 0,
        // One copy per (activity, group, user): a user id no account has.
        'userid' => 2000000000 + count($copies), 'groupid' => 0,
    ]);
    $copies[] = $submissionid;
    $evaluationid = $DB->insert_record('redaction_ai_evaluations', (object) [
        'redactionid' => $redactionid, 'submissionid' => $submissionid, 'provider' => 'aucun', 'model' => 'aucun',
        'status' => $statut, 'finale' => $finale, 'timecreated' => $maintenant - $age, 'timemodified' => $maintenant - $age,
    ]);
    if ($tache !== null) {
        $DB->insert_record('task_adhoc', (object) [
            'component' => 'mod_redaction', 'classname' => '\\mod_redaction\\task\\evaluate_submission',
            'nextruntime' => $maintenant - $age, 'faildelay' => 0, 'blocking' => 0,
            'customdata' => json_encode(['evaluationid' => $evaluationid]),
            'timecreated' => $maintenant - $age,
            'timestarted' => $tache['demarre'] ? $maintenant - $age : null,
            'pid' => $tache['pid'] ?? null, 'hostname' => $tache['demarre'] ? gethostname() : null,
        ]);
    }
    return [$submissionid, $evaluationid];
};

$tacherestante = fn(int $evaluationid) => $DB->record_exists_select('task_adhoc', 'classname = ? AND '
    . $DB->sql_compare_text('customdata') . ' = ?', ['\\mod_redaction\\task\\evaluate_submission',
    json_encode(['evaluationid' => $evaluationid])]);

// A pid no process has: the highest possible, beyond pid_max.
$pidmort = 2147483646;

try {
    $sujets = $activite(true);
    $classique = $activite(false);

    [$s1, $e1] = $cas($sujets, 'processing', 60, ['demarre' => true, 'pid' => $pidmort]);
    [$s2, $e2] = $cas($sujets, 'processing', 60, ['demarre' => true, 'pid' => getmypid()]);
    [$s3, $e3] = $cas($sujets, 'processing', ai_evaluator::CORRECTION_DUREE_MAX + 60, ['demarre' => true, 'pid' => getmypid()]);
    [$s4, $e4] = $cas($sujets, 'pending', 3 * HOURSECS, ['demarre' => false]);
    [$s5, $e5] = $cas($sujets, 'processing', 30, null);
    [$s6, $e6] = $cas($sujets, 'processing', ai_evaluator::CORRECTION_SANS_TACHE + 60, null);
    [$s7, $e7] = $cas($sujets, 'processing', 60, ['demarre' => true, 'pid' => $pidmort], 1);
    [$s8, $e8] = $cas($classique, 'processing', 60, ['demarre' => true, 'pid' => $pidmort]);
    [$s9, $e9] = $cas($sujets, 'applied', 60, null);

    $liberees = ai_evaluator::liberer_bloquees($maintenant);

    $statut = fn(int $id) => $DB->get_field('redaction_ai_evaluations', 'status', ['id' => $id]);
    $essais = fn(int $id) => (int) $DB->get_field('redaction_submission', 'training_count', ['id' => $id]);
    $message = get_string('sujets_correction_interrompue', 'mod_redaction');

    $check('process killed: failed, attempt given back, its task removed', $statut($e1) === 'failed' && $essais($s1) === 1
        && !$tacherestante($e1));
    $check('process killed: the student is told the correction was interrupted',
        $DB->get_field('redaction_ai_evaluations', 'error_message', ['id' => $e1]) === $message);
    $check('process alive and recent: left running', $statut($e2) === 'processing' && $essais($s2) === 2 && $tacherestante($e2));
    $check('process alive but past the maximum: failed, attempt given back', $statut($e3) === 'failed' && $essais($s3) === 1
        && !$tacherestante($e3));
    $check('waiting in the queue, however old: left alone', $statut($e4) === 'pending' && $essais($s4) === 2 && $tacherestante($e4));
    $check('running without task, just queued: left alone', $statut($e5) === 'processing' && $essais($s5) === 2);
    $check('running without task for minutes: failed, attempt given back', $statut($e6) === 'failed' && $essais($s6) === 1);
    $check('final correction killed: failed (retried by relancer_finales), no attempt given back',
        $statut($e7) === 'failed' && $essais($s7) === 2);
    $check('classic mode: left alone', $statut($e8) === 'processing' && $essais($s8) === 2);
    $check('settled correction: left alone', $statut($e9) === 'applied');
    $check('count of the corrections released', $liberees === 4);
    $check('a second run releases nothing more, never gives an attempt back twice',
        ai_evaluator::liberer_bloquees($maintenant) === 0 && $essais($s1) === 1);

    $check('scheduled task declared, every minute', class_exists('\\mod_redaction\\task\\liberer_corrections')
        && $DB->record_exists('task_scheduled', ['classname' => '\\mod_redaction\\task\\liberer_corrections', 'minute' => '*']));
} catch (\Throwable $e) {
    echo 'EXC   ' . $e->getMessage() . ' ' . ($e->debuginfo ?? '') . "\n";
    $failures++;
} finally {
    foreach ($copies as $id) {
        foreach ($DB->get_fieldset_select('redaction_ai_evaluations', 'id', 'submissionid = ?', [$id]) as $evaluationid) {
            $DB->delete_records_select('task_adhoc', 'classname = ? AND ' . $DB->sql_compare_text('customdata') . ' = ?',
                ['\\mod_redaction\\task\\evaluate_submission', json_encode(['evaluationid' => (int) $evaluationid])]);
        }
        $DB->delete_records('redaction_ai_evaluations', ['submissionid' => $id]);
        $DB->delete_records('redaction_submission', ['id' => $id]);
    }
    foreach ($activites as $id) {
        $DB->delete_records('redaction', ['id' => $id]);
    }
}

echo $failures === 0 ? "\nOK\n" : "\n$failures FAIL\n";
exit($failures === 0 ? 0 : 1);
