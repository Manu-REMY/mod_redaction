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
 * CLI check of the student screen of the assigned subjects mode (no PHPUnit needed).
 *
 * Usage: php verif_ecran.php [--dump=/existing/dir]
 * It creates a throw-away course, activities, groups and users, then removes them. The provider is
 * simulated and the activities use a provider name no real API answers to: no AI call can happen.
 * With --dump the rendered screen of each state is saved as HTML, to be photographed afterwards.
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
function ia(float $note, string $commentaire): string {
    return json_encode(['attendus' => ['E1', 'E2'], 'note' => $note, 'commentaire' => $commentaire]);
}

/** Calls a student service as a user and returns the result, or the exception. */
function appeler(int $userid, callable $f) {
    \core\session\manager::set_user(core_user::get_user($userid));
    try {
        return $f();
    } catch (\Throwable $e) {
        return $e;
    }
}

/** Removes the adhoc tasks of the evaluations of a submission, so nothing else can pick them up. */
function retirer_taches(int $submissionid): int {
    global $DB;
    $n = 0;
    $ids = $DB->get_fieldset_select('redaction_ai_evaluations', 'id', 'submissionid = ?', [$submissionid]);
    foreach ($DB->get_records('task_adhoc', ['classname' => '\mod_redaction\task\evaluate_submission']) as $tache) {
        if (in_array((int) (json_decode($tache->customdata)->evaluationid ?? 0), array_map('intval', $ids), true)) {
            $DB->delete_records('task_adhoc', ['id' => $tache->id]);
            $n++;
        }
    }
    return $n;
}

$dump = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--dump=') === 0) {
        $dump = rtrim(substr($arg, 7), '/');
    }
}

