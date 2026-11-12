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
 * CLI check of the training attempts and of the single final submission, request 16 (no PHPUnit needed).
 *
 * Usage: php verif_finale.php
 * It creates a throw-away course, activities, groups and users, then removes them. The provider is
 * simulated and the activities use a provider name no real API answers to: no AI call can happen.
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
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/enrollib.php');

use mod_redaction\ai_evaluator;
use mod_redaction\ai_provider\provider_interface;

// Worker mode: php verif_finale.php worker-finale <cmid> <userid> <start>: submits the final version at the given instant.
if (($argv[1] ?? '') === 'worker-finale') {
    \core\session\manager::set_user($DB->get_record('user', ['id' => (int) $argv[3]], '*', MUST_EXIST));
    while (microtime(true) < (float) $argv[4]) {
        usleep(200);
    }
    try {
        $r = \mod_redaction\external\submit_final::execute((int) $argv[2]);
        echo json_encode($r) . "\n";
    } catch (\Throwable $e) {
        echo 'EXC ' . $e->getMessage() . "\n";
    }
    exit(0);
}

/** Provider that plays back prepared answers and keeps every prompt. */
class fournisseur_simule implements provider_interface {
    /** @var array Answers to play back. */
    public array $reponses = [];
    /** @var array Prompts received. */
    public array $appels = [];

    public function get_name(): string {
        return 'simule';
    }

    public function get_models(): array {
        return ['simule'];
    }

    public function get_default_model(): string {
        return 'simule';
    }

    public function evaluate(string $systemprompt, string $userprompt, string $model, int $maxtokens): array {
        $this->appels[] = ['system' => $systemprompt, 'user' => $userprompt];
        if (!$this->reponses) {
            throw new \RuntimeException('Simulated provider has no answer left');
        }
        $reponse = array_shift($this->reponses);
        if ($reponse instanceof \Closure) {
            $reponse = $reponse();
        }
        if ($reponse instanceof \Throwable) {
            throw $reponse;
        }
        return ['content' => $reponse, 'prompt_tokens' => 10, 'completion_tokens' => 5];
    }

    public function estimate_tokens(string $text): int {
        return 1;
    }

    public function test_connection(): array {
        return ['success' => true, 'message' => ''];
    }
}

$failures = 0;
$check = function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

/** JSON answer of the simulated AI for one subject. */
function ia(float $note, string $commentaire = 'Tu as vu juste.'): string {
    return json_encode(['attendus' => ['E1', 'E2'], 'note' => $note, 'commentaire' => $commentaire]);
}

/** JSON answer of the simulated AI about the language. */
function langue_ia(array $paliers, array $fautes = [], array $remarques = []): string {
    [$o, $s, $l, $g] = $paliers;
    return json_encode(['orthographe' => ['palier' => $o, 'fautes' => $fautes], 'syntaxe' => ['palier' => $s, 'remarques' => $remarques],
        'lexique' => ['palier' => $l], 'organisation' => ['palier' => $g]], JSON_UNESCAPED_UNICODE);
}

/** Grade in the gradebook, null when there is none. */
function carnet(stdClass $redaction, int $userid): ?float {
    $info = grade_get_grades($redaction->course, 'mod', 'redaction', $redaction->id, $userid);
    $grade = $info->items[0]->grades[$userid]->grade ?? null;
    return $grade === null ? null : (float) $grade;
}

/** Writes the answers of a submission, replacing the earlier ones. */
function repondre(stdClass $submission, array $sujets, string $texte, int $format = FORMAT_HTML): void {
    global $DB;
    foreach ($sujets as $sujet) {
        $DB->delete_records('redaction_sujet_reponse', ['submissionid' => $submission->id, 'sujetid' => $sujet->id]);
        $DB->insert_record('redaction_sujet_reponse', (object) [
            'submissionid' => $submission->id, 'sujetid' => $sujet->id, 'contenu' => $texte, 'contenuformat' => $format,
            'userid' => 0, 'timemodified' => time(),
        ]);
    }
}

