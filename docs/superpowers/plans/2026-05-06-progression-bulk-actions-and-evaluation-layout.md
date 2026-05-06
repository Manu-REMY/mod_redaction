# Tableau progression : actions groupées + nouvelle mise en page de l'évaluation IA

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre à l'enseignant de réévaluer / déverrouiller plusieurs élèves d'un coup depuis le tableau de progression, et alléger la page d'évaluation IA en passant à un layout 2 colonnes.

**Architecture:** Côté tableau de progression : checkbox column + action bar Mustache, sélection JS, modale de confirmation `core/modal_save_cancel`, deux external services (`bulk_evaluate` existant, `bulk_unlock` nouveau). Côté évaluation IA : refactor de `ai_evaluation.mustache` avec deux nouveaux wrappers grid (`.mod_redaction-ai-row-grade-criteria`, `.mod_redaction-ai-row-keywords-suggestions`) et suppression des blocs `overall_appreciation` + `strengths/weaknesses`.

**Tech Stack:** PHP 8.1 (Moodle 4.5+), Mustache, AMD JS, Moodle External Services API, `core/modal_factory`, `core/notification`.

**Spec source:** `docs/superpowers/specs/2026-05-06-progression-bulk-actions-and-evaluation-layout-design.md`

---

## File map

### Section 1 — Tableau de progression

- **CREATE** `redaction/classes/external/bulk_unlock.php` — external service de déverrouillage en masse.
- **MODIFY** `redaction/classes/output/grading_overview_data.php` — exposer `lastname firstname`, ajouter `latest` (submission + status + hascontent), exposer `can_grade`.
- **MODIFY** `redaction/templates/grading_overview.mustache` — colonne checkbox, en-tête checkbox maître, action bar (compteur + 2 boutons).
- **MODIFY** `redaction/amd/src/grading_overview.js` — gestion sélection, modale, AJAX.
- **MODIFY** `redaction/amd/build/grading_overview.min.js` — recompilé.
- **MODIFY** `redaction/db/services.php` — déclarer `mod_redaction_bulk_unlock`.
- **MODIFY** `redaction/lang/en/redaction.php` — clés UI nouvelles (cf. spec §1.9).
- **MODIFY** `redaction/lang/fr/redaction.php` — traductions FR correspondantes.
- **MODIFY** `redaction/styles.css` — styles barre d'actions + checkboxes.
- **MODIFY** `redaction/version.php` — bump version.
- **CREATE** `redaction/tests/external/bulk_unlock_test.php` — PHPUnit pour `bulk_unlock`.

### Section 2 — Évaluation IA

- **MODIFY** `redaction/templates/ai_evaluation.mustache` — supprimer `overall_appreciation` + forces/faiblesses, ajouter wrappers grid, ouvrir par défaut commentaire général/mots-clés/conseils, retirer toggle des critères.
- **MODIFY** `redaction/styles.css` — nettoyer classes orphelines, ajouter wrappers grid + responsive fallback.

---

## Pré-requis

