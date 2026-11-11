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
 * CLI check of the teacher side of the assigned subjects mode (no PHPUnit needed).
 *
 * Usage: php verif_enseignant.php
 * It creates a throw-away course, activities, groups and users, then removes them. The provider is
 * simulated and no real AI call can happen.
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
use mod_redaction\sujets;

/** Provider that plays back prepared answers. */
class fournisseur_simule implements provider_interface {
    /** @var array Answers to play back. */
    public array $reponses = [];

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
        if (!$this->reponses) {
            throw new \RuntimeException('Simulated provider has no answer left');
        }
        return ['content' => array_shift($this->reponses), 'prompt_tokens' => 10, 'completion_tokens' => 5];
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
function ia(float $note): string {
    return json_encode(['attendus' => ['E1', 'E2'], 'note' => $note, 'commentaire' => 'Tu as vu juste.']);
}

/** Grade in the gradebook, null when there is none. */
function carnet(stdClass $redaction, int $userid): ?float {
    $info = grade_get_grades($redaction->course, 'mod', 'redaction', $redaction->id, $userid);
    $grade = $info->items[0]->grades[$userid]->grade ?? null;
    return $grade === null ? null : (float) $grade;
}

/** Removes the adhoc task of an evaluation, so nothing else can pick it up. */
function retirer_tache(int $evaluationid): void {
    global $DB;
    foreach ($DB->get_records('task_adhoc', ['classname' => '\mod_redaction\task\evaluate_submission']) as $tache) {
        if ((int) (json_decode($tache->customdata)->evaluationid ?? 0) === $evaluationid) {
            $DB->delete_records('task_adhoc', ['id' => $tache->id]);
        }
    }
}

/** One counted correction of a submission: both subjects get the same note. Returns the evaluation id. */
function corriger(stdClass $redaction, stdClass $submission, int $userid, float $note, \fournisseur_simule $sim): int {
    global $DB;
    $sim->reponses = [ia($note), ia($note)];
    $id = ai_evaluator::queue_evaluation($redaction->id, $submission->id, (int) $submission->groupid, $userid, true);
    retirer_tache($id);
    ai_evaluator::process_evaluation($id);
    if ($DB->get_field('redaction_ai_evaluations', 'status', ['id' => $id]) === 'completed') {
        ai_evaluator::apply_evaluation($id, 0);
    }
    return $id;
}

/** Calls a closure and tells whether it raised an exception. */
function leve(Closure $f): bool {
    try {
        $f();
    } catch (\Throwable $e) {
        return true;
    }
    return false;
}

\core\session\manager::set_user(get_admin());

$now = time();
$sim = new fournisseur_simule();
ai_evaluator::$fournisseurtest = $sim;
$course = null;
$users = [];
$olddelay = get_config('mod_redaction', 'ai_auto_apply_delay');
$studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
$moduleid = (int) $DB->get_field('modules', 'id', ['name' => 'redaction'], MUST_EXIST);
$rids = [];

try {
    $course = create_course((object) [
        'fullname' => 'verif_enseignant temporaire',
        'shortname' => 'verif_enseignant_' . $now,
        'category' => core_course_category::get_default()->id,
    ]);

    $creeractivite = function (array $options) use ($course, $moduleid, &$rids, $DB): array {
        $id = redaction_add_instance((object) ($options + [
            'course' => $course->id, 'name' => 'verif_enseignant temporaire', 'intro' => '', 'introformat' => FORMAT_HTML,
            'ai_enabled' => 1, 'ai_provider' => 'simule', 'langue_enabled' => 0,
        ]));
        $rids[] = $id;
        $cmid = add_course_module((object) ['course' => $course->id, 'module' => $moduleid, 'instance' => $id, 'section' => 0]);
        course_add_cm_to_section($course->id, $cmid, 0);
        return [$DB->get_record('redaction', ['id' => $id], '*', MUST_EXIST), get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST)];
    };
    $sujet = function (stdClass $redaction, int $i) use ($DB, $now): int {
        return $DB->insert_record('redaction_sujet', (object) [
            'redactionid' => $redaction->id, 'sortorder' => $i, 'titre' => 'Systeme' . $i, 'contenu' => 'Enonce' . $i,
            'contenuformat' => FORMAT_HTML, 'corrige' => 'Corrige' . $i, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    };

    foreach (['A', 'B', 'C'] as $lettre) {
        $id = user_create_user((object) [
            'username' => 'verifens' . strtolower($lettre) . $now, 'firstname' => 'Prenom' . $lettre, 'lastname' => 'Nom' . $lettre,
            'email' => "verifens{$lettre}{$now}@example.invalid", 'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
        ], false, false);
        $users[$lettre] = $id;
        enrol_try_internal_enrol($course->id, $id, $studentrole);
    }
    [$a, $b, $c] = array_values($users);
    $g1 = groups_create_group((object) ['courseid' => $course->id, 'name' => '9Y-GRP01']);
    $g2 = groups_create_group((object) ['courseid' => $course->id, 'name' => '9Y-GRP02']);
    groups_add_member($g1, $a);
    groups_add_member($g1, $b);
    groups_add_member($g2, $c);

    [$redaction, $cm] = $creeractivite(['training_enabled' => 1, 'ai_auto_apply' => 1, 'group_submission' => 1, 'training_max_attempts' => 5,
        'sujets_enabled' => 1, 'sujets_par_groupe' => 2, 'sujets_seuil' => 16]);
    set_config('ai_auto_apply_delay', 0, 'mod_redaction');
    $s = [1 => $sujet($redaction, 1), 2 => $sujet($redaction, 2), 3 => $sujet($redaction, 3)];
    $ids = fn(array $liste) => array_map('intval', array_column($liste, 'id'));
    $ordre = fn(array $liste) => array_values($ids($liste));

    $attribues = $ids(sujets::du_groupe($redaction, $g1));
    $submission = redaction_get_or_create_submission($redaction, $g1, $a);
    foreach ($attribues as $sid) {
        $DB->insert_record('redaction_sujet_reponse', (object) [
            'submissionid' => $submission->id, 'sujetid' => $sid, 'contenu' => 'Reponse' . $sid, 'contenuformat' => FORMAT_PLAIN,
            'userid' => $a, 'timemodified' => $now,
        ]);
    }

    // --- Teacher grade versus the AI.
    $e1 = corriger($redaction, $submission, $a, 12, $sim);
    $sub = $DB->get_record('redaction_submission', ['id' => $submission->id]);
    $check('first correction: grade 12 and gradebook 12', (float) $sub->grade === 12.0 && carnet($redaction, $a) === 12.0);

    $DB->set_field('redaction_submission', 'timemodified', 100, ['id' => $submission->id]);
    sujets::noter($redaction, $DB->get_record('redaction_submission', ['id' => $submission->id]), 10.0, 'Mon avis');
    $sub = $DB->get_record('redaction_submission', ['id' => $submission->id]);
    $check('teacher grade stored', (float) $sub->grade_enseignant === 10.0);
    $check('teacher grade wins over a better AI grade (12)', (float) $sub->grade === 10.0);
    $check('gradebook follows the teacher grade for both members', carnet($redaction, $a) === 10.0 && carnet($redaction, $b) === 10.0);
    $check('timemodified bumped when the grade is set', (int) $sub->timemodified > 100);
    $check('teacher feedback kept', $sub->feedback === 'Mon avis');

    $e2 = corriger($redaction, $submission, $a, 18, $sim);
    $sub = $DB->get_record('redaction_submission', ['id' => $submission->id]);
    $check('a later, better submission does not override the teacher', (float) $sub->grade === 10.0 && carnet($redaction, $a) === 10.0);
    $check('a later correction does not erase the teacher comment', $sub->feedback === 'Mon avis');

    $DB->set_field('redaction_submission', 'timemodified', 100, ['id' => $submission->id]);
    sujets::noter($redaction, $DB->get_record('redaction_submission', ['id' => $submission->id]), null);
    $sub = $DB->get_record('redaction_submission', ['id' => $submission->id]);
    $check('removing the teacher grade gives back the best AI grade (18)',
        $sub->grade_enseignant === null && (float) $sub->grade === 18.0 && carnet($redaction, $a) === 18.0 && carnet($redaction, $b) === 18.0);
    $check('timemodified bumped when the grade is removed', (int) $sub->timemodified > 100);

    sujets::noter($redaction, $sub, 5.0);
    $check('a lower teacher grade lowers the grade', (float) $DB->get_field('redaction_submission', 'grade', ['id' => $submission->id]) === 5.0);
    $sub = $DB->get_record('redaction_submission', ['id' => $submission->id]);
    $check('grade above 20 refused', leve(fn() => sujets::noter($redaction, $sub, 25.0)));
    $check('negative grade refused', leve(fn() => sujets::noter($redaction, $sub, -1.0)));
    $check('refused grades changed nothing', (float) $DB->get_field('redaction_submission', 'grade_enseignant', ['id' => $submission->id]) === 5.0);
    sujets::noter($redaction, $DB->get_record('redaction_submission', ['id' => $submission->id]), null);

    // --- Changing the subjects.
    $gradeavant = $DB->get_field('redaction_submission', 'grade', ['id' => $submission->id]);
    $absent = array_values(array_diff($s, $attribues))[0];
    $nouveaux = [$absent, $attribues[1]];
    $avant = $ids(sujets::du_groupe($redaction, $g1));
    sujets::changer($redaction, $g1, $nouveaux);
    $apres = $ids(sujets::du_groupe($redaction, $g1));
    $check('changer replaces the subjects of the group, in the given order', $apres === array_map('intval', $nouveaux));
    $check('changer leaves the grades alone',
        $DB->get_field('redaction_submission', 'grade', ['id' => $submission->id]) === $gradeavant && carnet($redaction, $a) === 18.0);
    $check('changer leaves the evaluations alone', $DB->count_records('redaction_ai_evaluations', ['submissionid' => $submission->id]) === 2);
    $check('answers of the removed subject stay in the table',
        $DB->count_records('redaction_sujet_reponse', ['submissionid' => $submission->id]) === count($attribues));
    $check('other groups are not touched', $DB->count_records('redaction_sujet_groupe', ['redactionid' => $redaction->id, 'groupid' => $g2]) === 0);

    sujets::changer($redaction, $g1, $avant);
    $check('putting the subjects back brings the previous answers back',
        $ids(sujets::du_groupe($redaction, $g1)) === $avant
        && $DB->get_field('redaction_sujet_reponse', 'contenu', ['submissionid' => $submission->id, 'sujetid' => $avant[0]]) === 'Reponse' . $avant[0]);

    $avantrefus = $ids(sujets::du_groupe($redaction, $g1));
    $check('one subject only is refused', leve(fn() => sujets::changer($redaction, $g1, [$s[1]])));
    $check('three subjects are refused', leve(fn() => sujets::changer($redaction, $g1, [$s[1], $s[2], $s[3]])));
    $check('duplicates are refused', leve(fn() => sujets::changer($redaction, $g1, [$s[1], $s[1]])));

    [$autre] = $creeractivite(['group_submission' => 1, 'sujets_enabled' => 1, 'sujets_par_groupe' => 2, 'sujets_seuil' => 16, 'training_enabled' => 1]);
    $etranger = $sujet($autre, 1);
    $check('a subject of another activity is refused', leve(fn() => sujets::changer($redaction, $g1, [$s[1], $etranger])));
    $check('an unknown subject is refused', leve(fn() => sujets::changer($redaction, $g1, [$s[1], 999999999])));
    $check('a group of another course is refused', leve(fn() => sujets::changer($redaction, 999999999, [$s[1], $s[2]])));
    $check('refusals changed nothing', $ids(sujets::du_groupe($redaction, $g1)) === $avantrefus);

    // A group that never opened the activity can be given subjects, and du_groupe keeps them.
    sujets::changer($redaction, $g2, [$s[2], $s[3]]);
    $check('a group without attribution can be given subjects', $ids(sujets::du_groupe($redaction, $g2)) === [$s[2], $s[3]]);
    $check('reading an unopened group does not attribute', sujets::lire($redaction->id, $g1) !== [] && sujets::lire($redaction->id, 999999999) === []);

    // --- The other paths that write a grade or launch an evaluation.
    $sub = $DB->get_record('redaction_submission', ['id' => $submission->id]);
    $DB->set_field('redaction_submission', 'contenu', 'Du texte', ['id' => $submission->id]);
    $avantevals = $DB->count_records('redaction_ai_evaluations');
    $r = \mod_redaction\external\evaluate_submission::execute($cm->id, $submission->id);
    $check('evaluate_submission is refused in this mode', $r['success'] === false && $DB->count_records('redaction_ai_evaluations') === $avantevals);
    $r = \mod_redaction\external\bulk_evaluate::execute($cm->id, [$submission->id]);
    $check('bulk_evaluate queues nothing in this mode', $r['queued'] === 0 && $DB->count_records('redaction_ai_evaluations') === $avantevals);

    $statutavant = $DB->get_field('redaction_submission', 'status', ['id' => $submission->id]);
    $r = \mod_redaction\external\submit_action::execute($cm->id, 'unlock', $g1, 0, $submission->id);
    $check('submit_action unlock refused in this mode', $r['success'] === false);
    $r = \mod_redaction\external\submit_action::execute($cm->id, 'submit', $g1);
    $check('submit_action submit refused in this mode', $r['success'] === false);
    $r = \mod_redaction\external\bulk_unlock::execute($cm->id, [$submission->id]);
    $check('bulk_unlock refused in this mode', $r['success'] === false && $r['unlocked'] === 0);
    $check('submit_work refused in this mode', leve(fn() => \mod_redaction\external\submit_work::execute($cm->id, 'T', 'C', true)));
    $check('none of them changed the status', $DB->get_field('redaction_submission', 'status', ['id' => $submission->id]) === $statutavant);

    // apply_ai_grade on a completed evaluation must not override the teacher grade.
    sujets::noter($redaction, $DB->get_record('redaction_submission', ['id' => $submission->id]), 9.0);
    $sim->reponses = [ia(19), ia(19)];
    $e3 = ai_evaluator::queue_evaluation($redaction->id, $submission->id, $g1, $a, true);
    retirer_tache($e3);
    ai_evaluator::process_evaluation($e3);
    $DB->set_field('redaction_ai_evaluations', 'status', 'completed', ['id' => $e3]);
    $r = \mod_redaction\external\apply_ai_grade::execute($cm->id, $e3);
    $sub = $DB->get_record('redaction_submission', ['id' => $submission->id]);
    $check('apply_ai_grade keeps the teacher grade (9)', $r['success'] && (float) $sub->grade === 9.0 && (float) $sub->grade_enseignant === 9.0 && carnet($redaction, $a) === 9.0);
    sujets::noter($redaction, $sub, null);
    $check('then removing it gives 19', (float) $DB->get_field('redaction_submission', 'grade', ['id' => $submission->id]) === 19.0);

    // --- Screen data.
    $PAGE->set_url('/mod/redaction/view.php', ['id' => $cm->id]);
    $PAGE->set_context(context_module::instance($cm->id));
    $sortie = $PAGE->get_renderer('core');
    $DB->set_field('redaction_ai_evaluations', 'status', 'failed', ['id' => $e3]);
    $DB->set_field('redaction_ai_evaluations', 'error_message', '<b>boom</b>', ['id' => $e3]);
    $DB->set_field('redaction_sujet_reponse', 'contenu', '<script>alert(1)</script> <5 cm', ['submissionid' => $submission->id, 'sujetid' => $avant[0]]);
    $apercu = (new \mod_redaction\output\grading_sujets($redaction, $cm, context_module::instance($cm->id), 0))->export_for_template($sortie);
    $lignes = array_column($apercu['groupes'], null, 'groupid');
    $check('overview: one row per group of the course', count($lignes) === 2 && isset($lignes[$g1], $lignes[$g2]));
    $check('overview: systems and best grade of the group', $lignes[$g1]['systemes'] !== '' && $lignes[$g1]['hasnote'] && $lignes[$g1]['note'] === '19');
    $check('overview: a failed correction is flagged', $lignes[$g1]['alerte'] !== '');
    $check('overview: a group that never opened has no grade and no systems claimed',
        !$lignes[$g2]['hasnote'] && $lignes[$g2]['used'] === 0);
    $html = $sortie->render_from_template('mod_redaction/grading_sujets_overview', $apercu);
    $check('overview renders with the group name and a link to the detail', strpos($html, '9Y-GRP01') !== false && strpos($html, 'groupid=' . $g1) !== false);

    $detail = (new \mod_redaction\output\grading_sujets($redaction, $cm, context_module::instance($cm->id), $g1))->export_for_template($sortie);
    $html = $sortie->render_from_template('mod_redaction/grading_sujets_detail', $detail);
    $check('detail: answers are escaped', strpos($html, '<script>alert') === false && strpos($html, '&lt;script&gt;') !== false);
    $check('detail: error message is escaped', strpos($html, '<b>boom</b>') === false && strpos($html, '&lt;b&gt;boom') !== false);
    $check('detail: key shown to the teacher', strpos($html, 'Corrige') !== false);
    $check('detail: one block per evaluation, the failed one flagged', count($detail['evaluations']) === 3 && $detail['alerte'] !== '');
    $check('detail: per-subject detail of a completed evaluation', strpos($html, 'Tu as vu juste.') !== false);
    $check('detail, language off: subjects on 20, no language block', strpos($html, '/20') !== false
        && strpos($html, 'mod_redaction-langue') === false);

    // A pending evaluation shows its age.
    $DB->set_field('redaction_ai_evaluations', 'status', 'pending', ['id' => $e2]);
    $DB->set_field('redaction_ai_evaluations', 'timecreated', time() - 3600, ['id' => $e2]);
    $detail = (new \mod_redaction\output\grading_sujets($redaction, $cm, context_module::instance($cm->id), $g1))->export_for_template($sortie);
    $enattente = array_values(array_filter($detail['evaluations'], fn($e) => $e['id'] == $e2))[0];
    $check('detail: a pending evaluation shows its age', $enattente['ispending'] && $enattente['age'] !== '' && $enattente['longue']);

    // A grade with a decimal comma shows in a number input as a dot; the teacher comment is shown on both screens.
    sujets::noter($redaction, $DB->get_record('redaction_submission', ['id' => $submission->id]), 12.5, 'Reprends le systeme 2.');
    $detail = (new \mod_redaction\output\grading_sujets($redaction, $cm, context_module::instance($cm->id), $g1))->export_for_template($sortie);
    $check('detail: the grade of the teacher is not localised', $detail['noteprof'] === '12.5');
    $check('detail: the comment of the teacher is prefilled', $detail['commentaireprof'] === 'Reprends le systeme 2.'
        && strpos($sortie->render_from_template('mod_redaction/grading_sujets_detail', $detail), 'Reprends le systeme 2.</textarea>') !== false);
    \core\session\manager::set_user($DB->get_record('user', ['id' => $a]));
    $eleve = (new \mod_redaction\output\sujets_eleve($redaction, $cm, context_module::instance($cm->id), $DB->get_record('redaction_submission', ['id' => $submission->id]), $g1))->export_for_template($sortie);
    $check('student: the comment of the teacher is shown', $eleve['hascommentaireprof'] && $eleve['commentaireprof'] === 'Reprends le systeme 2.');
    $etat = \mod_redaction\external\get_sujets_etat::execute($cm->id);
    $check('get_sujets_etat: the saved answers of the group, and the correction state', $etat['pending'] === true && is_array($etat['reponses']));
    // A grade locked in the gradebook still shows to the student, and is only left out of what is written.
    $item = grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod', 'itemmodule' => 'redaction', 'iteminstance' => $redaction->id, 'itemnumber' => 0]);
    $item->get_grade($a, true)->set_locked(true, false);
    $check('locked grade: shown to the student', isset(redaction_get_user_grades_sujets($redaction, $a)[$a]));
    $check('locked grade: left out of the gradebook write', !isset(redaction_get_user_grades($redaction, $a)[$a]));
    $item->get_grade($a, true)->set_locked(false, false);
    $check('legacy autosave refused in this mode', \mod_redaction\external\autosave::execute($cm->id, 'redaction', json_encode(['contenu' => 'x']), $g1)['success'] === false);
    \core\session\manager::set_user(get_admin());
    sujets::noter($redaction, $DB->get_record('redaction_submission', ['id' => $submission->id]), null);

    // The history behind each correction, the overview in a fixed number of queries, the alert past a running correction.
    $DB->insert_record('redaction_history', (object) ['submissionid' => $submission->id, 'redactionid' => $redaction->id, 'groupid' => $g1, 'userid' => 0, 'titre' => '',
        'contenu' => "Systeme1\nTexte de la version", 'contenuformat' => FORMAT_PLAIN, 'version_number' => 1, 'word_count' => 4, 'char_count' => 30,
        'saved_by' => $a, 'timecreated' => 1]);
    $detail = (new \mod_redaction\output\grading_sujets($redaction, $cm, context_module::instance($cm->id), $g1))->export_for_template($sortie);
    $check('detail: the corrections carry the text they were given', count(array_filter($detail['evaluations'], fn($e) => $e['hastexte'])) >= 1);
    $avant = $DB->perf_get_reads();
    (new \mod_redaction\output\grading_sujets($redaction, $cm, context_module::instance($cm->id), 0))->export_for_template($sortie);
    $numeros = array_values(array_map(fn($e) => $e['numero'], array_filter($detail['evaluations'], fn($e) => $e['hasnumero'])));
    $check('detail: failed corrections have no attempt number, the others are numbered down from the count',
        count(array_filter($detail['evaluations'], fn($e) => $e['isfailed'] && !$e['hasnumero'])) >= 1
        && $numeros === range(count($numeros), 1));
    $accueil = $sortie->render_from_template('mod_redaction/home', ['isteacher' => true, 'cangrade' => true, 'sujetsenabled' => true,
        'consignesurl' => 'URLCONSIGNES', 'correctionurl' => 'URLCORRECTION', 'sujetsurl' => 'URLSUJETS', 'gradingurl' => 'URLNOTES',
        'submittedcountstr' => '3']);
    $check('teacher home: no instructions or model card in this mode, the subjects card stays',
        strpos($accueil, 'URLCONSIGNES') === false && strpos($accueil, 'URLCORRECTION') === false && strpos($accueil, 'URLSUJETS') !== false);
    $check('overview: a fixed number of reads, whatever the groups (' . ($DB->perf_get_reads() - $avant) . ')', $DB->perf_get_reads() - $avant < 20);

    // --- Actions of the grading screen.
    $ctx = context_module::instance($cm->id);
    $ok = \core\output\notification::NOTIFY_SUCCESS;
    $ko = \core\output\notification::NOTIFY_ERROR;
    $agir = fn(string $action, array $donnees = []) => sujets::traiter_action($redaction, $ctx, $g1, $action, $donnees);
    [, $type] = $agir('note', ['grade' => 7.5, 'feedback' => '']);
    $check('screen: set a grade', $type === $ok && (float) $DB->get_field('redaction_submission', 'grade_enseignant', ['id' => $submission->id]) === 7.5);
    [, $type] = $agir('note', ['grade' => 30.0]);
    $check('screen: a grade above 20 is an error message, not an exception', $type === $ko);
    [, $type] = $agir('retirer');
    $check('screen: remove the grade', $type === $ok && $DB->get_field('redaction_submission', 'grade_enseignant', ['id' => $submission->id]) === null);
    [, $type] = $agir('systemes', ['sujetid' => [$s[1], $s[2]]]);
    $check('screen: the group has written, the change needs the confirmation', $type === $ko);
    [, $type] = $agir('systemes', ['sujetid' => [$s[1], $s[2]], 'confirme' => true]);
    $check('screen: confirmed change goes through', $type === $ok && $ids(sujets::du_groupe($redaction, $g1)) === [$s[1], $s[2]]);
    [, $type] = $agir('systemes', ['sujetid' => [$s[1]], 'confirme' => true]);
    $check('screen: an invalid list is an error message', $type === $ko);
    [, $type] = $agir('nimporte');
    $check('screen: unknown action refused', $type === $ko);
    [, $type] = sujets::traiter_action($redaction, $ctx, $g2, 'note', ['grade' => 10.0]);
    $check('screen: grade on a group without work refused', $type === $ko);

    // --- Language part: the teacher sees the levels, the exact forms and what the server dropped.
    [$lang, $cml] = $creeractivite(['training_enabled' => 1, 'ai_auto_apply' => 1, 'group_submission' => 1, 'training_max_attempts' => 5,
        'sujets_enabled' => 1, 'sujets_par_groupe' => 2, 'sujets_seuil' => 16, 'langue_enabled' => 1, 'langue_points' => 2]);
    $sujet($lang, 1);
    $sujet($lang, 2);
    $sublang = redaction_get_or_create_submission($lang, $g1, $a);
    foreach ($ids(sujets::du_groupe($lang, $g1)) as $sid) {
        $DB->insert_record('redaction_sujet_reponse', (object) [
            'submissionid' => $sublang->id, 'sujetid' => $sid, 'contenu' => 'Le fonctionnemant du systeme', 'contenuformat' => FORMAT_PLAIN,
            'userid' => $a, 'timemodified' => $now,
        ]);
    }
    $langueia = json_encode(['orthographe' => ['palier' => 'I', 'fautes' => [
            ['cite' => 'fonctionnemant', 'correction' => 'fonctionnement', 'type' => 'lexicale', 'fois' => 2],
            ['cite' => 'resisté', 'correction' => 'résisté', 'type' => 'lexicale', 'fois' => 1]]],
        'syntaxe' => ['palier' => 'S', 'remarques' => []], 'lexique' => ['palier' => 'S'], 'organisation' => ['palier' => 'TS']],
        JSON_UNESCAPED_UNICODE);
    $sim->reponses = [ia(18), ia(17), $langueia];
    $idl = ai_evaluator::queue_evaluation($lang->id, $sublang->id, (int) $g1, $a, true);
    retirer_tache($idl);
    ai_evaluator::process_evaluation($idl);
    $detail = (new \mod_redaction\output\grading_sujets($lang, $cml, context_module::instance($cml->id), $g1))->export_for_template($sortie);
    $html = $sortie->render_from_template('mod_redaction/grading_sujets_detail', $detail);
    $check('teacher, language: the levels in words and letters',
        strpos($html, get_string('langue_palier_i', 'mod_redaction') . ' (I)') !== false
        && strpos($html, get_string('langue_palier_ts', 'mod_redaction') . ' (TS)') !== false);
    $check('teacher, language: the mistake with its exact form', strpos($html, 'fonctionnemant') !== false
        && strpos($html, 'fonctionnement') !== false);
    $check('teacher, language: the dropped quotes are counted', strpos($html, 'mod_redaction-langue-ecartees') !== false
        && strpos($html, htmlspecialchars(get_string('langue_ecartees', 'mod_redaction', 1), ENT_QUOTES)) !== false
        && strpos($html, 'resisté') === false);
    $check('teacher, language: subjects on 18', strpos($html, format_float(16.2, 1, true, true) . '/18') !== false
        && strpos($html, format_float(15.3, 1, true, true) . '/18') !== false);

    // The teacher changes the setting: the earlier corrections stay in the history, flagged, without a number.
    $data = clone $lang;
    $data->instance = $lang->id;
    unset($data->id);
    $data->langue_points = 3;
    redaction_update_instance($data);
    $lang = $DB->get_record('redaction', ['id' => $lang->id], '*', MUST_EXIST);
    $detail = (new \mod_redaction\output\grading_sujets($lang, $cml, context_module::instance($cml->id), $g1))->export_for_template($sortie);
    $html = $sortie->render_from_template('mod_redaction/grading_sujets_detail', $detail);
    $ev = $detail['evaluations'][0];
    $check('teacher: a correction graded with another setting is flagged and has no number',
        $ev['necompteplus'] && !$ev['hasnumero']
        && strpos($html, htmlspecialchars(get_string('sujets_ens_ne_compte_plus', 'mod_redaction'), ENT_QUOTES)) !== false);

    // --- Ordinary activity: nothing changes.
    [$ordinaire, $cmo] = $creeractivite(['training_enabled' => 1, 'ai_enabled' => 1, 'group_submission' => 0]);
    $so = redaction_get_or_create_submission($ordinaire, 0, $a);
    $DB->set_field('redaction_submission', 'contenu', 'Texte', ['id' => $so->id]);
    $r = \mod_redaction\external\bulk_evaluate::execute($cmo->id, [$so->id]);
    $check('ordinary activity: bulk_evaluate still queues', $r['queued'] === 1);
    foreach ($DB->get_records('redaction_ai_evaluations', ['submissionid' => $so->id]) as $ev) {
        retirer_tache($ev->id);
        $DB->set_field('redaction_ai_evaluations', 'status', 'completed', ['id' => $ev->id]);
        $DB->set_field('redaction_ai_evaluations', 'parsed_grade', 14, ['id' => $ev->id]);
        $r = \mod_redaction\external\apply_ai_grade::execute($cmo->id, $ev->id);
    }
    $so = $DB->get_record('redaction_submission', ['id' => $so->id]);
    $check('ordinary activity: apply_ai_grade writes the grade as before', (float) $so->grade === 14.0 && $so->grade_enseignant === null);
} finally {
    ai_evaluator::$fournisseurtest = null;
    if ($olddelay === false) {
        unset_config('ai_auto_apply_delay', 'mod_redaction');
    } else {
        set_config('ai_auto_apply_delay', $olddelay, 'mod_redaction');
    }
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

if ($rids) {
    [$insql, $inparams] = $DB->get_in_or_equal($rids);
    $reste = $DB->count_records_select('redaction', "id $insql", $inparams)
        + $DB->count_records_select('redaction_submission', "redactionid $insql", $inparams)
        + $DB->count_records_select('redaction_ai_evaluations', "redactionid $insql", $inparams)
        + $DB->count_records_select('redaction_sujet', "redactionid $insql", $inparams)
        + $DB->count_records_select('redaction_sujet_groupe', "redactionid $insql", $inparams);
    $check("nothing left behind (found {$reste})", $reste === 0);
}

echo $failures ? "\n{$failures} failure(s)\n" : "\nAll checks passed\n";
exit($failures ? 1 : 0);