/** Removes the adhoc task of an evaluation, so nothing else can pick it up. Returns whether it was queued. */
function retirer_tache(int $evaluationid): bool {
    global $DB;
    $trouve = false;
    foreach ($DB->get_records('task_adhoc', ['classname' => '\mod_redaction\task\evaluate_submission']) as $tache) {
        if ((int) (json_decode($tache->customdata)->evaluationid ?? 0) === $evaluationid) {
            $DB->delete_records('task_adhoc', ['id' => $tache->id]);
            $trouve = true;
        }
    }
    return $trouve;
}

/** Spends an attempt as the student screen does, queues the evaluation and processes it. */
function corriger(stdClass $redaction, stdClass $submission, int $userid): int {
    global $DB;
    $DB->execute('UPDATE {redaction_submission} SET training_count = training_count + 1 WHERE id = ?', [$submission->id]);
    $id = ai_evaluator::queue_evaluation($redaction->id, $submission->id, (int) $submission->groupid, $userid, true);
    retirer_tache($id);
    ai_evaluator::process_evaluation($id);
    return $id;
}

\core\session\manager::set_user(get_admin());

$now = time();
$sim = new fournisseur_simule();
ai_evaluator::$fournisseurtest = $sim;
$course = null;
$users = [];
$studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
$moduleid = (int) $DB->get_field('modules', 'id', ['name' => 'redaction'], MUST_EXIST);
$rids = [];

/** JSON answer of the simulated AI in three parts. */
function ia3(float $note, array $attendus = ['E1', 'E2']): string {
    return json_encode(['attendus' => $attendus, 'erreurs' => [], 'note' => $note, 'juste' => 'Tu décris ta section.',
        'a_preciser' => 'Dis ce qui se passe.', 'piste' => 'Que se passe-t-il ?'], JSON_UNESCAPED_UNICODE);
}

/** Queues and processes an attempt or the final correction of a submission. */
function passer(stdClass $redaction, stdClass $submission, int $userid, bool $finale): int {
    global $DB;
    if (!$finale) {
        $DB->execute('UPDATE {redaction_submission} SET training_count = training_count + 1 WHERE id = ?', [$submission->id]);
    } else {
        // As the service does: the copy is locked when its final version is handed in.
        $DB->update_record('redaction_submission', (object) ['id' => $submission->id, 'status' => 1, 'timesubmitted' => time()]);
    }
    $id = ai_evaluator::queue_evaluation($redaction->id, $submission->id, (int) $submission->groupid, $userid, true, $finale);
    retirer_tache($id);
    ai_evaluator::process_evaluation($id);
    return $id;
}