- Branche git propre. Travailler sur `main` (workflow actuel du projet d'après `git log`).
- `grunt` disponible depuis une installation Moodle pour recompiler l'AMD (la commande dans `CLAUDE.md` est `grunt amd --root=/mod/redaction`).

---

## Section 1 — Tableau de progression

### Task 1 : Bump version + ajouter clés i18n EN

**Files:**
- Modify: `redaction/version.php` (ligne 28)
- Modify: `redaction/lang/en/redaction.php` (en bas du fichier)

- [ ] **Step 1 : Bump version**

```php
// version.php — ligne 28
$plugin->version = 2026050603;  // YYYYMMDDXX format
```

(Nouveau numéro = ancien `2026050602` + 1.)

- [ ] **Step 2 : Ajouter les clés i18n en anglais**

À ajouter à la fin de `redaction/lang/en/redaction.php` (avant le `?>` final s'il existe, sinon à la suite des autres `$string[...]`) :

```php
// Bulk actions on the progression overview.
$string['overview_select_all'] = 'Select all students';
$string['overview_selection_count'] = '{$a} selected';
$string['overview_action_reevaluate'] = 'Re-evaluate';
$string['overview_action_unlock'] = 'Unlock';
$string['overview_confirm_reevaluate_title'] = 'Confirm re-evaluation';
$string['overview_confirm_unlock_title'] = 'Confirm unlock';
$string['overview_confirm_affected'] = 'Affected ({$a}):';
$string['overview_confirm_ignored'] = 'Ignored ({$a}):';
$string['overview_confirm_button'] = 'Confirm';
$string['overview_skip_reason_nocontent'] = 'no content';
$string['overview_skip_reason_alreadyunlocked'] = 'already unlocked';
$string['overview_bulk_reevaluate_result'] = '{$a->queued} queued, {$a->skipped} skipped';
$string['overview_bulk_unlock_result'] = '{$a->unlocked} unlocked, {$a->skipped} skipped';
$string['overview_no_selection'] = 'No student selected';
```

- [ ] **Step 3 : Vérifier la syntaxe PHP**

Run: `php -l /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/lang/en/redaction.php && php -l /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/version.php`
Expected: `No syntax errors detected` pour les deux.

- [ ] **Step 4 : Commit**

```bash
git add redaction/version.php redaction/lang/en/redaction.php
git commit -m "lang(en): add bulk action strings for progress overview"
```

---

### Task 2 : Traduire en français

**Files:**
- Modify: `redaction/lang/fr/redaction.php`

- [ ] **Step 1 : Ajouter les mêmes clés en FR**

À ajouter à la fin de `redaction/lang/fr/redaction.php` (mêmes clés que Task 1 Step 2) :

```php
// Actions groupées sur le tableau de progression.
$string['overview_select_all'] = 'Tout sélectionner';
$string['overview_selection_count'] = '{$a} sélectionné(s)';
$string['overview_action_reevaluate'] = 'Réévaluer';
$string['overview_action_unlock'] = 'Déverrouiller';
$string['overview_confirm_reevaluate_title'] = 'Confirmer la réévaluation';
$string['overview_confirm_unlock_title'] = 'Confirmer le déverrouillage';
$string['overview_confirm_affected'] = 'Sera affecté(e) ({$a}) :';
$string['overview_confirm_ignored'] = 'Sera ignoré(e) ({$a}) :';
$string['overview_confirm_button'] = 'Confirmer';
$string['overview_skip_reason_nocontent'] = 'pas de contenu';
$string['overview_skip_reason_alreadyunlocked'] = 'déjà déverrouillée';
$string['overview_bulk_reevaluate_result'] = '{$a->queued} lancée(s), {$a->skipped} ignorée(s)';
$string['overview_bulk_unlock_result'] = '{$a->unlocked} déverrouillée(s), {$a->skipped} ignorée(s)';
$string['overview_no_selection'] = 'Aucun élève sélectionné';
```

- [ ] **Step 2 : Vérifier la syntaxe**

Run: `php -l /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/lang/fr/redaction.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3 : Vérifier l'iso EN/FR**

Run :

```bash
diff <(grep -oE "\\\$string\['[^']+'\]" /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/lang/en/redaction.php | sort -u) \
     <(grep -oE "\\\$string\['[^']+'\]" /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/lang/fr/redaction.php | sort -u)
```

Expected : aucune ligne commençant par `>` (la contrainte est : pas de clé FR sans EN). Des lignes `<` (clés EN sans FR) sont tolérées hors scope, mais pour les clés ajoutées dans cette tâche les deux fichiers doivent être synchros.

- [ ] **Step 4 : Commit**

```bash
git add redaction/lang/fr/redaction.php
git commit -m "lang(fr): translate bulk action strings for progress overview"
```

---

### Task 3 : External service `bulk_unlock`

**Files:**
- Create: `redaction/classes/external/bulk_unlock.php`
- Modify: `redaction/db/services.php`

- [ ] **Step 1 : Créer la classe external**

Crée `redaction/classes/external/bulk_unlock.php` avec exactement ce contenu :

```php
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
 * External function for bulk unlock of submissions.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;
use context_module;

/**
 * External function to unlock multiple submissions in one call.
 *
 * Mirrors the per-submission unlock logic in submit_action::execute (case 'unlock'):
 * sets status from 1 (locked) back to 0 (draft) so the student can edit again.
 */
class bulk_unlock extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'submissionids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Submission ID'),
                'Array of submission IDs to unlock'
            ),
        ]);
    }

    /**
     * Bulk unlock submissions.
     *
     * @param int   $cmid          Course module ID
     * @param int[] $submissionids Submission IDs to unlock
     * @return array{success: bool, unlocked: int, skipped: int, errors: string[]}
     */
    public static function execute(int $cmid, array $submissionids): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'submissionids' => $submissionids,
        ]);

        $cm = get_coursemodule_from_id('redaction', $params['cmid'], 0, false, MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/redaction:grade', $context);

        $results = [
            'success' => true,
            'unlocked' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($params['submissionids'] as $submissionid) {
            $submission = $DB->get_record('redaction_submission', [
                'id' => (int) $submissionid,
                'redactionid' => $redaction->id,
            ]);

            if (!$submission) {
                $results['skipped']++;
                continue;
            }

            if ((int) $submission->status !== 1) {
                $results['skipped']++;
                continue;
            }

            $submission->status = 0;
            $submission->timemodified = time();
            try {
                if ($DB->update_record('redaction_submission', $submission)) {
                    $results['unlocked']++;
                } else {
                    $results['skipped']++;
                }
            } catch (\Exception $e) {
                $results['errors'][] = $e->getMessage();
                $results['skipped']++;
            }
        }

        return $results;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the operation succeeded'),
            'unlocked' => new external_value(PARAM_INT, 'Number of submissions unlocked'),
            'skipped' => new external_value(PARAM_INT, 'Number of submissions skipped'),
            'errors' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Error message'),
                'List of error messages',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
```

- [ ] **Step 2 : Vérifier la syntaxe PHP**

Run: `php -l /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/classes/external/bulk_unlock.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3 : Déclarer le service dans `db/services.php`**

Ouvre `redaction/db/services.php` et ajoute, **dans le tableau `$functions`, juste après l'entrée `mod_redaction_bulk_evaluate`** :

```php
'mod_redaction_bulk_unlock' => [
    'classname'    => 'mod_redaction\external\bulk_unlock',
    'methodname'   => 'execute',
    'description'  => 'Bulk unlock submissions',
    'type'         => 'write',
    'ajax'         => true,
    'capabilities' => 'mod/redaction:grade',
],
```

- [ ] **Step 4 : Vérifier la syntaxe PHP**

Run: `php -l /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/db/services.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5 : Commit**

```bash
git add redaction/classes/external/bulk_unlock.php redaction/db/services.php
git commit -m "feat(external): bulk_unlock service for batch unlocking submissions"
```

---

### Task 4 : Test PHPUnit pour `bulk_unlock`

**Files:**
- Create: `redaction/tests/external/bulk_unlock_test.php`

- [ ] **Step 1 : Créer le test**

Crée `redaction/tests/external/bulk_unlock_test.php` :

```php
<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * PHPUnit tests for the bulk_unlock external function.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/redaction/lib.php');

/**
 * @group mod_redaction
 */
final class bulk_unlock_test extends \advanced_testcase {

    public function test_unlocks_only_locked_submissions_belonging_to_the_module(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $student2 = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $redaction = $this->getDataGenerator()->create_module('redaction', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('redaction', $redaction->id);

        global $DB;

        // Locked submission for student1.
        $sub1 = (object)[
            'redactionid' => $redaction->id,
            'userid' => $student1->id,
            'groupid' => 0,
            'contenu' => 'Some content',
            'status' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $sub1->id = $DB->insert_record('redaction_submission', $sub1);

        // Draft submission for student2 (should be skipped).
        $sub2 = (object)[
            'redactionid' => $redaction->id,
            'userid' => $student2->id,
            'groupid' => 0,
            'contenu' => 'Draft content',
            'status' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $sub2->id = $DB->insert_record('redaction_submission', $sub2);

        $this->setUser($teacher);

        $result = bulk_unlock::execute($cm->id, [$sub1->id, $sub2->id]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['unlocked']);
        $this->assertSame(1, $result['skipped']);

        $this->assertSame(0, (int) $DB->get_field('redaction_submission', 'status', ['id' => $sub1->id]));
        $this->assertSame(0, (int) $DB->get_field('redaction_submission', 'status', ['id' => $sub2->id]));
    }

    public function test_requires_grade_capability(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $redaction = $this->getDataGenerator()->create_module('redaction', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('redaction', $redaction->id);

        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        bulk_unlock::execute($cm->id, [0]);
    }

    public function test_skips_submissions_from_other_redaction(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $r1 = $this->getDataGenerator()->create_module('redaction', ['course' => $course->id]);
        $r2 = $this->getDataGenerator()->create_module('redaction', ['course' => $course->id]);
        $cm1 = get_coursemodule_from_instance('redaction', $r1->id);

        global $DB;

        $foreignsub = (object)[
            'redactionid' => $r2->id,
            'userid' => $student->id,
            'groupid' => 0,
            'contenu' => 'x',
            'status' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $foreignsub->id = $DB->insert_record('redaction_submission', $foreignsub);

        $this->setUser($teacher);

        $result = bulk_unlock::execute($cm1->id, [$foreignsub->id]);

        $this->assertSame(0, $result['unlocked']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, (int) $DB->get_field('redaction_submission', 'status', ['id' => $foreignsub->id]));
    }
}
```

- [ ] **Step 2 : Vérifier la syntaxe**

Run: `php -l /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/tests/external/bulk_unlock_test.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3 : Lancer le test (si Moodle PHPUnit configuré)**

Si tu as une instance Moodle avec PHPUnit configurée :

```bash
cd /path/to/moodle
php admin/tool/phpunit/cli/init.php  # une seule fois
vendor/bin/phpunit --filter bulk_unlock_test mod/redaction/tests/external/bulk_unlock_test.php
```

Expected : 3 tests passent.

Si pas d'infra PHPUnit disponible localement, **note-le** dans le commit message ; on validera côté CI Moodle.

- [ ] **Step 4 : Commit**

```bash
git add redaction/tests/external/bulk_unlock_test.php
git commit -m "test(external): cover bulk_unlock happy path, capability, scope"
```

---

### Task 5 : Renderable — exposer `latest`, `can_grade`, format nom

**Files:**
- Modify: `redaction/classes/output/grading_overview_data.php`

- [ ] **Step 1 : Ajouter `can_grade` au contexte exporté**

Dans `export_for_template()`, juste avant le `return [`, ajouter :

```php
$canGrade = has_capability('mod/redaction:grade', \context_module::instance($this->cmid));
```

Puis ajouter `'can_grade' => $canGrade,` dans le tableau retourné.

- [ ] **Step 2 : Modifier `build_student_rows()` — nom + latest**

Remplacer la boucle `foreach ($users as $user)` (à partir de `// Multiple submissions per user: keep the first one we see (oldest).`) par une logique qui collecte aussi la dernière soumission :

```php
$firstSubByUser = [];   // oldest, used to build the column timeline
$lastSubByUser = [];    // latest, used for bulk actions
$submissionIds = [];
foreach ($submissions as $s) {
    if (!isset($firstSubByUser[$s->userid])) {
        $firstSubByUser[$s->userid] = $s;
    }
    $lastSubByUser[$s->userid] = $s; // overwritten in chrono order — last one wins
    $submissionIds[] = $s->id;
}

// Map first-submission id (kept for backward compat with build_cells via $sid).
$submissionByUser = [];
foreach ($firstSubByUser as $uid => $s) {
    $submissionByUser[$uid] = $s->id;
}

// Pre-fetch the contenu length to know if the latest submission has content.
$lastSubIds = array_map(static fn($s) => (int) $s->id, $lastSubByUser);
$lastContenus = [];
if (!empty($lastSubIds)) {
    [$insql2, $inparams2] = $DB->get_in_or_equal($lastSubIds, SQL_PARAMS_QM);
    $rows = $DB->get_records_sql(
        'SELECT id, contenu, status FROM {redaction_submission} WHERE id ' . $insql2,
        $inparams2
    );
    foreach ($rows as $r) {
        $lastContenus[(int) $r->id] = [
            'hascontent' => !empty(trim((string) $r->contenu)),
            'status' => (int) $r->status,
        ];
    }
}
```

Puis, dans la boucle finale `foreach ($users as $user)`, remplacer le `'name' => fullname($user)` et adjacents par :

```php
$lastSub = $lastSubByUser[$user->id] ?? null;
$lastInfo = ($lastSub && isset($lastContenus[(int) $lastSub->id])) ? $lastContenus[(int) $lastSub->id] : null;

$rows[] = [
    'name' => trim($user->lastname . ' ' . $user->firstname),
    'nameurl' => $this->build_detail_url((int) $user->id),
    'has_nameurl' => true,
    'cells' => $this->build_cells($evals, (int) $user->id),
    'latest' => [
        'has' => $lastSub !== null,
        'submissionid' => $lastSub ? (int) $lastSub->id : 0,
        'status' => $lastInfo['status'] ?? 0,
        'hascontent' => $lastInfo['hascontent'] ?? false,
        'itemid' => (int) $user->id,
    ],
];
```

- [ ] **Step 3 : Modifier `build_group_rows()` — latest pour les groupes**

Appliquer la même logique de `firstSubByGroup` / `lastSubByGroup` / `lastContenus`. Le `name` reste `format_string($group->name)`. Le `latest.itemid` est le `groupid`.

Code complet à insérer dans `build_group_rows()` (remplace la section `$submissionByGroup = []` ... `$evalsBySubmission = ...`) :

```php
$firstSubByGroup = [];
$lastSubByGroup = [];
$submissionIds = [];
foreach ($submissions as $s) {
    if (!isset($firstSubByGroup[$s->groupid])) {
        $firstSubByGroup[$s->groupid] = $s;
    }
    $lastSubByGroup[$s->groupid] = $s;
    $submissionIds[] = $s->id;
}
$submissionByGroup = [];
foreach ($firstSubByGroup as $gid => $s) {
    $submissionByGroup[$gid] = $s->id;
}

$lastSubIds = array_map(static fn($s) => (int) $s->id, $lastSubByGroup);
$lastContenus = [];
if (!empty($lastSubIds)) {
    [$insql2, $inparams2] = $DB->get_in_or_equal($lastSubIds, SQL_PARAMS_QM);
    $rowsr = $DB->get_records_sql(
        'SELECT id, contenu, status FROM {redaction_submission} WHERE id ' . $insql2,
        $inparams2
    );
    foreach ($rowsr as $r) {
        $lastContenus[(int) $r->id] = [
            'hascontent' => !empty(trim((string) $r->contenu)),
            'status' => (int) $r->status,
        ];
    }
}

$evalsBySubmission = $this->load_evaluations_by_submission($submissionIds);
```

Puis remplacer la construction de la ligne par :

```php
$lastSub = $lastSubByGroup[$group->id] ?? null;
$lastInfo = ($lastSub && isset($lastContenus[(int) $lastSub->id])) ? $lastContenus[(int) $lastSub->id] : null;

$rows[] = [
    'name' => format_string($group->name),
    'nameurl' => $this->build_detail_url((int) $group->id),
    'has_nameurl' => true,
    'cells' => $this->build_cells($evals, (int) $group->id),
    'latest' => [
        'has' => $lastSub !== null,
        'submissionid' => $lastSub ? (int) $lastSub->id : 0,
        'status' => $lastInfo['status'] ?? 0,
        'hascontent' => $lastInfo['hascontent'] ?? false,
        'itemid' => (int) $group->id,
    ],
];
```

- [ ] **Step 4 : Vérifier la syntaxe**

Run: `php -l /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/classes/output/grading_overview_data.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5 : Commit**

```bash
git add redaction/classes/output/grading_overview_data.php
git commit -m "feat(overview): expose latest submission + can_grade, swap to lastname-firstname"
```

---

### Task 6 : Template — colonne checkbox + barre d'actions

**Files:**
- Modify: `redaction/templates/grading_overview.mustache`

- [ ] **Step 1 : Réécrire le template**

Remplace **intégralement** le contenu de `redaction/templates/grading_overview.mustache` par :

```mustache
{{!
    @template mod_redaction/grading_overview

    Progression overview table: rows = students/groups, columns = attempts.

    Context variables required:
    * cmid
    * isgroupmode
    * has_rows
    * can_grade
    * headers - array of {label}
    * rows - array of {name, nameurl, has_nameurl, cells, latest}
        cells - array of cell descriptors
        latest - {has, submissionid, status, hascontent, itemid}
}}
<div class="mod_redaction-overview-wrapper">
    {{#has_rows}}
        {{#can_grade}}
        <div class="mod_redaction-overview-actionbar">
            <span class="mod_redaction-overview-selcount" data-count="0">
                {{#str}}overview_selection_count, redaction, 0{{/str}}
            </span>
            <div class="mod_redaction-overview-actions">
                <button type="button"
                        class="btn btn-secondary mod_redaction-overview-action-reevaluate"
                        disabled>
                    &#128260; {{#str}}overview_action_reevaluate, redaction{{/str}}
                </button>
                <button type="button"
                        class="btn btn-secondary mod_redaction-overview-action-unlock"
                        disabled>
                    &#128275; {{#str}}overview_action_unlock, redaction{{/str}}
                </button>
            </div>
        </div>
        {{/can_grade}}

        <table class="mod_redaction-overview-table table table-bordered table-sm" data-sort-name="">
            <thead>
                <tr>
                    {{#can_grade}}
                    <th class="mod_redaction-overview-checkbox-col">
                        <input type="checkbox"
                               class="mod_redaction-overview-checkall"
                               aria-label="{{#str}}overview_select_all, redaction{{/str}}">
                    </th>
                    {{/can_grade}}
                    <th class="mod_redaction-overview-name-col" data-sort="name">
                        {{#isgroupmode}}{{#str}}group, group{{/str}}{{/isgroupmode}}
                        {{^isgroupmode}}{{#str}}overview_student_col, redaction{{/str}}{{/isgroupmode}}
                    </th>
                    {{#headers}}
                        <th class="text-center">{{label}}</th>
                    {{/headers}}
                </tr>
            </thead>
            <tbody>
                {{#rows}}
                    <tr>
                        {{#can_grade}}
                        <td class="mod_redaction-overview-checkbox-col">
                            {{#latest.has}}
                            <input type="checkbox"
                                   class="mod_redaction-overview-rowcheck"
                                   data-itemid="{{latest.itemid}}"
                                   data-submissionid="{{latest.submissionid}}"
                                   data-status="{{latest.status}}"
                                   data-hascontent="{{#latest.hascontent}}1{{/latest.hascontent}}{{^latest.hascontent}}0{{/latest.hascontent}}"
                                   data-name="{{name}}">
                            {{/latest.has}}
                        </td>
                        {{/can_grade}}
                        <td class="mod_redaction-overview-name">
                            {{#has_nameurl}}<a href="{{nameurl}}" class="mod_redaction-overview-namelink">{{name}}</a>{{/has_nameurl}}
                            {{^has_nameurl}}{{name}}{{/has_nameurl}}
                        </td>
                        {{#cells}}
                            {{#hasattempt}}
                                <td class="mod_redaction-overview-cell mod_redaction-overview-cell-{{levelclass}} {{#is_latest}}mod_redaction-overview-cell-latest{{/is_latest}}">
                                    <a href="{{detailurl}}" class="mod_redaction-overview-link">
                                        <span class="mod_redaction-overview-grade">{{grade}}</span>
                                        <span class="mod_redaction-overview-status">{{{statusicon}}}</span>
                                    </a>
                                    {{#is_latest}}
                                        {{#has_criteria}}
                                            <div class="mod_redaction-overview-minibars">
                                                {{#criteria}}
                                                    <div class="mod_redaction-overview-minibar mod_redaction-overview-minibar-{{levelclass}}"
                                                         title="{{name}}: {{score}}/{{max}}"
                                                         style="width: {{percentage}}%"></div>
                                                {{/criteria}}
                                            </div>
                                        {{/has_criteria}}
                                    {{/is_latest}}
                                </td>
                            {{/hasattempt}}
                            {{^hasattempt}}
                                <td class="mod_redaction-overview-cell mod_redaction-overview-cell-empty">&mdash;</td>
                            {{/hasattempt}}
                        {{/cells}}
                    </tr>
                {{/rows}}
            </tbody>
        </table>
    {{/has_rows}}
    {{^has_rows}}
        <div class="alert alert-info">{{#str}}overview_no_data, redaction{{/str}}</div>
    {{/has_rows}}
</div>
```

> **Note pour l'attribut `style`** : ce `style="width: {{percentage}}%"` existait déjà dans le template d'origine ; on le conserve par cohérence (un sélecteur CSS pur ne peut pas exprimer un pourcentage dynamique). Si ce point est rouvert au moment de la review Moodle Plugin Directory, traite-le séparément.

- [ ] **Step 2 : Commit**

```bash
git add redaction/templates/grading_overview.mustache
git commit -m "feat(overview): checkbox column + actions bar in template"
```

---

### Task 7 : CSS — barre d'actions, checkboxes, états

**Files:**
- Modify: `redaction/styles.css`

- [ ] **Step 1 : Ajouter les nouveaux blocs CSS**

À la fin de `redaction/styles.css`, ajoute :

```css
/* ===== Progression overview — bulk actions ===== */

.mod_redaction-overview-actionbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    padding: 8px 12px;
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
}

.mod_redaction-overview-selcount {
    font-weight: 600;
    color: #444;
}

.mod_redaction-overview-actions {
    display: flex;
    gap: 8px;
}

.mod_redaction-overview-actions button[disabled] {
    opacity: 0.5;
    cursor: not-allowed;
}

.mod_redaction-overview-checkbox-col {
    width: 36px;
    text-align: center;
    vertical-align: middle;
}

.mod_redaction-overview-checkbox-col input[type="checkbox"] {
    cursor: pointer;
}
```

- [ ] **Step 2 : Vérifier visuellement (manuel)**

Charger la page `view.php?id=<cm>&page=grading&tab=overview` dans un navigateur, vérifier :
- Barre d'actions visible avec compteur "0 sélectionné(s)" et 2 boutons grisés.
- Colonne checkbox à gauche du nom.
- Pas de débordement sur écran 1280px.

- [ ] **Step 3 : Commit**

```bash
git add redaction/styles.css
git commit -m "style(overview): bulk action bar + checkbox column styles"
```

---

### Task 8 : JS — sélection (master + lignes) + état boutons

**Files:**
- Modify: `redaction/amd/src/grading_overview.js`

- [ ] **Step 1 : Réécrire le module AMD avec la sélection**

Remplace **intégralement** le contenu de `redaction/amd/src/grading_overview.js` par :

```javascript
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Progression overview interactions: column sort + bulk-action selection.
 *
 * @module     mod_redaction/grading_overview
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'core/ajax',
    'core/notification',
    'core/str',
    'core/modal_factory',
    'core/modal_events',
], function(Ajax, Notification, Str, ModalFactory, ModalEvents) {

    var SEL = {
        TABLE: '.mod_redaction-overview-table',
        ACTIONBAR: '.mod_redaction-overview-actionbar',
        CHECKALL: '.mod_redaction-overview-checkall',
        ROWCHECK: '.mod_redaction-overview-rowcheck',
        SELCOUNT: '.mod_redaction-overview-selcount',
        BTN_REEVAL: '.mod_redaction-overview-action-reevaluate',
        BTN_UNLOCK: '.mod_redaction-overview-action-unlock',
    };

    var state = {
        cmid: 0,
    };

    function sortByName(table) {
        var tbody = table.tBodies[0];
        var rows = Array.prototype.slice.call(tbody.rows);
        var asc = table.dataset.sortName !== 'asc';
        // Name cell index depends on whether the checkbox column is present.
        var hasCheckCol = !!table.querySelector('thead ' + SEL.CHECKALL);
        var nameIndex = hasCheckCol ? 1 : 0;
        rows.sort(function(a, b) {
            var an = a.cells[nameIndex].textContent.trim().toLowerCase();
            var bn = b.cells[nameIndex].textContent.trim().toLowerCase();
            if (an < bn) { return asc ? -1 : 1; }
            if (an > bn) { return asc ? 1 : -1; }
            return 0;
        });
        rows.forEach(function(row) { tbody.appendChild(row); });
        table.dataset.sortName = asc ? 'asc' : 'desc';
    }

    function selectedChecks() {
        return Array.prototype.slice.call(
            document.querySelectorAll(SEL.ROWCHECK + ':checked')
        );
    }

    function refreshSelectionUI() {
        var count = selectedChecks().length;
        var counter = document.querySelector(SEL.SELCOUNT);
        var reBtn = document.querySelector(SEL.BTN_REEVAL);
        var unlBtn = document.querySelector(SEL.BTN_UNLOCK);
        if (counter) {
            Str.get_string('overview_selection_count', 'mod_redaction', count)
                .then(function(s) { counter.textContent = s; return s; })
                .catch(Notification.exception);
        }
        if (reBtn) { reBtn.disabled = count === 0; }
        if (unlBtn) { unlBtn.disabled = count === 0; }
    }

    function bindCheckall() {
        var master = document.querySelector(SEL.CHECKALL);
        if (!master) { return; }
        master.addEventListener('change', function() {
            var checks = document.querySelectorAll(SEL.ROWCHECK);
            checks.forEach(function(c) { c.checked = master.checked; });
            refreshSelectionUI();
        });
    }

    function bindRowChecks() {
        var checks = document.querySelectorAll(SEL.ROWCHECK);
        checks.forEach(function(c) {
            c.addEventListener('change', refreshSelectionUI);
        });
    }

    return {
        init: function(opts) {
            opts = opts || {};
            state.cmid = parseInt(opts.cmid, 10) || 0;

            var table = document.querySelector(SEL.TABLE);
            if (!table) { return; }

            var nameHeader = table.querySelector('th[data-sort="name"]');
            if (nameHeader) {
                nameHeader.style.cursor = 'pointer';
                nameHeader.addEventListener('click', function() {
                    sortByName(table);
                });
            }

            bindCheckall();
            bindRowChecks();
            refreshSelectionUI();

            // Action bindings — see Task 9 / Task 10 for the modal + AJAX glue.
            // require(['mod_redaction/grading_overview_actions'], ...) is *not*
            // used here: we keep everything in this module to minimise files.
            require(['mod_redaction/grading_overview'], function() {});
        },
    };
});
```

- [ ] **Step 2 : Commit (étape intermédiaire — sélection seule, sans actions)**

```bash
git add redaction/amd/src/grading_overview.js
git commit -m "feat(overview): JS selection state (checkall, row checks, counter)"
```

---

### Task 9 : JS — modale de confirmation

**Files:**
- Modify: `redaction/amd/src/grading_overview.js`

- [ ] **Step 1 : Ajouter la logique modale**

Dans `redaction/amd/src/grading_overview.js`, **avant** le bloc `return { init: ... }` final, insère ces fonctions :

```javascript
function buildSummaryHtml(affected, ignored, affectedTitle, ignoredTitle) {
    var html = '';
    html += '<div class="mod_redaction-overview-confirm">';
    html += '<h6 class="mod_redaction-overview-confirm-title">' + affectedTitle + '</h6>';
    if (affected.length) {
        html += '<ul>';
        affected.forEach(function(a) {
            html += '<li>' + escapeHtml(a.name) + '</li>';
        });
        html += '</ul>';
    } else {
        html += '<p><em>—</em></p>';
    }
    if (ignored.length) {
        html += '<h6 class="mod_redaction-overview-confirm-title">' + ignoredTitle + '</h6>';
        html += '<ul>';
        ignored.forEach(function(i) {
            html += '<li>' + escapeHtml(i.name) + ' <small>(' + escapeHtml(i.reason) + ')</small></li>';
        });
        html += '</ul>';
    }
    html += '</div>';
    return html;
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function(c) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
    });
}

/**
 * Partition the selected checkboxes into "affected" / "ignored" lists
 * for a given action.
 *
 * @param {string} action 'reevaluate' or 'unlock'
 * @returns {Promise<{affected: Array, ignored: Array}>}
 */
function partitionForAction(action) {
    var checks = selectedChecks();
    var affected = [];
    var ignored = [];

    return Str.get_strings([
        {key: 'overview_skip_reason_nocontent', component: 'mod_redaction'},
        {key: 'overview_skip_reason_alreadyunlocked', component: 'mod_redaction'},
    ]).then(function(strs) {
        var reasonNoContent = strs[0];
        var reasonAlreadyUnlocked = strs[1];

        checks.forEach(function(c) {
            var name = c.dataset.name || '';
            var subid = parseInt(c.dataset.submissionid, 10) || 0;
            var status = parseInt(c.dataset.status, 10) || 0;
            var hascontent = c.dataset.hascontent === '1';

            if (action === 'reevaluate') {
                if (hascontent) {
                    affected.push({name: name, submissionid: subid});
                } else {
                    ignored.push({name: name, reason: reasonNoContent});
                }
            } else if (action === 'unlock') {
                if (status === 1) {
                    affected.push({name: name, submissionid: subid});
                } else {
                    ignored.push({name: name, reason: reasonAlreadyUnlocked});
                }
            }
        });
        return {affected: affected, ignored: ignored};
    });
}

/**
 * Open the confirmation modal for a given action and resolve the promise
 * with `true` if the user clicked Confirm, `false` if they cancelled.
 */
function confirmAction(action) {
    var titleKey = action === 'reevaluate'
        ? 'overview_confirm_reevaluate_title'
        : 'overview_confirm_unlock_title';

    return Promise.all([
        partitionForAction(action),
        Str.get_strings([
            {key: titleKey, component: 'mod_redaction'},
            {key: 'overview_confirm_button', component: 'mod_redaction'},
        ]),
    ]).then(function(payload) {
        var parts = payload[0];
        var strs = payload[1];
        var title = strs[0];
        var confirmLabel = strs[1];

        return Str.get_strings([
            {key: 'overview_confirm_affected', component: 'mod_redaction', param: parts.affected.length},
            {key: 'overview_confirm_ignored', component: 'mod_redaction', param: parts.ignored.length},
        ]).then(function(headers) {
            var html = buildSummaryHtml(parts.affected, parts.ignored, headers[0], headers[1]);

            return ModalFactory.create({
                type: ModalFactory.types.SAVE_CANCEL,
                title: title,
                body: html,
            }).then(function(modal) {
                modal.setSaveButtonText(confirmLabel);
                return new Promise(function(resolve) {
                    modal.getRoot().on(ModalEvents.save, function() {
                        resolve({confirmed: true, parts: parts});
                    });
                    modal.getRoot().on(ModalEvents.hidden, function() {
                        modal.destroy();
                    });
                    modal.getRoot().on(ModalEvents.cancel, function() {
                        resolve({confirmed: false, parts: parts});
                    });
                    modal.show();
                });
            });
        });
    });
}
```

- [ ] **Step 2 : Vérifier visuellement (manuel)**

Charger la page, cocher 1-2 lignes, et après Task 10 (binding boutons) tester l'ouverture de la modale.

- [ ] **Step 3 : Commit**

```bash
git add redaction/amd/src/grading_overview.js
git commit -m "feat(overview): JS confirmation modal building blocks"
```

---

### Task 10 : JS — appels AJAX `bulk_evaluate` / `bulk_unlock` + binding boutons

**Files:**
- Modify: `redaction/amd/src/grading_overview.js`

- [ ] **Step 1 : Ajouter les handlers d'action**

Toujours dans `redaction/amd/src/grading_overview.js`, **avant** le `return { init: ... }`, ajoute :

```javascript
function callBulkEvaluate(submissionIds) {
    return Ajax.call([{
        methodname: 'mod_redaction_bulk_evaluate',
        args: {cmid: state.cmid, submissionids: submissionIds},
    }])[0];
}

function callBulkUnlock(submissionIds) {
    return Ajax.call([{
        methodname: 'mod_redaction_bulk_unlock',
        args: {cmid: state.cmid, submissionids: submissionIds},
    }])[0];
}

function notifySuccess(action, response) {
    var key = action === 'reevaluate'
        ? 'overview_bulk_reevaluate_result'
        : 'overview_bulk_unlock_result';
    var payload = action === 'reevaluate'
        ? {queued: response.queued, skipped: response.skipped}
        : {unlocked: response.unlocked, skipped: response.skipped};
    return Str.get_string(key, 'mod_redaction', payload).then(function(msg) {
        Notification.addNotification({message: msg, type: 'info'});
        return msg;
    });
}

function runAction(action) {
    confirmAction(action).then(function(out) {
        if (!out.confirmed || !out.parts.affected.length) {
            return null;
        }
        var ids = out.parts.affected.map(function(a) { return a.submissionid; });
        var promise = action === 'reevaluate'
            ? callBulkEvaluate(ids)
            : callBulkUnlock(ids);
        return promise.then(function(resp) {
            return notifySuccess(action, resp).then(function() {
                window.location.reload();
            });
        });
    }).catch(Notification.exception);
}

function bindActionButtons() {
    var reBtn = document.querySelector(SEL.BTN_REEVAL);
    var unlBtn = document.querySelector(SEL.BTN_UNLOCK);
    if (reBtn) {
        reBtn.addEventListener('click', function() { runAction('reevaluate'); });
    }
    if (unlBtn) {
        unlBtn.addEventListener('click', function() { runAction('unlock'); });
    }
}
```

- [ ] **Step 2 : Appeler `bindActionButtons()` dans `init`**

Dans le bloc `return { init: function(opts) { ... } }`, après `refreshSelectionUI();`, ajoute la ligne :

```javascript
            bindActionButtons();
```

Et retire la ligne `require(['mod_redaction/grading_overview'], function() {});` qui était un placeholder dans Task 8.

- [ ] **Step 3 : Vérifier la syntaxe JS (à l'œil)**

Aucun outil JS n'est imposé par le projet. Relire le fichier : pas de point-virgule manquant, pas de virgule pendante.

- [ ] **Step 4 : Recompiler l'AMD**

Depuis une installation Moodle :

```bash
cd /path/to/moodle
grunt amd --root=/mod/redaction
```

Cela régénère `redaction/amd/build/grading_overview.min.js`.

Si pas d'environnement grunt local, **note-le** dans le commit. La CI Moodle ou un environnement dédié pourra recompiler.

- [ ] **Step 5 : Test manuel**

1. Ouvrir le tableau de progression.
2. Cocher quelques élèves (mix avec/sans contenu, mix verrouillés/draft).
3. Cliquer **Réévaluer** → modale liste affectés/ignorés correctement → Confirmer → toast "X lancée(s), Y ignorée(s)" → page rechargée.
4. Cliquer **Déverrouiller** → idem.
5. Annuler → modale fermée, aucune requête.

- [ ] **Step 6 : Commit**

```bash
git add redaction/amd/src/grading_overview.js redaction/amd/build/grading_overview.min.js
git commit -m "feat(overview): wire Re-evaluate / Unlock to bulk external services"
```

---

## Section 2 — Évaluation IA : nouvelle mise en page

### Task 11 : Template — supprimer blocs redondants + nouveaux wrappers

**Files:**
- Modify: `redaction/templates/ai_evaluation.mustache`

- [ ] **Step 1 : Réécrire le template**

Dans `redaction/templates/ai_evaluation.mustache`, **dans le bloc `{{#iscompleted}} … {{/iscompleted}}`** (lignes 126-328 actuellement), remplacer **tout ce qui se trouve entre `{{#iscompleted}}` et `{{! Action Buttons …`** (donc les blocs : grade card, appreciation, strengths/weaknesses, criteria, keywords, suggestions, feedback) par la nouvelle structure suivante :

```mustache
            {{#iscompleted}}
                {{! Row 1: Grade card (left) + Criteria (right, always open) }}
                <div class="mod_redaction-ai-row-grade-criteria">
                    <div class="mod_redaction-ai-grade-card">
                        <div class="mod_redaction-ai-grade-ring mod_redaction-ai-grade-ring-{{gradelevel}}">
                            <div class="mod_redaction-ai-grade-value">{{grade}}<span class="mod_redaction-ai-grade-max">/20</span></div>
                        </div>
                        <div class="mod_redaction-ai-grade-level mod_redaction-ai-level-{{gradelevel}}">{{gradelevelstr}}</div>
                        <div class="mod_redaction-ai-grade-label">{{#str}}ai_grade, redaction{{/str}}</div>
                        <div class="mod_redaction-ai-confidence" title="{{#str}}ai_confidence, redaction{{/str}}: {{confidencepercent}}%">
                            <div class="mod_redaction-ai-confidence-bar">
                                <div class="mod_redaction-ai-confidence-fill mod_redaction-{{confidenceclass}}" style="width: {{confidencepercent}}%"></div>
                            </div>
                            <small class="mod_redaction-ai-confidence-label">{{#str}}ai_confidence, redaction{{/str}}: {{confidencepercent}}%</small>
                        </div>
                    </div>

                    {{#hascriteria}}
                    <div class="mod_redaction-ai-criteria-section mod_redaction-ai-criteria-static">
                        <h5 class="mod_redaction-ai-section-title">&#128202; {{#str}}ai_criteria_details, redaction{{/str}}</h5>
                        <div class="mod_redaction-ai-section-content">
                            {{#criteria}}
                                <div class="mod_redaction-ai-criterion mod_redaction-ai-criterion-{{scoreclass}}">
                                    <div class="mod_redaction-ai-criterion-header">
                                        <span class="mod_redaction-ai-criterion-name">{{name}}</span>
                                        <div class="mod_redaction-ai-criterion-meta">
                                            <span class="mod_redaction-ai-criterion-level-label mod_redaction-ai-level-{{scoreclass}}">{{levelstr}}</span>
                                            <span class="mod_redaction-ai-criterion-score mod_redaction-{{scoreclass}}">
                                                {{score}}/{{max}}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="mod_redaction-ai-criterion-progress">
                                        <div class="mod_redaction-ai-criterion-progress-bar mod_redaction-{{scoreclass}}"
                                             style="width: {{percentage}}%"></div>
                                    </div>
                                    {{#hascomment}}
                                        <div class="mod_redaction-ai-criterion-comment">
                                            {{{comment}}}
                                        </div>
                                    {{/hascomment}}
                                </div>
                            {{/criteria}}
                        </div>
                    </div>
                    {{/hascriteria}}
                </div>

                {{! Row 2: General feedback, full width, collapsible (open by default) }}
                {{#hasfeedback}}
                <div class="mod_redaction-ai-criteria-section mod_redaction-ai-section-open">
                    <div class="mod_redaction-ai-section-toggle" onclick="toggleSection(this)">
                        <h5 class="mod_redaction-ai-section-title">&#128172; {{#str}}ai_general_feedback, redaction{{/str}}</h5>
                        <span class="mod_redaction-toggle-icon">&#9660;</span>
                    </div>
                    <div class="mod_redaction-ai-section-content">
                        <div class="mod_redaction-ai-feedback">
                            {{{parsed_feedback}}}
                        </div>
                    </div>
                </div>
                {{/hasfeedback}}

                {{! Row 3: Keywords (left) + Suggestions (right), both collapsible, open by default }}
                <div class="mod_redaction-ai-row-keywords-suggestions">
                    {{#haskeywords}}
                    <div class="mod_redaction-ai-criteria-section mod_redaction-ai-section-open">
                        <div class="mod_redaction-ai-section-toggle" onclick="toggleSection(this)">
                            <h5 class="mod_redaction-ai-section-title">&#128273; {{#str}}ai_keywords, redaction{{/str}}</h5>
                            <span class="mod_redaction-toggle-icon">&#9660;</span>
                        </div>
                        <div class="mod_redaction-ai-section-content">
                            <div class="mod_redaction-ai-keywords-container">
                                {{#haskeywordsfound}}
                                <div class="mod_redaction-ai-keywords-group">
                                    <span class="mod_redaction-ai-keywords-label mod_redaction-ai-keywords-found-label">{{#str}}ai_keywords_found, redaction{{/str}}</span>
                                    <div class="mod_redaction-ai-keywords-tags">
                                        {{#keywords_found}}
                                        <span class="mod_redaction-ai-keyword-tag mod_redaction-ai-keyword-found">&#10003; {{word}}</span>
                                        {{/keywords_found}}
                                    </div>
                                </div>
                                {{/haskeywordsfound}}
                                {{#haskeywordsmissing}}
                                <div class="mod_redaction-ai-keywords-group">
                                    <span class="mod_redaction-ai-keywords-label mod_redaction-ai-keywords-missing-label">{{#str}}ai_keywords_missing, redaction{{/str}}</span>
                                    <div class="mod_redaction-ai-keywords-tags">
                                        {{#keywords_missing}}
                                        <span class="mod_redaction-ai-keyword-tag mod_redaction-ai-keyword-missing">&#10007; {{word}}</span>
                                        {{/keywords_missing}}
                                    </div>
                                </div>
                                {{/haskeywordsmissing}}
                            </div>
                        </div>
                    </div>
                    {{/haskeywords}}

                    {{#hassuggestions}}
                    <div class="mod_redaction-ai-criteria-section mod_redaction-ai-section-open">
                        <div class="mod_redaction-ai-section-toggle" onclick="toggleSection(this)">
                            <h5 class="mod_redaction-ai-section-title">&#128161; {{#str}}ai_suggestions, redaction{{/str}}</h5>
                            <span class="mod_redaction-toggle-icon">&#9660;</span>
                        </div>
                        <div class="mod_redaction-ai-section-content">
                            <ul class="mod_redaction-ai-suggestions-list">
                                {{#suggestions}}
                                <li class="mod_redaction-ai-suggestion-item">
                                    <span class="mod_redaction-ai-suggestion-icon">&#10148;</span>
                                    <span class="mod_redaction-ai-suggestion-text">{{{text}}}</span>
                                </li>
                                {{/suggestions}}
                            </ul>
                        </div>
                    </div>
                    {{/hassuggestions}}
                </div>
```

(Le bloc `{{! Action Buttons … }}` qui suit reste **inchangé**.)

> **Vérification clé** : la nouvelle classe `mod_redaction-ai-section-open` est ajoutée à chaque section qui doit être ouverte par défaut. Le CSS de Task 12 fera afficher le contenu d'office. Le toggle JS existant inverse l'état au clic (à ajuster en Task 13 si nécessaire).

- [ ] **Step 2 : Vérifier le rendu Mustache (manuel)**

Ouvrir une page d'évaluation IA dans le navigateur. Vérifier :
- Plus de bloc 💬 *"C'est un bon début…"*.
- Plus de blocs ✅ *Points forts* / 🛠️ *Axes d'amélioration*.
- Note ronde à gauche, critères à droite (sur écran large).
- Commentaire général entre les deux lignes, pleine largeur.
- Mots-clés à gauche, conseils à droite.

- [ ] **Step 3 : Commit**

```bash
git add redaction/templates/ai_evaluation.mustache
git commit -m "feat(ai-evaluation): drop redundant blocks, restructure to 2-column layout"
```

---

### Task 12 : CSS — wrappers grid + nettoyage

**Files:**
- Modify: `redaction/styles.css`

- [ ] **Step 1 : Nettoyer les classes orphelines**

Recherche et supprime de `redaction/styles.css` toutes les règles qui ciblent ces classes (elles ne sont plus utilisées) :

- `.mod_redaction-ai-appreciation`, `.mod_redaction-ai-appreciation-icon`, `.mod_redaction-ai-appreciation-text`
- `.mod_redaction-ai-strengths-weaknesses`
- `.mod_redaction-ai-sw-column`, `.mod_redaction-ai-sw-header`, `.mod_redaction-ai-sw-header-strengths`, `.mod_redaction-ai-sw-header-weaknesses`, `.mod_redaction-ai-sw-icon`, `.mod_redaction-ai-sw-list`, `.mod_redaction-ai-strengths-col`, `.mod_redaction-ai-weaknesses-col`, `.mod_redaction-ai-sw-full`

Exécute : `grep -n "mod_redaction-ai-\(appreciation\|sw-\|strengths\|weaknesses\)" /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/styles.css` pour les localiser. Supprime chaque bloc complet (depuis le sélecteur jusqu'au `}` fermant).

- [ ] **Step 2 : Ajouter les wrappers grid**

À la fin de `redaction/styles.css`, ajoute :

```css
/* ===== AI evaluation — 2-column layout ===== */

.mod_redaction-ai-row-grade-criteria {
    display: grid;
    grid-template-columns: minmax(280px, 320px) 1fr;
    gap: 1rem;
    align-items: start;
    margin-bottom: 1rem;
}

.mod_redaction-ai-row-keywords-suggestions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    align-items: start;
    margin-bottom: 1rem;
}

@media (max-width: 768px) {
    .mod_redaction-ai-row-grade-criteria,
    .mod_redaction-ai-row-keywords-suggestions {
        grid-template-columns: 1fr;
    }
}

/* Static (non-collapsible) version of the criteria section: hide the toggle row's chevron */
.mod_redaction-ai-criteria-static .mod_redaction-toggle-icon {
    display: none;
}
.mod_redaction-ai-criteria-static .mod_redaction-ai-section-toggle {
    cursor: default;
}

/* Sections marked as "open by default" — content visible without click */
.mod_redaction-ai-section-open .mod_redaction-ai-section-content {
    display: block;
}
.mod_redaction-ai-section-open .mod_redaction-toggle-icon {
    transform: rotate(180deg);
}
```

- [ ] **Step 3 : Vérifier visuellement**

Recharger une page d'évaluation IA :
- Layout 2 colonnes propre sur écran large.
- Empilage vertical sur < 768 px.
- Mots-clés / Conseils / Commentaire général ouverts au chargement (chevron pointant vers le haut).

- [ ] **Step 4 : Commit**

```bash
git add redaction/styles.css
git commit -m "style(ai-evaluation): grid wrappers + remove orphan strengths/appreciation styles"
```

---

### Task 13 : Vérifier le toggle JS reste cohérent avec `mod_redaction-ai-section-open`

**Files:**
- (potentiellement) Modify: la fonction `toggleSection(this)` (à localiser)

- [ ] **Step 1 : Localiser `toggleSection`**

Run :

```bash
grep -rn "function toggleSection\|toggleSection\s*=" /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/ --include="*.js" --include="*.mustache" --include="*.php"
```

Identifier où `toggleSection` est défini (probable : injecté inline dans un template ou défini dans un AMD module).

- [ ] **Step 2 : Adapter si nécessaire**

Si `toggleSection` lit/écrit l'état via une classe différente (ex. ajoute `.collapsed` plutôt que de retirer `.mod_redaction-ai-section-open`), adapter pour que le clic toggle correctement les sections marquées ouvertes :

- Cas A : `toggleSection` ajoute/retire la classe `collapsed` sur le parent. → Pas de modif nécessaire si on ajoute aussi en CSS le styling de `.collapsed > .mod_redaction-ai-section-content { display: none }`.
- Cas B : `toggleSection` ajoute/retire `.mod_redaction-ai-section-open`. → Pas de modif nécessaire.
- Cas C : `toggleSection` toggle simplement `style.display` du contenu. → Au premier clic l'état "ouvert par défaut" est inversé, c'est OK.

Choisir l'adaptation minimale qui rend le toggle cohérent. **Documenter dans le commit** le cas observé et le fix appliqué.

- [ ] **Step 3 : Test manuel**

Ouvrir la page d'évaluation IA, vérifier :
- Sections **Commentaire général**, **Mots-clés**, **Conseils** visibles au chargement.
- Cliquer le titre d'une section → repli.
- Re-cliquer → ré-affichage.

- [ ] **Step 4 : Commit (s'il y a eu modification)**

```bash
git add <fichiers modifiés>
git commit -m "fix(ai-evaluation): keep toggleSection consistent with default-open sections"
```

S'il n'y a pas eu de modif (toggle déjà compatible), pas de commit nécessaire.

---

### Task 14 : Test manuel global + bump version final

**Files:**
- (déjà bumpée Task 1)

- [ ] **Step 1 : Plan de test (issue de la spec)**

Section 1 — tableau progression :
- [ ] Tableau s'affiche avec **nom-prénom** (ex. "REMY Emmanuel") au lieu de "Emmanuel REMY".
- [ ] Checkbox maître coche/décoche toutes les lignes éligibles.
- [ ] Lignes sans soumission : **pas** de checkbox.
- [ ] Compteur de sélection se met à jour en temps réel.
- [ ] Boutons **Réévaluer** / **Déverrouiller** désactivés sans sélection.
- [ ] Modale de confirmation liste correctement affectés vs ignorés (raisons : *pas de contenu*, *déjà déverrouillée*).
- [ ] Confirmer Réévaluer → toast `X lancée(s), Y ignorée(s)` → page rechargée → file IA peuplée.
- [ ] Confirmer Déverrouiller → toast `X déverrouillée(s), Y ignorée(s)` → page rechargée → status passé à 0.
- [ ] Annuler ferme la modale, aucune requête envoyée.
- [ ] Mode groupe (`group_submission=1`) : actions appliquées au niveau groupe.
- [ ] Compte sans `mod/redaction:grade` (élève) : barre d'actions et checkboxes invisibles.

Section 2 — affichage évaluation IA :
- [ ] Bloc 💬 "C'est un bon début…" supprimé.
- [ ] Blocs Points forts / Axes d'amélioration supprimés.
- [ ] Note à gauche, critères à droite sur écran ≥ 1024 px.
- [ ] Commentaire général en pleine largeur entre les deux lignes.
- [ ] Mots-clés à gauche, Conseils à droite.
- [ ] Sur écran < 768 px : tout s'empile verticalement.
- [ ] Critères toujours visibles (plus de toggle).
- [ ] Commentaire général / Mots-clés / Conseils ouverts par défaut, repliables.
- [ ] Tentatives passées affichent la même structure (sans boutons Apply / Re-évaluer).

- [ ] **Step 2 : Si tout passe — pas d'autre modif requise**

Le bump version (Task 1) suffit. La build min du JS (Task 10) doit être à jour.

- [ ] **Step 3 : Optionnel — créer le ZIP de distribution**

```bash
cd /Volumes/DONNEES/Claude\ code/mod_redaction
zip -r redaction.zip redaction/ -x "redaction/.git/*"
```

---

## Récap des commits attendus

```
lang(en): add bulk action strings for progress overview
lang(fr): translate bulk action strings for progress overview
feat(external): bulk_unlock service for batch unlocking submissions
test(external): cover bulk_unlock happy path, capability, scope
feat(overview): expose latest submission + can_grade, swap to lastname-firstname
feat(overview): checkbox column + actions bar in template
style(overview): bulk action bar + checkbox column styles
feat(overview): JS selection state (checkall, row checks, counter)
feat(overview): JS confirmation modal building blocks
feat(overview): wire Re-evaluate / Unlock to bulk external services
feat(ai-evaluation): drop redundant blocks, restructure to 2-column layout
style(ai-evaluation): grid wrappers + remove orphan strengths/appreciation styles
fix(ai-evaluation): keep toggleSection consistent with default-open sections   ← optionnel
```

## Risques connus

- **Recompilation AMD** : Task 10 Step 4 dépend de `grunt`. Si l'environnement local ne l'a pas, fournir le build via la CI Moodle.
- **Toggle JS** : Task 13 reste exploratoire jusqu'à localisation de `toggleSection`. Le pire cas est un comportement inversé au premier clic (acceptable temporairement).
- **PHPUnit** : Task 4 nécessite une instance Moodle de test. Si indisponible, marquer le test comme "à valider en CI".

## Self-review

- **Couverture spec §1.1** (lastname firstname) → Task 5 Step 2.
- **Couverture spec §1.2** (checkbox column + data-attrs) → Task 6 Step 1.
- **Couverture spec §1.3** (latest dans renderable) → Task 5 Step 2-3.
- **Couverture spec §1.4** (action bar + can_grade gate) → Task 6 Step 1 (template) + Task 5 Step 1 (can_grade).
- **Couverture spec §1.5** (modale confirmation) → Task 9 + Task 10.
- **Couverture spec §1.6** (`bulk_unlock`) → Task 3 + Task 4.
- **Couverture spec §1.7** (réutilisation `bulk_evaluate`) → Task 10 Step 1 (`callBulkEvaluate`).
- **Couverture spec §1.8** (extension JS) → Tasks 8-10.
- **Couverture spec §1.9** (i18n) → Tasks 1-2.
- **Couverture spec §1.10** (CSS) → Task 7.
- **Couverture spec §2.1** (suppressions template) → Task 11.
- **Couverture spec §2.2-2.3** (nouvelle structure) → Task 11.
- **Couverture spec §2.4** (CSS grid) → Task 12.
- **Couverture spec §2.5** (inchangés) → Task 11 garde `pending`/`failed`/action buttons.

Pas de placeholders ; tous les blocs de code sont complets ; les noms de méthodes (`callBulkEvaluate`, `partitionForAction`, `confirmAction`, `runAction`, `bindActionButtons`, `refreshSelectionUI`) sont cohérents entre les tâches.
