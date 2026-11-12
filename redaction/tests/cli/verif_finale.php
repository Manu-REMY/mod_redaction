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
    $check('an attempt with an empty subject is still refused',
        redaction_can_submit_attempt($r3, $DB->get_record('redaction_submission', ['id' => $s3->id]))['reason'] === 'empty_answer');
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