try {
    $course = create_course((object) [
        'fullname' => 'verif_finale temporaire', 'shortname' => 'verif_finale_' . $now,
        'category' => core_course_category::get_default()->id,
    ]);
    $creeractivite = function (array $options) use ($course, $moduleid, &$rids, $DB): stdClass {
        $id = redaction_add_instance((object) ($options + [
            'course' => $course->id, 'name' => 'verif_finale temporaire', 'intro' => '', 'introformat' => FORMAT_HTML,
            'ai_enabled' => 1, 'ai_provider' => 'simule', 'langue_enabled' => 1, 'langue_points' => 2,
        ]));
        $rids[] = $id;
        $cmid = add_course_module((object) ['course' => $course->id, 'module' => $moduleid, 'instance' => $id, 'section' => 0]);
        course_add_cm_to_section($course->id, $cmid, 0);
        return $DB->get_record('redaction', ['id' => $id], '*', MUST_EXIST);
    };
    foreach (['A', 'B'] as $lettre) {
        $id = user_create_user((object) [
            'username' => 'veriffin' . strtolower($lettre) . $now, 'firstname' => 'Prenom' . $lettre, 'lastname' => 'Nom' . $lettre,
            'email' => "veriffin{$lettre}{$now}@example.invalid", 'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
        ], false, false);
        $users[$lettre] = $id;
        enrol_try_internal_enrol($course->id, $id, $studentrole);
    }
    [$a, $b] = array_values($users);
    $g1 = groups_create_group((object) ['courseid' => $course->id, 'name' => '9Z-GRP01']);
    groups_add_member($g1, $a);
    groups_add_member($g1, $b);

    // Individual activity, two subjects each (the beam is one, two exercises the empty subject).
    $redaction = $creeractivite(['training_enabled' => 1, 'ai_auto_apply' => 1, 'group_submission' => 0, 'training_max_attempts' => 5,
        'sujets_enabled' => 1, 'sujets_par_groupe' => 2, 'sujets_seuil' => 12]);
    for ($i = 1; $i <= 2; $i++) {
        $DB->insert_record('redaction_sujet', (object) [
            'redactionid' => $redaction->id, 'sortorder' => $i, 'titre' => 'Effort' . $i, 'contenu' => 'Explique ta poutre ' . $i,
            'contenuformat' => FORMAT_HTML, 'corrige' => 'E1 : la semelle. E2 : la hauteur.', 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }
    $submission = redaction_get_or_create_submission($redaction, 0, $a);
    $sujets = \mod_redaction\sujets::de_soumission($redaction, $submission);
    repondre($submission, $sujets, 'Ma poutre a une section en I, elle est haute.', FORMAT_PLAIN);

    // 1. An attempt: graded, fingerprinted, never in the gradebook.
    $sim->reponses = [ia3(15), ia3(15), langue_ia(['S', 'S', 'S', 'S'])];
    $essai = passer($redaction, $submission, $a, false);
    $ev = $DB->get_record('redaction_ai_evaluations', ['id' => $essai]);
    $check("attempt: graded ({$ev->status}, {$ev->parsed_grade})", in_array($ev->status, ['completed', 'applied'], true) && $ev->parsed_grade !== null);
    $check('attempt: finale = 0 and a fingerprint', (int) $ev->finale === 0 && strlen((string) $ev->empreinte) === 40);
    $check('attempt: nothing in the gradebook', carnet($redaction, $a) === null);
    $details = json_decode($ev->sujets_json, true);
    $check('attempt: feedback in three parts per subject', isset($details[0]['juste'], $details[0]['a_preciser'], $details[0]['piste']));

    // 2. Final submission on unchanged answers: the attempt is taken over, no AI call.
    $sim->appels = [];
    $finale = passer($redaction, $submission, $a, true);
    $fv = $DB->get_record('redaction_ai_evaluations', ['id' => $finale]);
    $check('final, unchanged text: no AI call (' . count($sim->appels) . ')', count($sim->appels) === 0);
    $check('final, unchanged text: grade of the attempt', (float) $fv->parsed_grade === (float) $ev->parsed_grade && (int) $fv->finale === 1);
    $check('final: the grade goes to the gradebook', carnet($redaction, $a) === (float) $ev->parsed_grade);
    // The student screen after the final version: grade, read-only answers, no button.
    \core\session\manager::set_user($DB->get_record('user', ['id' => $a]));
    $cmf = get_coursemodule_from_instance('redaction', $redaction->id, $course->id, false, MUST_EXIST);
    $ctxf = context_module::instance($cmf->id);
    $PAGE->set_context($ctxf);
    $PAGE->set_url('/');
    $outf = $PAGE->get_renderer('core');
    $submission = $DB->get_record('redaction_submission', ['id' => $submission->id]);
    $df = (new \mod_redaction\output\sujets_eleve($redaction, $cmf, $ctxf, $submission, 0))->export_for_template($outf);
    $hf = $outf->render_from_template('mod_redaction/sujets_eleve', $df);
    \core\session\manager::set_user(get_admin());
    $check('student screen after the final: graded, validated, handed in, answers read-only, no button',
        $df['hasnote'] && $df['valide'] && $df['rendue'] && !$df['canessai'] && !$df['canfinale'] && str_contains($hf, 'readonly')
        && !str_contains($hf, 'mod_redaction-se-final') && !str_contains($hf, '[['));
    $check('student screen: what is right, with its word (above the threshold, nothing else)',
        str_contains($hf, get_string('sujets_se_juste', 'mod_redaction')) && !str_contains($hf, get_string('sujets_se_piste', 'mod_redaction')));

    // 3. A changed answer: a new correction.
    $sub2 = redaction_get_or_create_submission($redaction, 0, $b);
    $sujets2 = \mod_redaction\sujets::de_soumission($redaction, $sub2);
    repondre($sub2, $sujets2, 'Premier texte de ma poutre.', FORMAT_PLAIN);
    $sim->reponses = [ia3(10), ia3(10), langue_ia(['S', 'S', 'S', 'S'])];
    passer($redaction, $sub2, $b, false);
    repondre($sub2, $sujets2, 'Premier texte de ma poutre!', FORMAT_PLAIN);
    $sim->appels = [];
    $sim->reponses = [ia3(16), ia3(16), langue_ia(['TS', 'TS', 'TS', 'TS'])];
    $f2 = passer($redaction, $sub2, $b, true);
    $check('final, one character changed: a new correction (' . count($sim->appels) . ' calls)', count($sim->appels) === 3);
    $check('final, changed text: its own grade', (float) $DB->get_field('redaction_ai_evaluations', 'parsed_grade', ['id' => $f2]) === 16.4);

    // 4. Teacher grade wins; a change of language setting after the final keeps the grade.
    \mod_redaction\sujets::noter($redaction, $DB->get_record('redaction_submission', ['id' => $sub2->id]), 9.0);
    $check('teacher grade wins over the final', carnet($redaction, $b) === 9.0);
    \mod_redaction\sujets::noter($redaction, $DB->get_record('redaction_submission', ['id' => $sub2->id]), null);
    $check('teacher grade removed: the final again', carnet($redaction, $b) === 16.4);
    $DB->set_field('redaction', 'langue_points', 3, ['id' => $redaction->id]);
    $redaction = $DB->get_record('redaction', ['id' => $redaction->id]);
    redaction_update_grades($redaction);
    $check('language setting changed after the final: grade kept', carnet($redaction, $b) === 16.4);
    $DB->set_field('redaction', 'langue_points', 2, ['id' => $redaction->id]);
    $redaction = $DB->get_record('redaction', ['id' => $redaction->id]);

    // 5. Final with an empty subject: no call for it, 0 for its part.
    $r3 = $creeractivite(['training_enabled' => 1, 'ai_auto_apply' => 1, 'group_submission' => 1, 'training_max_attempts' => 5,
        'sujets_enabled' => 1, 'sujets_par_groupe' => 2, 'sujets_seuil' => 12]);
    for ($i = 1; $i <= 2; $i++) {
        $DB->insert_record('redaction_sujet', (object) [
            'redactionid' => $r3->id, 'sortorder' => $i, 'titre' => 'Systeme' . $i, 'contenu' => 'Enonce' . $i,
            'contenuformat' => FORMAT_HTML, 'corrige' => 'E1 : a. E2 : b.', 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }
    $s3 = redaction_get_or_create_submission($r3, $g1, $a);
    $sujets3 = array_values(\mod_redaction\sujets::de_soumission($r3, $s3));
    repondre($s3, [$sujets3[0]], 'Une vraie réponse ici.', FORMAT_PLAIN);
    $sim->appels = [];
    $sim->reponses = [ia3(18), langue_ia(['S', 'S', 'S', 'S'])];
    $f3 = passer($r3, $s3, $a, true);
    $e3 = $DB->get_record('redaction_ai_evaluations', ['id' => $f3]);
    $d3 = json_decode((string) $e3->sujets_json, true);
    $check("final with an empty subject: graded ({$e3->status}), 2 calls (" . count($sim->appels) . ')',
        in_array($e3->status, ['completed', 'applied'], true) && count($sim->appels) === 2);
    $check('final with an empty subject: 0 for that subject', count($d3) === 2 && (float) $d3[1]['note'] === 0.0);
    $check('group final: the grade goes to both members', carnet($r3, $a) !== null && carnet($r3, $a) === carnet($r3, $b));
    $DB->set_field('redaction_submission', 'status', 0, ['id' => $s3->id]);
    $check('an attempt with an empty subject is still refused',
        redaction_can_submit_attempt($r3, $DB->get_record('redaction_submission', ['id' => $s3->id]))['reason'] === 'empty_answer');

    // 6. Request 16, the services: final submission locks, once; attempts and final refused after it or after the date.
    $r6 = $creeractivite(['training_enabled' => 1, 'ai_auto_apply' => 1, 'group_submission' => 1, 'training_max_attempts' => 2,
        'sujets_enabled' => 1, 'sujets_par_groupe' => 1, 'sujets_seuil' => 12]);
    $DB->insert_record('redaction_sujet', (object) [
        'redactionid' => $r6->id, 'sortorder' => 1, 'titre' => 'Seul', 'contenu' => 'Enonce', 'contenuformat' => FORMAT_HTML,
        'corrige' => 'E1 : a. E2 : b.', 'timecreated' => $now, 'timemodified' => $now,
    ]);
    $cm6 = get_coursemodule_from_instance('redaction', $r6->id, $course->id, false, MUST_EXIST);
    $s6 = redaction_get_or_create_submission($r6, $g1, $a);
    $su6 = \mod_redaction\sujets::de_soumission($r6, $s6);
    \core\session\manager::set_user($DB->get_record('user', ['id' => $a]));
    $vide = \mod_redaction\external\submit_final::execute($cm6->id);
    $check('final with an empty subject: refused', !$vide['success'] && $vide['message'] === get_string('training_error_empty_answer', 'mod_redaction'));
    repondre($s6, $su6, 'Ma réponse complète.', FORMAT_PLAIN);
    $DB->set_field('redaction_submission', 'training_count', 2, ['id' => $s6->id]);
    $essai = \mod_redaction\external\submit_sujets::execute($cm6->id);
    $check('no attempt left: an attempt is refused', !$essai['success']);
    // Two processes at the same instant: a single final.
    $start = microtime(true) + 3;
    $procs = [];
    $pipes = [];
    foreach ([$a, $b] as $k => $uid) {
        $procs[$k] = proc_open([PHP_BINARY, __FILE__, 'worker-finale', (string) $cm6->id, (string) $uid, sprintf('%.4F', $start)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
    }
    $sorties = [];
    foreach ($procs as $k => $proc) {
        $sorties[$k] = trim(stream_get_contents($pipes[$k][1]) . stream_get_contents($pipes[$k][2]));
        proc_close($proc);
    }
    $ok = array_filter($sorties, fn($o) => str_contains($o, '"success":true'));
    $finales = $DB->count_records('redaction_ai_evaluations', ['submissionid' => $s6->id, 'finale' => 1]);
    $check('no attempt left, two members at once: one final only (' . $finales . ', ' . implode(' | ', $sorties) . ')',
        count($ok) === 1 && $finales === 1);
    $sub6 = $DB->get_record('redaction_submission', ['id' => $s6->id]);
    $check('final: the copy is locked', (int) $sub6->status === 1 && (int) $sub6->timesubmitted > 0);
    $check('final: no attempt spent', (int) $sub6->training_count === 2);
    foreach ($DB->get_records('redaction_ai_evaluations', ['submissionid' => $s6->id]) as $ev6) {
        retirer_tache((int) $ev6->id);
    }
    $DB->set_field('redaction_ai_evaluations', 'status', 'failed', ['submissionid' => $s6->id]);
    $sujet6 = reset($su6);
    $ecrit = \mod_redaction\external\save_sujet_reponse::execute($cm6->id, (int) $sujet6->id, 'Texte changé après la finale');
    $check('after the final: the answers can no longer be changed', !$ecrit['success']
        && $DB->get_field('redaction_sujet_reponse', 'contenu', ['submissionid' => $s6->id, 'sujetid' => $sujet6->id]) === 'Ma réponse complète.');
    $encore = \mod_redaction\external\submit_final::execute($cm6->id);
    $check('a second final is refused', !$encore['success']
        && $encore['message'] === get_string('training_error_already_submitted', 'mod_redaction'));
    $DB->set_field('redaction_submission', 'training_count', 0, ['id' => $s6->id]);
    $apres = \mod_redaction\external\submit_sujets::execute($cm6->id);
    $check('an attempt after the final is refused', !$apres['success']
        && $apres['message'] === get_string('training_error_already_submitted', 'mod_redaction'));
    // After the deadline: neither attempt nor final.
    $DB->set_field('redaction_submission', 'status', 0, ['id' => $s6->id]);
    $DB->delete_records('redaction_ai_evaluations', ['submissionid' => $s6->id]);
    $DB->set_field('redaction_correction', 'deadline_date', time() - 60, ['redactionid' => $r6->id]);
    $tard = \mod_redaction\external\submit_final::execute($cm6->id);
    $tardessai = \mod_redaction\external\submit_sujets::execute($cm6->id);
    $check('after the deadline: final refused', !$tard['success'] && $tard['message'] === get_string('training_error_deadline_passed', 'mod_redaction'));
    $check('after the deadline: attempt refused', !$tardessai['success']
        && $tardessai['message'] === get_string('training_error_deadline_passed', 'mod_redaction'));
    $tardecrit = \mod_redaction\external\save_sujet_reponse::execute($cm6->id, (int) $sujet6->id, 'Texte après la date');
    $check('after the deadline: the answers can no longer be changed', !$tardecrit['success']
        && $tardecrit['message'] === get_string('training_error_deadline_passed', 'mod_redaction'));
    \core\session\manager::set_user(get_admin());

    // 7. Request 16: the deadline hands in the drafts; failed final corrections are retried, within limits.
    $r7 = $creeractivite(['training_enabled' => 1, 'ai_auto_apply' => 1, 'group_submission' => 0, 'training_max_attempts' => 5,
        'sujets_enabled' => 1, 'sujets_par_groupe' => 1, 'sujets_seuil' => 12]);
    $DB->insert_record('redaction_sujet', (object) [
        'redactionid' => $r7->id, 'sortorder' => 1, 'titre' => 'Seul', 'contenu' => 'Enonce', 'contenuformat' => FORMAT_HTML,
        'corrige' => 'E1 : a. E2 : b.', 'timecreated' => $now, 'timemodified' => $now,
    ]);
    $plein = redaction_get_or_create_submission($r7, 0, $a);
    repondre($plein, \mod_redaction\sujets::de_soumission($r7, $plein), 'Ma réponse écrite.', FORMAT_PLAIN);
    $vide7 = redaction_get_or_create_submission($r7, 0, $b);
    \mod_redaction\sujets::de_soumission($r7, $vide7);
    $tache = new \mod_redaction\task\auto_submit_deadline();
    ob_start();
    $tache->execute();
    ob_end_clean();
    $check('no deadline: nothing handed in', (int) $DB->get_field('redaction_submission', 'status', ['id' => $plein->id]) === 0);
    $DB->set_field('redaction_correction', 'deadline_date', time() - 60, ['redactionid' => $r7->id]);
    ob_start();
    $tache->execute();
    ob_end_clean();
    $p7 = $DB->get_record('redaction_submission', ['id' => $plein->id]);
    $f7 = $DB->get_records('redaction_ai_evaluations', ['submissionid' => $plein->id, 'finale' => 1]);
    $check('deadline passed: the draft is handed in and its final correction queued', (int) $p7->status === 1 && count($f7) === 1
        && reset($f7)->status === 'pending');
    foreach ($f7 as $ev7) {
        retirer_tache((int) $ev7->id);
    }
    $v7 = $DB->get_record('redaction_submission', ['id' => $vide7->id]);
    $check('deadline passed, empty copy: locked, no correction, no grade', (int) $v7->status === 1
        && !$DB->record_exists('redaction_ai_evaluations', ['submissionid' => $vide7->id]) && $v7->grade === null);

    // Retries: a failed final (429) gets a new one; a permanent error does not; 12 failures stop the retries.
    $ev7 = reset($f7);
    $DB->update_record('redaction_ai_evaluations', (object) ['id' => $ev7->id, 'status' => 'failed',
        'error_message' => 'HTTP 429: Request limit per minute exceeded', 'timemodified' => time() - 700]);
    $relance = new \mod_redaction\task\relancer_finales();
    ob_start();
    $relance->execute();
    ob_end_clean();
    $nouvelles = $DB->get_records_select('redaction_ai_evaluations', 'submissionid = ? AND finale = 1 AND id <> ?', [$plein->id, $ev7->id]);
    $check('failed final (429): a new final correction is queued, the failed one kept', count($nouvelles) === 1
        && reset($nouvelles)->status === 'pending' && $DB->get_field('redaction_ai_evaluations', 'status', ['id' => $ev7->id]) === 'failed');
    foreach ($nouvelles as $n) {
        retirer_tache((int) $n->id);
    }
    ob_start();
    $relance->execute();
    ob_end_clean();
    $check('a final still pending: no second one', $DB->count_records('redaction_ai_evaluations', ['submissionid' => $plein->id, 'finale' => 1]) === 2);
    $DB->set_field('redaction_ai_evaluations', 'status', 'failed', ['submissionid' => $plein->id]);
    $DB->set_field('redaction_ai_evaluations', 'error_message', get_string('sujets_corrige_vide', 'mod_redaction'), ['submissionid' => $plein->id]);
    ob_start();
    $relance->execute();
    ob_end_clean();
    $check('permanent error: no retry', $DB->count_records('redaction_ai_evaluations', ['submissionid' => $plein->id, 'finale' => 1]) === 2);
    $DB->set_field('redaction_ai_evaluations', 'error_message', 'HTTP 429', ['submissionid' => $plein->id]);
    for ($i = 0; $i < 10; $i++) {
        $DB->insert_record('redaction_ai_evaluations', (object) ['redactionid' => $r7->id, 'submissionid' => $plein->id, 'groupid' => 0,
            'userid' => $a, 'provider' => 'simule', 'model' => 'simule', 'status' => 'failed', 'finale' => 1, 'error_message' => 'HTTP 429',
            'timecreated' => time() - 700, 'timemodified' => time() - 700]);
    }
    ob_start();
    $relance->execute();
    ob_end_clean();
    $check('12 failed finals: no more retry', $DB->count_records('redaction_ai_evaluations', ['submissionid' => $plein->id, 'finale' => 1]) === 12);
    // Final review 4: the student sees the final version being corrected, not an attempt to submit again.
    \core\session\manager::set_user($DB->get_record('user', ['id' => $a]));
    $cm7e = get_coursemodule_from_instance('redaction', $r7->id, $course->id, false, MUST_EXIST);
    $ctx7e = context_module::instance($cm7e->id);
    $PAGE->set_context($ctx7e);
    $PAGE->set_url('/');
    $de = (new \mod_redaction\output\sujets_eleve($r7, $cm7e, $ctx7e, $DB->get_record('redaction_submission', ['id' => $plein->id]), 0))
        ->export_for_template($PAGE->get_renderer('core'));
    \core\session\manager::set_user(get_admin());
    $check('failed final, student side: "being corrected", no attempt notice, no indication', $de['etatlibelle'] === get_string('sujets_se_etat_correction', 'mod_redaction')
        && !$de['echec'] && !$de['hasindicatif'] && !$de['canessai'] && !$de['canfinale']);

    // 8. Request 16, the teacher: state of each copy, final vs attempt, reopen, retry, alert.
    $ctx7 = context_module::instance(get_coursemodule_from_instance('redaction', $r7->id, $course->id, false, MUST_EXIST)->id);
    $cm7 = get_coursemodule_from_instance('redaction', $r7->id, $course->id, false, MUST_EXIST);
    $PAGE->set_context($ctx7);
    $PAGE->set_url('/');
    $sortie = $PAGE->get_renderer('core');
    $lignes = fn() => array_column((new \mod_redaction\output\grading_sujets($r7, $cm7, $ctx7))->export_for_template($sortie)['groupes'], null, 'groupid');
    $l = $lignes();
    $check('state: failed final, nothing pending: handed in, no grade, with an alert (' . ($l[$a]['etat'] ?? '?') . ')',
        ($l[$a]['etat'] ?? '') === 'soumise' && str_contains((string) $l[$a]['alerte'], get_string('sujets_ens_alerte_finale', 'mod_redaction', 12)));
    $check('state: empty copy handed in by the deadline (' . ($l[$b]['etat'] ?? '?') . ')', ($l[$b]['etat'] ?? '') === 'soumise');
    [$msg, $type] = \mod_redaction\sujets::traiter_action($r7, $ctx7, 0, 'relancer', [], $a);
    $check('retry: a new final queued', $type === \core\output\notification::NOTIFY_SUCCESS
        && $DB->count_records('redaction_ai_evaluations', ['submissionid' => $plein->id, 'finale' => 1, 'status' => 'pending']) === 1);
    $l = $lignes();
    $check('state: final correction running (' . ($l[$a]['etat'] ?? '?') . ')', ($l[$a]['etat'] ?? '') === 'correction');
    // Final review 3: no reopening while the final correction runs (it would write a final back on a draft).
    [$msg, $type] = \mod_redaction\sujets::traiter_action($r7, $ctx7, 0, 'rouvrir', ['date' => time() + 86400], $a);
    $detailc = (new \mod_redaction\output\grading_sujets($r7, $cm7, $ctx7, 0, $a))->export_for_template($sortie);
    $check('reopen refused while the final correction runs, and not offered', $type === \core\output\notification::NOTIFY_ERROR
        && $msg === get_string('sujets_ens_rouvrir_attente', 'mod_redaction') && empty($detailc['peutrouvrir'])
        && (int) $DB->get_field('redaction_submission', 'status', ['id' => $plein->id]) === 1);
    foreach ($DB->get_records('redaction_ai_evaluations', ['submissionid' => $plein->id, 'status' => 'pending']) as $p8) {
        retirer_tache((int) $p8->id);
        $DB->update_record('redaction_ai_evaluations', (object) ['id' => $p8->id, 'status' => 'applied', 'parsed_grade' => 14.0]);
    }
    redaction_update_grades($r7);
    $l = $lignes();
    $check('state: graded (' . ($l[$a]['etat'] ?? '?') . ')', ($l[$a]['etat'] ?? '') === 'notee' && carnet($r7, $a) === 14.0);
    $detail = (new \mod_redaction\output\grading_sujets($r7, $cm7, $ctx7, 0, $a))->export_for_template($sortie);
    $check('detail: the final correction is marked as such', (bool) array_filter($detail['evaluations'], fn($e) => !empty($e['isfinale'])));
    $html = $sortie->render_from_template('mod_redaction/grading_sujets_detail', $detail);
    $check('detail rendered: state, Final tag, reopen button, no missing string', str_contains($html, get_string('sujets_ens_etat_notee', 'mod_redaction'))
        && str_contains($html, get_string('sujets_ens_finale', 'mod_redaction')) && str_contains($html, get_string('sujets_ens_rouvrir', 'mod_redaction'))
        && !str_contains($html, '[['));
    $html = $sortie->render_from_template('mod_redaction/grading_sujets_overview',
        (new \mod_redaction\output\grading_sujets($r7, $cm7, $ctx7))->export_for_template($sortie));
    $check('overview rendered with the state of each copy', str_contains($html, get_string('sujets_ens_etat_notee', 'mod_redaction')) && !str_contains($html, '[['));
    // Reopen: the deadline is passed, a new date is needed.
    [$msg, $type] = \mod_redaction\sujets::traiter_action($r7, $ctx7, 0, 'rouvrir', [], $a);
    $check('reopen after the deadline without a date: refused', $type === \core\output\notification::NOTIFY_ERROR
        && $msg === get_string('sujets_ens_rouvrir_date', 'mod_redaction'));
    [$msg, $type] = \mod_redaction\sujets::traiter_action($r7, $ctx7, 0, 'rouvrir', ['date' => time() + 86400], $a);
    $p8 = $DB->get_record('redaction_submission', ['id' => $plein->id]);
    $check('reopen with a date: draft again, override for the student, grade out of the gradebook',
        $type === \core\output\notification::NOTIFY_SUCCESS && (int) $p8->status === 0 && $p8->grade === null
        && $DB->record_exists('redaction_overrides', ['redactionid' => $r7->id, 'userid' => $a]) && carnet($r7, $a) === null);
    $l = $lignes();
    $check('state: draft again (' . ($l[$a]['etat'] ?? '?') . ')', ($l[$a]['etat'] ?? '') === 'brouillon');
} catch (Throwable $ex) {
    $check('no exception: ' . $ex->getMessage() . ' ' . $ex->getFile() . ':' . $ex->getLine(), false);
} finally {
    ai_evaluator::$fournisseurtest = null;
    if ($course) {
        ob_start();
        delete_course($course, false);
        ob_end_clean();
    }
    foreach ($users as $id) {
        delete_user($DB->get_record('user', ['id' => $id]));
        $DB->delete_records('user', ['id' => $id]);
    }
}

echo $failures ? "\n{$failures} failure(s)\n" : "\nAll checks passed\n";
exit($failures ? 1 : 0);