\core\session\manager::set_user(get_admin());
force_current_language('fr');

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
        'fullname' => 'verif_ecran temporaire',
        'shortname' => 'verif_ecran_' . $now,
        'category' => core_course_category::get_default()->id,
    ]);

    $id = redaction_add_instance((object) [
        'course' => $course->id, 'name' => 'verif_ecran temporaire', 'intro' => '', 'introformat' => FORMAT_HTML,
        'ai_enabled' => 1, 'ai_provider' => 'simule', 'training_enabled' => 1, 'ai_auto_apply' => 1, 'group_submission' => 1,
        'training_max_attempts' => 5, 'sujets_enabled' => 1, 'sujets_par_groupe' => 2, 'sujets_seuil' => 16,
    ]);
    $rids[] = $id;
    $cmid = add_course_module((object) [
        'course' => $course->id, 'module' => $moduleid, 'instance' => $id, 'section' => 0, 'groupmode' => SEPARATEGROUPS,
    ]);
    course_add_cm_to_section($course->id, $cmid, 0);
    $redaction = $DB->get_record('redaction', ['id' => $id], '*', MUST_EXIST);
    $cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
    $context = context_module::instance($cmid);

    foreach (['A', 'B', 'C', 'D'] as $lettre) {
        $uid = user_create_user((object) [
            'username' => 'verifecr' . strtolower($lettre) . $now, 'firstname' => 'Eleve' . $lettre, 'lastname' => 'Test' . $lettre,
            'email' => "verifecr{$lettre}{$now}@example.invalid", 'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
        ], false, false);
        $users[$lettre] = $uid;
        enrol_try_internal_enrol($course->id, $uid, $studentrole);
    }
    [$a, $b, $c, $d] = array_values($users);

    $g1 = (int) groups_create_group((object) ['courseid' => $course->id, 'name' => '9Z-GRP01']);
    $g2 = (int) groups_create_group((object) ['courseid' => $course->id, 'name' => '9Z-GRP02']);
    groups_add_member($g1, $a);
    groups_add_member($g1, $b);
    groups_add_member($g2, $c);

    // Four subjects, attributed by hand so the groups share none: attribution itself is checked elsewhere.
    $ids = [];
    for ($i = 1; $i <= 4; $i++) {
        $ids[$i] = $DB->insert_record('redaction_sujet', (object) [
            'redactionid' => $redaction->id, 'sortorder' => $i, 'titre' => 'Systeme' . $i,
            'contenu' => '<p>Enonce' . $i . ' <a href="https://example.invalid/anim' . $i . '">animation</a></p>', 'contenuformat' => FORMAT_HTML,
            'corrige' => 'Corrigesecret' . $i . ' : le gouvernail pivote.', 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }
    foreach ([$g1 => [1, 2], $g2 => [3, 4]] as $groupe => $rangs) {
        foreach ($rangs as $rang => $i) {
            $DB->insert_record('redaction_sujet_groupe', (object) [
                'redactionid' => $redaction->id, 'groupid' => $groupe, 'sujetid' => $ids[$i], 'rang' => $rang + 1, 'timecreated' => $now,
            ]);
        }
    }
    $sujets1 = \mod_redaction\sujets::du_groupe($redaction, $g1);
    $sujets2 = \mod_redaction\sujets::du_groupe($redaction, $g2);
    $s1 = array_values($sujets1);
    $horsg1 = array_values(array_diff(array_keys($DB->get_records('redaction_sujet', ['redactionid' => $redaction->id])), array_keys($sujets1)));
    $check('the group has two subjects and two are foreign to it', count($sujets1) === 2 && count($horsg1) === 2);

    $save = fn(int $uid, int $sujetid, string $texte) => appeler($uid, fn() => \mod_redaction\external\save_sujet_reponse::execute($cmid, $sujetid, $texte));
    $submit = fn(int $uid) => appeler($uid, fn() => \mod_redaction\external\submit_sujets::execute($cmid));
    $status = fn(int $uid, int $subid) => appeler($uid, fn() => \mod_redaction\external\get_evaluation_status::execute($cmid, $subid));
    $rendre = function (int $uid) use ($redaction, $cm, $context, $g1, $DB, $OUTPUT, $PAGE) {
        \core\session\manager::set_user(core_user::get_user($uid));
        $PAGE->set_url('/mod/redaction/view.php', ['id' => $cm->id]);
        $PAGE->set_context($context);
        $output = $PAGE->get_renderer('core');
        $submission = redaction_get_or_create_submission($redaction, $g1, $uid);
        $redaction = $DB->get_record('redaction', ['id' => $redaction->id], '*', MUST_EXIST);
        $data = (new \mod_redaction\output\sujets_eleve($redaction, $cm, $context, $submission, $g1))->export_for_template($output);
        return [$data, $output->render_from_template('mod_redaction/sujets_eleve', $data)];
    };
    $enregistrer = function (string $nom, string $html) use ($dump) {
        if ($dump !== null) {
            file_put_contents("{$dump}/{$nom}.html", $html);
        }
    };

    // Without a group.
    $r = $save($d, $s1[0]->id, 'x');
    $check('no group: save refused with group_required', $r instanceof moodle_exception && $r->errorcode === 'group_required');
    $r = $submit($d);
    $check('no group: submit refused with group_required', $r instanceof moodle_exception && $r->errorcode === 'group_required');
    $check('no group: the page message exists', get_string_manager()->string_exists('group_required', 'mod_redaction'));

    // First screen, as two members.
    [$da, $ha] = $rendre($a);
    [$db, $hb] = $rendre($b);
    $check('screen: two subjects with their titles and statements', count($da['items']) === 2
        && strpos($ha, 'Systeme') !== false && strpos($ha, 'Enonce') !== false && strpos($ha, 'https://example.invalid/anim') !== false);
    $check('screen: the same screen for both members', $ha === $hb);
    $check('screen: the expected answer never reaches the screen', stripos($ha, 'corrige') === false && stripos($ha, 'Corrigesecret') === false
        && stripos(json_encode($da), 'corrige') === false);
    $check('screen: no inline style', stripos($ha, 'style=') === false);
    $check('screen: banner not validated, threshold recalled', !$da['valide'] && strpos($ha, 'mod_redaction-se-ko') !== false
        && strpos($ha, 'Niveau fragile pas encore validé') !== false && strpos($ha, 'Il faut au moins 16/20') !== false);
    $check('screen: no grade yet, five attempts left', !$da['hasnote'] && $da['restants'] === 5);
    $enregistrer('1-debut-membre-A', $ha);
    $enregistrer('1-debut-membre-B', $hb);

    // Writing: only the group's own subjects.
    $r = $save($c, $s1[0]->id, 'intrus');
    $check('another group cannot write on these subjects', $r instanceof moodle_exception && $r->errorcode === 'sujets_hors_groupe');
    $r = $save($a, $horsg1[0], 'hors groupe');
    $check('a member cannot write on a subject not attributed to the group', $r instanceof moodle_exception && $r->errorcode === 'sujets_hors_groupe');
    $check('nothing was written by the refused calls', $DB->count_records_sql('SELECT COUNT(1) FROM {redaction_sujet_reponse} rsr JOIN {redaction_submission} rs ON rs.id = rsr.submissionid WHERE rs.redactionid = ?', [$redaction->id]) === 0);
    $r = $save($a, $s1[0]->id, 'Le gouvernail <b>tourne</b> & "dirige"');
    $check('a member saves an answer', is_array($r) && $r['success']);
    $ligne = $DB->get_record('redaction_sujet_reponse', ['sujetid' => $s1[0]->id]);
    $check('the author is recorded, format plain', (int) $ligne->userid === $a && (int) $ligne->contenuformat === (int) FORMAT_PLAIN);
    $r = $save($b, $s1[0]->id, 'Le gouvernail tourne la roue');
    $ligne = $DB->get_record('redaction_sujet_reponse', ['sujetid' => $s1[0]->id]);
    $check('the other member overwrites the same answer and becomes its author', $r['success'] && (int) $ligne->userid === $b
        && $DB->count_records('redaction_sujet_reponse', ['sujetid' => $s1[0]->id]) === 1);
    $save($b, $s1[0]->id, 'Le gouvernail tourne la roue');
    $sub = $DB->get_record('redaction_submission', ['redactionid' => $redaction->id, 'groupid' => $g1]);
    $histo = $DB->get_records('redaction_history', ['submissionid' => $sub->id], 'version_number');
    $savedby = array_map(fn($h) => (int) $h->saved_by, array_values($histo));
    $check('history keeps the author of each save (unchanged text adds nothing)', $savedby === [$a, $b]);
    $derniere = end($histo);
    $check('history records the answers of both subjects', strpos($derniere->contenu, $s1[0]->titre) !== false && strpos($derniere->contenu, $s1[1]->titre) !== false
        && strpos($derniere->contenu, 'la roue') !== false);

    [$da, $ha] = $rendre($a);
    $check('the screen shows the saved answer, escaped, to every member', strpos($ha, 'Le gouvernail tourne la roue') !== false);
    $save($a, $s1[0]->id, 'Texte <script>alert(1)</script>');
    [, $ha2] = $rendre($a);
    $check('an answer with markup is escaped on display', strpos($ha2, '<script>alert') === false && strpos($ha2, '&lt;script&gt;') !== false);
    $save($a, $s1[0]->id, 'Le gouvernail tourne la roue');

    // Submitting with a missing answer.
    $r = $submit($a);
    $sub = $DB->get_record('redaction_submission', ['id' => $sub->id]);
    $check('empty answer: refused with the message, no attempt used', is_array($r) && !$r['success']
        && $r['message'] === get_string('training_error_empty_answer', 'mod_redaction')
        && (int) $sub->training_count === 0 && $DB->count_records('redaction_ai_evaluations', ['submissionid' => $sub->id]) === 0);
    [$da] = $rendre($a);
    $check('empty answer: the button stays usable', !$da['disabled']);

    // Submitting.
    $save($a, $s1[1]->id, 'Le moteur pousse');
    $r = $submit($a);
    $sub = $DB->get_record('redaction_submission', ['id' => $sub->id]);
    $check('submit: queued, one attempt counted', is_array($r) && $r['success'] && $r['evaluationid'] > 0 && (int) $sub->training_count === 1);
    $eval = $DB->get_record('redaction_ai_evaluations', ['id' => $r['evaluationid']]);
    $check('submit: pending evaluation for the group, by the submitting member', $eval->status === 'pending' && (int) $eval->groupid === $g1 && (int) $eval->userid === $a);
    $check('submit: members captured with the evaluation', $DB->count_records('redaction_sujet_membre', ['evaluationid' => $r['evaluationid']]) === 2);
    $r2 = $submit($b);
    $sub = $DB->get_record('redaction_submission', ['id' => $sub->id]);
    $check('the other member cannot submit at the same time, nothing counted twice', is_array($r2) && !$r2['success']
        && $r2['message'] === get_string('training_error_evaluation_pending', 'mod_redaction') && (int) $sub->training_count === 1
        && $DB->count_records('redaction_ai_evaluations', ['submissionid' => $sub->id]) === 1);
    $r3 = $save($b, $s1[1]->id, 'Autre texte');
    $check('writing is refused while the correction runs', is_array($r3) && !$r3['success']
        && $DB->get_field('redaction_sujet_reponse', 'contenu', ['sujetid' => $s1[1]->id]) === 'Le moteur pousse');
    [$da, $ha] = $rendre($b);
    $check('pending: same state for the other member, button disabled and worded', $da['pending'] && $da['disabled']
        && strpos($ha, 'Correction en cours') !== false && strpos($ha, 'disabled') !== false && strpos($ha, 'readonly') !== false);
    $enregistrer('2-correction-en-cours', $ha);
    $st = $status($b, $sub->id);
    $check('get_evaluation_status gives the pending state to a member', is_array($st) && $st['status'] === 'pending');
    $st = $status($c, $sub->id);
    $check('get_evaluation_status refuses another group', $st instanceof \required_capability_exception);

    // Correction: 12 and 10, not validated.
    retirer_taches($sub->id);
    $sim->reponses = [ia(12, 'Tu as vu la roue.'), ia(10, 'Il manque le role du moteur.')];
    ai_evaluator::process_evaluation((int) $eval->id);
    $eval = $DB->get_record('redaction_ai_evaluations', ['id' => $eval->id]);
    $check("correction applied (found {$eval->status})", $eval->status === 'applied');
    $st = $status($a, $sub->id);
    $check('get_evaluation_status gives applied and a grade', is_array($st) && $st['status'] === 'applied' && $st['grade'] !== null);
    [$da, $ha] = $rendre($a);
    [$db, $hb] = $rendre($b);
    $check('after correction: same grade and state for both members', $ha === $hb && $da['hasnote'] && !$da['valide'] && $da['restants'] === 4);
    $check('after correction: orange banner, grade out of 20, comments with the subject title', strpos($ha, 'mod_redaction-se-ko') !== false
        && strpos($ha, '/20') !== false && strpos($ha, 'Tu as vu la roue.') !== false && strpos($ha, 'Il manque le role du moteur.') === false && strpos($ha, 'Systeme') !== false);
    $check('after correction: still editable, button usable', !$da['pending'] && !$da['disabled'] && strpos($ha, 'readonly') === false);
    $enregistrer('3-note-sous-le-seuil', $ha);

    // Failure: correction pending, attempt given back.
    $save($a, $s1[1]->id, 'Le moteur pousse le bateau');
    $r = $submit($b);
    $check('second attempt counted', is_array($r) && $r['success'] && (int) $DB->get_field('redaction_submission', 'training_count', ['id' => $sub->id]) === 2);
    retirer_taches($sub->id);
    $sim->reponses = [new \RuntimeException('Provider down')];
    ai_evaluator::process_evaluation((int) $r['evaluationid']);
    [$da, $ha] = $rendre($a);
    $check('failed: attempt given back, "correction en attente" shown, grade kept', $da['echec'] && !$da['pending'] && $da['restants'] === 4
        && $da['hasnote'] && strpos($ha, 'Correction en attente') !== false);
    $enregistrer('4-correction-en-attente', $ha);

    // Validated.
    $r = $submit($a);
    retirer_taches($sub->id);
    $sim->reponses = [ia(18, 'Parfait.'), ia(17, 'Presque parfait.')];
    ai_evaluator::process_evaluation((int) $r['evaluationid']);
    [$da, $ha] = $rendre($b);
    $check('validated: blue banner with the word, grade kept as the best', $da['valide'] && strpos($ha, 'mod_redaction-se-ok') !== false
        && strpos($ha, 'Niveau fragile validé') !== false);
    $check('validated: text stays editable while attempts remain', !$da['disabled'] && $da['restants'] === 3 && strpos($ha, 'readonly') === false);
    $enregistrer('5-valide', $ha);

    // A member who joins after the best grade has no grade and sees no success.
    $late = user_create_user((object) [
        'username' => 'verifecrl' . $now, 'firstname' => 'EleveL', 'lastname' => 'TestL',
        'email' => "verifecrl{$now}@example.invalid", 'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
    ], false, false);
    $users['L'] = $late;
    enrol_try_internal_enrol($course->id, $late, $studentrole);
    groups_add_member($g1, $late);
    [$dl, $hl] = $rendre($late);
    $check('late joiner: no grade, no validated banner, no false success', !$dl['hasnote'] && !$dl['valide']
        && strpos($hl, 'mod_redaction-se-ko') !== false && strpos($hl, 'Niveau fragile validé') === false);
    [$db2] = $rendre($b);
    $check('the original member keeps the validated state', $db2['valide'] && $db2['hasnote']);
    $check('the group comments stay shared with the late joiner', $dl['hascommentaires']);
    $enregistrer('7-arrive-apres-la-note', $hl);

    // No attempt left.
    $DB->set_field('redaction_submission', 'training_count', 5, ['id' => $sub->id]);
    [$da, $ha] = $rendre($a);
    $check('no attempt left: button disabled with the reason', $da['disabled'] && $da['restants'] === 0
        && strpos($ha, get_string('training_error_max_attempts_reached', 'mod_redaction')) !== false);
    $enregistrer('6-plus-d-essai', $ha);
    $r = $submit($a);
    $check('no attempt left: submit refused', is_array($r) && !$r['success']);

    // Registration and strings.
    foreach (['mod_redaction_save_sujet_reponse', 'mod_redaction_submit_sujets'] as $nom) {
        $check("service {$nom} is registered", $DB->record_exists('external_functions', ['name' => $nom]));
    }
    $sources = file_get_contents($CFG->dirroot . '/mod/redaction/templates/sujets_eleve.mustache') . file_get_contents($CFG->dirroot . '/mod/redaction/amd/src/sujets.js');
    preg_match_all('/(sujets?_[a-z_]+)(?:,\s*mod_redaction|\',\s*component)/', $sources, $m1);
    preg_match_all("/key: '([a-z_]+)'/", $sources, $m2);
    $manquantes = [];
    foreach (array_unique(array_merge($m1[1], $m2[1])) as $cle) {
        foreach (['en', 'fr'] as $langue) {
            if (!get_string_manager()->string_exists($cle, 'mod_redaction') ||
                    !isset(get_string_manager()->load_component_strings('mod_redaction', $langue)[$cle])) {
                $manquantes[] = "{$langue}:{$cle}";
            }
        }
    }
    $check('every string of the template and script exists in en and fr (' . implode(',', $manquantes) . ')', !$manquantes);
} finally {
    ai_evaluator::$fournisseurtest = null;
    force_current_language('');
    \core\session\manager::set_user(get_admin());
    if ($course) {
        ob_start();
        delete_course($course, false);
        ob_end_clean();
    }
    foreach ($users as $uid) {
        delete_user($DB->get_record('user', ['id' => $uid]));
        $DB->delete_records('user', ['id' => $uid]);
    }
}

if ($rids) {
    [$insql, $inparams] = $DB->get_in_or_equal($rids);
    $reste = $DB->count_records_select('redaction', "id $insql", $inparams)
        + $DB->count_records_select('redaction_submission', "redactionid $insql", $inparams)
        + $DB->count_records_select('redaction_ai_evaluations', "redactionid $insql", $inparams)
        + $DB->count_records_select('redaction_sujet', "redactionid $insql", $inparams);
    $check("nothing left behind (found {$reste})", $reste === 0);
}

echo $failures ? "\n{$failures} failure(s)\n" : "\nAll checks passed\n";
exit($failures ? 1 : 0);
