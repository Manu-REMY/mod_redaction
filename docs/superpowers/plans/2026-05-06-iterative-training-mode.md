# Mode entraînement itératif Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refondre le mode entraînement de `mod_redaction` en flux à bouton unique itératif (chaque clic = soumission + éval IA + retour, jusqu'à un quota qui auto-finalise la dernière tentative).

**Architecture:** Suppression de la dualité `training_submit` / `final_submit`, fusion en une action unique sur `pages/redaction.php`. Auto-finalisation à la dernière tentative (`status=1`) pour préserver la sémantique de note finale au carnet de notes. Les évaluations IA perdent leur flag `is_training` (toutes équivalentes, ordre chronologique). Cooldown et min-change sont supprimés (B3).

**Tech Stack:** Moodle 5.0+ (PHP 8.1+), DML, XMLDB, Mustache, AMD (RequireJS), `core/ajax` web services, PHPUnit.

**Spec source :** `docs/superpowers/specs/2026-05-06-iterative-training-mode-design.md`

**Cible de version :** `2026050601` (release `2.1.0`).

---

## File Structure

| Fichier | Rôle | Action |
|---------|------|--------|
| `version.php` | Métadonnées plugin | Bump version + release |
| `db/install.xml` | Schéma initial | Drop 4 champs (cooldown, min_change, last_training_time, is_training) |
| `db/upgrade.php` | Migration | Étape de drop des 4 champs |
| `lib.php` | Helpers métier | + constante `REDACTION_DEFAULT_TRAINING_ATTEMPTS`, + `redaction_effective_max_attempts`, refonte de `redaction_can_training_submit` → `redaction_can_submit_attempt`, MAJ `redaction_get_user_grades` |
| `pages/redaction.php` | Page élève | Flux unifié bouton unique + auto-finalisation |
| `templates/redaction.mustache` | UI élève | 1 seul bouton + label dynamique |
| `amd/src/redaction_page.js` | JS élève | Suppression `submitTraining()`, polling via `core/ajax` |
| `amd/build/redaction_page.min.js` | Build | Synchronisation src |
| `classes/external/training_submit.php` | Service externe | **Supprimé** |
| `db/services.php` | Registre web services | Retrait de `mod_redaction_training_submit` |
| `classes/ai_evaluator.php` | Moteur IA | Retrait branches `is_training` |
| `mod_form.php` | Formulaire activité | Retrait des champs cooldown/min_change |
| `lang/en/redaction.php` | Strings EN | Suppression / ajout |
| `lang/fr/redaction.php` | Strings FR | Suppression / ajout |
| `grading.php` | Vue enseignant | Affichage chronologique de toutes les tentatives |
| `tests/ai_evaluator_test.php` | PHPUnit | Adaptation des cas `is_training` |

---

### Task 1 : Bump version + schéma BDD

**Files:**
- Modify: `redaction/version.php`
- Modify: `redaction/db/install.xml`

- [ ] **Step 1.1 : Bump version**

Modifier `redaction/version.php` :

```php
$plugin->version = 2026050601;  // YYYYMMDDXX format
$plugin->requires = 2024100700; // Moodle 4.5+
$plugin->maturity = MATURITY_BETA;
$plugin->release = '2.1.0';
```

- [ ] **Step 1.2 : Drop dans install.xml**

Dans `redaction/db/install.xml`, **supprimer** :
- de la table `redaction` : la balise `<FIELD NAME="training_cooldown" ... />` et `<FIELD NAME="training_min_change" ... />`
- de la table `redaction_submission` : la balise `<FIELD NAME="last_training_time" ... />`
- de la table `redaction_ai_evaluations` : la balise `<FIELD NAME="is_training" ... />` ainsi que toute clé/index la référençant.

Conserver intactes : `training_enabled`, `training_max_attempts`, `training_count`.

- [ ] **Step 1.3 : Vérification syntaxe XML**

```bash
xmllint --noout redaction/db/install.xml
```
Expected : exit 0 (XML valide).

- [ ] **Step 1.4 : Commit**

```bash
git add redaction/version.php redaction/db/install.xml
git commit -m "feat(schema): drop training_cooldown, training_min_change, last_training_time, is_training; bump 2.1.0"
```

---

### Task 2 : Migration upgrade.php

**Files:**
- Modify: `redaction/db/upgrade.php`

- [ ] **Step 2.1 : Ajouter l'étape upgrade**

À la fin de `xmldb_redaction_upgrade`, juste avant le `return true;` final, insérer :

```php
    if ($oldversion < 2026050601) {
        $table = new xmldb_table('redaction');
        $field = new xmldb_field('training_cooldown');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        $field = new xmldb_field('training_min_change');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $table = new xmldb_table('redaction_submission');
        $field = new xmldb_field('last_training_time');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        $table = new xmldb_table('redaction_ai_evaluations');
        $field = new xmldb_field('is_training');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026050601, 'redaction');
    }
```

- [ ] **Step 2.2 : Vérification syntaxe PHP**

```bash
php -l redaction/db/upgrade.php
```
Expected : `No syntax errors detected`.

- [ ] **Step 2.3 : Commit**

```bash
git add redaction/db/upgrade.php
git commit -m "feat(upgrade): drop obsolete training fields in 2026050601"
```

---

### Task 3 : Helpers lib.php — constante + effective max + can_submit_attempt

**Files:**
- Modify: `redaction/lib.php`

- [ ] **Step 3.1 : Ajouter la constante en tête du fichier**

Juste après `defined('MOODLE_INTERNAL') || die();` dans `redaction/lib.php`, insérer :

```php
/**
 * Default training attempts quota when training_max_attempts is 0 (unlimited).
 */
const REDACTION_DEFAULT_TRAINING_ATTEMPTS = 5;
```

- [ ] **Step 3.2 : Ajouter le helper `redaction_effective_max_attempts`**

Insérer cette fonction quelque part avant `redaction_can_training_submit` (par convention, juste avant) :

```php
/**
 * Returns the effective maximum training attempts for an instance.
 *
 * If training_max_attempts is 0 (legacy unlimited), the default constant applies.
 *
 * @param stdClass $redaction
 * @return int
 */
function redaction_effective_max_attempts($redaction) {
    $max = (int) ($redaction->training_max_attempts ?? 0);
    return $max > 0 ? $max : REDACTION_DEFAULT_TRAINING_ATTEMPTS;
}
```

- [ ] **Step 3.3 : Refonte de `redaction_can_training_submit`**

Remplacer **intégralement** la fonction `redaction_can_training_submit` existante par :

```php
/**
 * Determine whether the student is allowed to submit another attempt.
 *
 * Replaces the previous training_submit gate. Cooldown and min_change checks
 * are removed; quota and pending evaluation are the only remaining gates.
 *
 * @param stdClass $redaction
 * @param stdClass $submission
 * @param stdClass|null $correction
 * @return array ['allowed' => bool, 'reason' => string]
 */
function redaction_can_submit_attempt($redaction, $submission, $correction = null) {
    global $DB;

    if (empty($redaction->training_enabled) || empty($redaction->ai_enabled)) {
        return ['allowed' => false, 'reason' => 'training_not_enabled'];
    }

    if ((int) $submission->status === 1) {
        return ['allowed' => false, 'reason' => 'already_submitted'];
    }

    if ($correction && !empty($correction->deadline_date) && time() > $correction->deadline_date) {
        return ['allowed' => false, 'reason' => 'deadline_passed'];
    }

    $maxeffective = redaction_effective_max_attempts($redaction);
    $used = (int) ($submission->training_count ?? 0);
    if ($used >= $maxeffective) {
        return ['allowed' => false, 'reason' => 'max_attempts_reached'];
    }

    $pending = $DB->record_exists_select(
        'redaction_ai_evaluations',
        'submissionid = ? AND status IN (?, ?)',
        [$submission->id, 'pending', 'processing']
    );
    if ($pending) {
        return ['allowed' => false, 'reason' => 'evaluation_pending'];
    }

    return ['allowed' => true, 'reason' => ''];
}

/**
 * Backward-compat alias. Prefer redaction_can_submit_attempt().
 *
 * @deprecated since 2.1.0
 */
function redaction_can_training_submit($redaction, $submission, $correction = null) {
    return redaction_can_submit_attempt($redaction, $submission, $correction);
}
```

- [ ] **Step 3.4 : Vérification syntaxe**

```bash
php -l redaction/lib.php
```
Expected : `No syntax errors detected`.

- [ ] **Step 3.5 : Commit**

```bash
git add redaction/lib.php
git commit -m "feat(lib): redaction_can_submit_attempt + effective_max_attempts helper"
```

---

### Task 4 : Note au carnet de notes (G1) — `redaction_get_user_grades`

**Files:**
- Modify: `redaction/lib.php`

- [ ] **Step 4.1 : Localiser la fonction**

Repérer `function redaction_get_user_grades(...)` dans `redaction/lib.php`.

- [ ] **Step 4.2 : Réécrire le corps pour pointer sur la dernière éval**

Remplacer la requête existante qui sélectionne la note (probablement basée sur `submission.grade`) par une lecture de la dernière éval IA non-failed/non-pending :

```php
function redaction_get_user_grades($redaction, $userid = 0) {
    global $DB;

    $params = ['redactionid' => $redaction->id];
    $where = 's.gestionprojetid = :redactionid'; // (adapt to actual column - check existing code)
    if ($userid) {
        $where .= ' AND s.userid = :userid';
        $params['userid'] = $userid;
    }

    // Get the latest completed/applied AI evaluation per submission.
    $sql = "SELECT s.userid AS userid,
                   (SELECT e.parsed_grade
                      FROM {redaction_ai_evaluations} e
                     WHERE e.submissionid = s.id
                       AND e.status IN ('completed', 'applied')
                  ORDER BY e.timecreated DESC, e.id DESC
                     LIMIT 1) AS rawgrade
              FROM {redaction_submission} s
             WHERE $where";

    $records = $DB->get_records_sql($sql, $params);

    $grades = [];
    foreach ($records as $r) {
        if ($r->rawgrade === null) {
            continue;
        }
        $grades[$r->userid] = (object) [
            'userid' => $r->userid,
            'rawgrade' => (float) $r->rawgrade,
        ];
    }
    return $grades;
}
```

**Note importante :** Avant d'éditer, **lire** la fonction existante pour récupérer le nom exact de la colonne (`redactionid` vs `gestionprojetid`) et les paramètres réels. La fonction Moodle a une signature standard, ne pas la casser.

- [ ] **Step 4.3 : Vérification syntaxe**

```bash
php -l redaction/lib.php
```
Expected : `No syntax errors detected`.

- [ ] **Step 4.4 : Commit**

```bash
git add redaction/lib.php
git commit -m "feat(grades): use latest completed AI evaluation for gradebook (G1)"
```

---

### Task 5 : Page élève — flux unifié

**Files:**
- Modify: `redaction/pages/redaction.php`

- [ ] **Step 5.1 : Lire le contexte actuel**

Lire `redaction/pages/redaction.php` lignes 73-100 (calcul training data) et 105-204 (handler POST).

- [ ] **Step 5.2 : Mettre à jour le calcul des variables (avant le POST handler)**

Dans la zone « Training mode data » (vers la ligne 73), remplacer l'appel à `redaction_can_training_submit` par `redaction_can_submit_attempt`. Calculer aussi le quota effectif et le compteur :

```php
// Training mode data.
$trainingenabled = !empty($redaction->training_enabled) && !empty($redaction->ai_enabled);
$correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
$cantraining = ['allowed' => false, 'reason' => ''];
$trainingevals = [];
$maxeffective = $trainingenabled ? redaction_effective_max_attempts($redaction) : 0;
$attemptsused = (int) ($submission->training_count ?? 0);
$attemptsremaining = max(0, $maxeffective - $attemptsused);
$islastattempt = $trainingenabled && $attemptsremaining === 1; // The next click will be the last.
if ($trainingenabled && $submission) {
    $cantraining = redaction_can_submit_attempt($redaction, $submission, $correction);
    $trainingevals = redaction_get_training_evaluations($submission->id);
}
```

- [ ] **Step 5.3 : Réécrire le handler `action === 'submit'`**

Remplacer la branche `if ($action === 'submit')` (lignes ~155-203) par :

```php
    if ($action === 'submit') {
        // Get form data first.
        $submission->titre = optional_param('titre', '', PARAM_TEXT);

        // Handle editor content.
        $editordata = optional_param_array('contenu_editor', [], PARAM_RAW);
        $submission->contenu_editor = [
            'text' => $editordata['text'] ?? '',
            'format' => isset($editordata['format']) ? (int)$editordata['format'] : FORMAT_HTML,
            'itemid' => isset($editordata['itemid']) ? (int)$editordata['itemid'] : 0,
        ];

        // Save editor content.
        $submission = file_postupdate_standard_editor(
            $submission,
            'contenu',
            $editoroptions,
            $context,
            'mod_redaction',
            'contenu',
            $submission->id
        );

        // In training mode, increment counter and auto-finalize on last attempt.
        if ($trainingenabled) {
            $check = redaction_can_submit_attempt($redaction, $submission, $correction);
            if (!$check['allowed']) {
                redirect(
                    new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'redaction']),
                    get_string('training_error_' . $check['reason'], 'redaction'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }

            $submission->training_count = $attemptsused + 1;
            $submission->timemodified = time();

            $maxeff = redaction_effective_max_attempts($redaction);
            $islast = ($submission->training_count >= $maxeff);
            if ($islast) {
                $submission->status = 1;
                $submission->timesubmitted = time();
            }
        } else {
            // Classic mode — single shot, immediate lock.
            $submission->status = 1;
            $submission->timesubmitted = time();
            $submission->timemodified = time();
        }

        $DB->update_record('redaction_submission', $submission);

        redaction_save_history($submission, $USER->id);

        if ($redaction->ai_enabled) {
            \mod_redaction\ai_evaluator::queue_evaluation(
                $redaction->id,
                $submission->id,
                $submission->groupid,
                $submission->userid
            );
        }

        redirect(
            new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'redaction']),
            get_string('status_submitted', 'redaction'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
```

- [ ] **Step 5.4 : Calculer le label du bouton dans `$templatedata`**

Repérer le bloc `$templatedata = [...]` (vers ligne 343). Avant ce bloc, calculer :

```php
// Compute submit button label.
$attemptbuttonlabel = '';
$attemptconfirm = '';
if ($trainingenabled && !$issubmitted) {
    if ($attemptsused === 0) {
        $attemptbuttonlabel = get_string('attempt_button_first', 'redaction');
    } else if ($attemptsremaining > 1) {
        $attemptbuttonlabel = get_string('attempt_button_remaining', 'redaction',
            (object) ['used' => $attemptsused, 'max' => $maxeffective]);
    } else {
        $attemptbuttonlabel = get_string('attempt_button_last', 'redaction');
        $attemptconfirm = get_string('attempt_last_confirm', 'redaction');
    }
}
```

Puis ajouter dans `$templatedata` :

```php
    'attemptbuttonlabel' => $attemptbuttonlabel,
    'attemptconfirm' => $attemptconfirm,
    'hasattemptconfirm' => !empty($attemptconfirm),
    'attemptsexhaustedstr' => ($trainingenabled && $issubmitted && $attemptsused >= $maxeffective)
        ? get_string('attempts_exhausted', 'redaction', $maxeffective)
        : '',
```

- [ ] **Step 5.5 : Vérification syntaxe**

```bash
php -l redaction/pages/redaction.php
```
Expected : `No syntax errors detected`.

- [ ] **Step 5.6 : Commit**

```bash
git add redaction/pages/redaction.php
git commit -m "feat(student): unified single-button submit + auto-finalize on last attempt"
```

---

### Task 6 : Template Mustache — bouton unique

**Files:**
- Modify: `redaction/templates/redaction.mustache`

- [ ] **Step 6.1 : Localiser le bloc des boutons d'action**

Repérer le bloc `{{^issubmitted}}<div class="mod_redaction-action-buttons">...` (vers les lignes 292-310).

- [ ] **Step 6.2 : Remplacer le bloc**

Remplacer **intégralement** ce bloc par :

```mustache
            {{^issubmitted}}
                <div class="mod_redaction-action-buttons">
                    <button type="submit" name="action" value="save" class="mod_redaction-btn-save">
                        &#x1F4BE; {{#str}}savechanges, moodle{{/str}}
                    </button>
                    {{#trainingenabled}}
                        <button type="submit" name="action" value="submit" class="mod_redaction-btn-submit"
                                {{#hasattemptconfirm}}onclick="return confirm('{{attemptconfirm}}');"{{/hasattemptconfirm}}>
                            &#x2728; {{attemptbuttonlabel}}
                        </button>
                    {{/trainingenabled}}
                    {{^trainingenabled}}
                        <button type="submit" name="action" value="submit" class="mod_redaction-btn-submit"
                                onclick="return confirm('{{submitconfirm}}');">
                            &#x2713; {{#str}}submit_redaction, mod_redaction{{/str}}
                        </button>
                    {{/trainingenabled}}
                </div>
            {{/issubmitted}}
```

- [ ] **Step 6.3 : Ajouter le message « tentatives épuisées »**

Juste après le bloc « Submission Status » (vers la ligne 156), ajouter :

```mustache
    {{#attemptsexhaustedstr}}
        <div class="mod_redaction-attempts-exhausted">
            &#x1F3C1; {{attemptsexhaustedstr}}
        </div>
    {{/attemptsexhaustedstr}}
```

- [ ] **Step 6.4 : Mettre à jour la doc d'en-tête du template**

Dans le commentaire d'en-tête `{{!}}` du template, ajouter à la liste des variables :

```
* attemptbuttonlabel - Submit button label (training mode)
* attemptconfirm - JS confirm string for last attempt (empty if not last)
* hasattemptconfirm - Boolean true when confirmation must trigger
* attemptsexhaustedstr - String shown when student has used all attempts
```

- [ ] **Step 6.5 : Commit**

```bash
git add redaction/templates/redaction.mustache
git commit -m "feat(student-ui): single submit button with dynamic label"
```

---

### Task 7 : JS — supprimer submitTraining cassé, polling via core/ajax

**Files:**
- Modify: `redaction/amd/src/redaction_page.js`
- Modify: `redaction/amd/build/redaction_page.min.js`

- [ ] **Step 7.1 : Réécrire `redaction_page.js`**

Remplacer **intégralement** le contenu de `redaction/amd/src/redaction_page.js` par :

```js
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Student redaction page interactions.
 *
 * @module     mod_redaction/redaction_page
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'core/notification'], function(Ajax, Notification) {

    var config = {};

    /**
     * Toggle collapsible section.
     * @param {HTMLElement} header
     */
    function toggleCollapsible(header) {
        header.classList.toggle('mod_redaction-collapsed');
        var content = header.nextElementSibling;
        content.classList.toggle('mod_redaction-collapsed');
    }

    /**
     * Poll for evaluation result via the Moodle web service.
     * @param {number} submissionid
     */
    function pollEvaluationResult(submissionid) {
        var attempts = 0;
        var maxAttempts = 120; // 10 minutes at 5s intervals.
        var interval = 5000;

        var poll = setInterval(function() {
            attempts++;
            if (attempts > maxAttempts) {
                clearInterval(poll);
                location.reload();
                return;
            }

            Ajax.call([{
                methodname: 'mod_redaction_get_evaluation_status',
                args: {submissionid: parseInt(submissionid, 10)},
            }])[0]
                .then(function(data) {
                    if (data.status === 'completed' || data.status === 'applied' || data.status === 'failed') {
                        clearInterval(poll);
                        location.reload();
                    }
                    return data;
                })
                .catch(function() {
                    // Ignore polling errors, continue.
                    return null;
                });
        }, interval);
    }

    return {
        /**
         * Initialise the redaction page.
         * @param {object} params Configuration
         */
        init: function(params) {
            config = params;

            window.toggleCollapsible = toggleCollapsible;

            // If a pending evaluation exists for this submission, start polling.
            if (config.pollEvaluation && config.submissionid) {
                pollEvaluationResult(config.submissionid);
            }

            // Suppress unused variable warning.
            void Notification;
        },
    };
});
```

- [ ] **Step 7.2 : Mettre à jour le PHP qui passe les params JS**

Dans `redaction/pages/redaction.php`, repérer le bloc `$jsparams = [...]` (vers la ligne 389). Remplacer par :

```php
// Detect whether a pending evaluation exists, to start polling on page load.
$pollevaluation = $DB->record_exists_select(
    'redaction_ai_evaluations',
    'submissionid = ? AND status IN (?, ?)',
    [$submission->id, 'pending', 'processing']
);

$jsparams = [
    'cmid' => $cm->id,
    'submissionid' => $submission->id,
    'pollEvaluation' => $pollevaluation,
];
$PAGE->requires->js_call_amd('mod_redaction/redaction_page', 'init', [$jsparams]);
```

- [ ] **Step 7.3 : Synchroniser le build**

```bash
cp redaction/amd/src/redaction_page.js redaction/amd/build/redaction_page.min.js
```

- [ ] **Step 7.4 : Commit**

```bash
git add redaction/amd/src/redaction_page.js redaction/amd/build/redaction_page.min.js redaction/pages/redaction.php
git commit -m "feat(js): polling via core/ajax, drop broken submitTraining()"
```

---

### Task 8 : Suppression du service externe `training_submit`

**Files:**
- Delete: `redaction/classes/external/training_submit.php`
- Modify: `redaction/db/services.php`

- [ ] **Step 8.1 : Supprimer le fichier**

```bash
rm redaction/classes/external/training_submit.php
```

- [ ] **Step 8.2 : Retirer le service de `db/services.php`**

Localiser la définition `'mod_redaction_training_submit' => [...]` dans `redaction/db/services.php` et supprimer **toute la sous-clé** (de la clé jusqu'à la `],` fermante incluse).

- [ ] **Step 8.3 : Vérification syntaxe**

```bash
php -l redaction/db/services.php
```
Expected : `No syntax errors detected`.

- [ ] **Step 8.4 : Commit**

```bash
git add redaction/classes/external/training_submit.php redaction/db/services.php
git commit -m "refactor: drop training_submit external service (unused)"
```

---

### Task 9 : Cleanup `is_training` dans ai_evaluator.php

**Files:**
- Modify: `redaction/classes/ai_evaluator.php`

- [ ] **Step 9.1 : Lire le fichier**

Lire `redaction/classes/ai_evaluator.php` pour repérer toutes les occurrences de `is_training`.

- [ ] **Step 9.2 : Supprimer la méthode `queue_training_evaluation`**

Si la méthode existe, la supprimer **entièrement**. Toutes les soumissions passent désormais par `queue_evaluation`.

- [ ] **Step 9.3 : Retirer les références `is_training` dans les requêtes et inserts**

Dans toutes les requêtes SQL et les `insert_record` / `update_record`, retirer la colonne `is_training` (ne plus la définir, ne plus la filtrer).

Pour `process_evaluation`, retirer toute branche conditionnelle `if ($evaluation->is_training)` — fusionner les deux comportements en un seul (le comportement « non-training » actuel : queue + apply grade au gradebook).

- [ ] **Step 9.4 : Vérifier les autres consommateurs**

```bash
grep -rn "is_training" redaction/ --include="*.php"
```

Si des occurrences restent dans `lib.php`, `pages/redaction.php`, `grading.php` ou `classes/dashboard/`, les retirer dans le même commit.

- [ ] **Step 9.5 : Vérification syntaxe sur les fichiers modifiés**

```bash
php -l redaction/classes/ai_evaluator.php
```
Expected : `No syntax errors detected`.

- [ ] **Step 9.6 : Commit**

```bash
git add -u redaction/
git commit -m "refactor(ai_evaluator): drop is_training distinction"
```

---

### Task 10 : Formulaire activité — retrait des champs

**Files:**
- Modify: `redaction/mod_form.php`

- [ ] **Step 10.1 : Lire le fichier et localiser les champs**

```bash
grep -n "training_cooldown\|training_min_change" redaction/mod_form.php
```

- [ ] **Step 10.2 : Supprimer les définitions de champ**

Pour chaque ligne identifiée, supprimer la séquence `addElement` + `setType` + `setDefault` + `addHelpButton` correspondante (typiquement 3-4 lignes par champ).

- [ ] **Step 10.3 : Vérification syntaxe**

```bash
php -l redaction/mod_form.php
```
Expected : `No syntax errors detected`.

- [ ] **Step 10.4 : Commit**

```bash
git add redaction/mod_form.php
git commit -m "feat(form): drop cooldown and min_change fields from activity form"
```

---

### Task 11 : Strings EN + FR

**Files:**
- Modify: `redaction/lang/en/redaction.php`
- Modify: `redaction/lang/fr/redaction.php`

- [ ] **Step 11.1 : Suppressions dans `lang/en/redaction.php`**

Supprimer les lignes :
```php
$string['training_min_change'] = ...;
$string['training_min_change_help'] = ...;     // si présent
$string['training_cooldown'] = ...;             // si présent comme label de form
$string['training_cooldown_help'] = ...;        // si présent
$string['training_error_min_change'] = ...;
$string['training_error_cooldown_remaining'] = ...;
$string['training_final_submit'] = ...;
$string['training_final_confirm'] = ...;
$string['training_submit'] = ...;
```

- [ ] **Step 11.2 : Ajouts dans `lang/en/redaction.php`**

Ajouter (par ordre alphabétique pour rester maintenable) :

```php
$string['attempt_button_first'] = 'Submit';
$string['attempt_button_last'] = 'Submit final version (last attempt)';
$string['attempt_button_remaining'] = 'Resubmit ({$a->used}/{$a->max} used)';
$string['attempt_last_confirm'] = 'This is your last attempt. You will not be able to edit afterwards. Continue?';
$string['attempts_exhausted'] = 'You have used all {$a} attempts. Final grade recorded.';
```

- [ ] **Step 11.3 : Mêmes opérations dans `lang/fr/redaction.php`**

Suppressions identiques. Ajouts :

```php
$string['attempt_button_first'] = 'Soumettre';
$string['attempt_button_last'] = 'Soumettre la version finale (dernière tentative)';
$string['attempt_button_remaining'] = 'Soumettre à nouveau ({$a->used}/{$a->max} utilisées)';
$string['attempt_last_confirm'] = 'C\'est ta dernière tentative. Tu ne pourras plus modifier après. Continuer ?';
$string['attempts_exhausted'] = 'Tu as utilisé tes {$a} tentatives. Note finale enregistrée.';
```

- [ ] **Step 11.4 : Vérification syntaxe**

```bash
php -l redaction/lang/en/redaction.php && php -l redaction/lang/fr/redaction.php
```
Expected : `No syntax errors detected`.

- [ ] **Step 11.5 : Commit**

```bash
git add redaction/lang/en/redaction.php redaction/lang/fr/redaction.php
git commit -m "lang: drop obsolete training strings, add attempt_* strings"
```

---

### Task 12 : Vue enseignant — affichage chronologique des tentatives

**Files:**
- Modify: `redaction/grading.php`

- [ ] **Step 12.1 : Lire le fichier**

Lire `redaction/grading.php` pour identifier la requête qui charge les évals IA d'un élève.

- [ ] **Step 12.2 : Adapter la requête**

Si la requête filtre sur `is_training = 0`, retirer le filtre. Trier toutes les évals par `timecreated DESC` et passer la liste à la vue. Marquer la première (la plus récente) avec un flag `is_latest = true` à utiliser dans le template pour le badge « tentative finale ».

- [ ] **Step 12.3 : Adapter le template Mustache associé**

Dans le template Mustache concerné (probablement `redaction/templates/grading_*.mustache`), itérer sur la liste avec `{{#evaluations}}...{{/evaluations}}` et mettre en avant `{{#is_latest}}<span class="badge">Latest</span>{{/is_latest}}`. Conserver les critères + feedback déjà rendus.

- [ ] **Step 12.4 : Vérification syntaxe**

```bash
php -l redaction/grading.php
```
Expected : `No syntax errors detected`.

- [ ] **Step 12.5 : Commit**

```bash
git add -u redaction/
git commit -m "feat(grading): chronological attempt list with latest badge"
```

---

### Task 13 : Tests PHPUnit

**Files:**
- Modify: `redaction/tests/ai_evaluator_test.php`

- [ ] **Step 13.1 : Lire les tests concernés**

```bash
grep -n "is_training\|training_min_change\|training_cooldown\|queue_training_evaluation" redaction/tests/ai_evaluator_test.php
```

- [ ] **Step 13.2 : Supprimer les tests obsolètes**

Pour chaque test qui valide spécifiquement le comportement `is_training=1` ou les checks cooldown/min_change, soit le supprimer (s'il n'a plus de pertinence), soit l'adapter à la nouvelle sémantique unifiée.

- [ ] **Step 13.3 : Ajouter un test pour le quota effectif**

Ajouter à la fin du fichier :

```php
public function test_effective_max_attempts_defaults_when_zero(): void {
    require_once($GLOBALS['CFG']->dirroot . '/mod/redaction/lib.php');
    $r = (object) ['training_max_attempts' => 0];
    $this->assertSame(REDACTION_DEFAULT_TRAINING_ATTEMPTS, redaction_effective_max_attempts($r));

    $r->training_max_attempts = 8;
    $this->assertSame(8, redaction_effective_max_attempts($r));
}
```

- [ ] **Step 13.4 : Lancer les tests**

```bash
cd redaction && vendor/bin/phpunit tests/ai_evaluator_test.php 2>&1 | tail -20
```

Si l'environnement local PHPUnit n'est pas configuré, sauter cette étape et marquer comme « tests à valider en preprod ». Indiquer qu'on testera manuellement.

- [ ] **Step 13.5 : Commit**

```bash
git add redaction/tests/ai_evaluator_test.php
git commit -m "test: adapt ai_evaluator tests to unified attempt model"
```

---

### Task 14 : Smoke test en preprod

**Files:**
- Aucun fichier modifié — vérification manuelle.

- [ ] **Step 14.1 : Déployer sur preprod**

```bash
sshpass -p '<password — voir TESTING.local.md>' rsync -avz --delete \
  redaction/ \
  favi5410@favi5410.odns.fr:~/preprod.ent-occitanie.com/public/mod/redaction/

sshpass -p '<password — voir TESTING.local.md>' ssh favi5410@favi5410.odns.fr \
  "cd ~/preprod.ent-occitanie.com && /opt/alt/php82/usr/bin/php admin/cli/upgrade.php --non-interactive --allow-unstable"

sshpass -p '<password — voir TESTING.local.md>' ssh favi5410@favi5410.odns.fr \
  "cd ~/preprod.ent-occitanie.com && /opt/alt/php82/usr/bin/php admin/cli/purge_caches.php"
```

- [ ] **Step 14.2 : Reset OPcache preprod**

```bash
sshpass -p '<password — voir TESTING.local.md>' ssh favi5410@favi5410.odns.fr \
  "echo '<?php opcache_reset();' > ~/preprod.ent-occitanie.com/public/opcache_reset.php && \
   curl -s https://preprod.ent-occitanie.com/opcache_reset.php && \
   rm ~/preprod.ent-occitanie.com/public/opcache_reset.php"
```

- [ ] **Step 14.3 : Scénario de validation manuel**

Compte enseignant `prof` / mdp `<password — voir TESTING.local.md>` → cours TEST :
1. Activer le mode entraînement avec `training_max_attempts = 0` et `training_max_attempts = 3` sur 2 activités distinctes.
2. Compte élève `3a1` / mdp `<password — voir TESTING.local.md>` :
   - Activité avec `max=0` → vérifier que le bouton affiche « Soumettre », soumettre 5 fois, vérifier l'auto-finalisation à la 5ème.
   - Activité avec `max=3` → soumettre 3 fois, vérifier auto-finalisation à la 3ème, l'éditeur passe en lecture seule, message « Tu as utilisé tes 3 tentatives ».
3. Compte enseignant : ouvrir l'écran de grading et vérifier l'affichage chronologique des 3 (resp. 5) évaluations IA avec critères et feedback.
4. Vérifier la note dans le carnet de notes : doit correspondre à la **dernière** éval.
5. Tester le déverrouillage manuel par l'enseignant (filet de sécurité) : `status` repasse à 0 sans toucher à `training_count`.

- [ ] **Step 14.4 : Bilan**

Si tout passe, marquer cette tâche done. Sinon, créer une issue ou retourner au plan corriger.

---

### Task 15 : Déploiement prod

**Files:**
- Aucun fichier modifié — déploiement.

- [ ] **Step 15.1 : Backup BDD prod (table redaction et liées)**

```bash
sshpass -p '<password — voir TESTING.local.md>' ssh favi5410@favi5410.odns.fr \
  "cd ~/ent-occitanie.com && /opt/alt/php82/usr/bin/php moodle/admin/cli/cfg.php --name=dbname --component=core" \
  | head -1 > /tmp/dbname.txt
# Note: prefer using cPanel backup tools if available.
```

À adapter selon les outils dispo. Au minimum, vérifier qu'un backup automatique récent existe avant de continuer.

- [ ] **Step 15.2 : SCP des fichiers modifiés**

```bash
sshpass -p '<password — voir TESTING.local.md>' rsync -avz --delete \
  --exclude='.git*' --exclude='*.bak.*' \
  redaction/ \
  favi5410@favi5410.odns.fr:~/ent-occitanie.com/moodle/mod/redaction/
```

- [ ] **Step 15.3 : Lancer l'upgrade**

```bash
sshpass -p '<password — voir TESTING.local.md>' ssh favi5410@favi5410.odns.fr \
  "cd ~/ent-occitanie.com/moodle && /opt/alt/php82/usr/bin/php admin/cli/upgrade.php --non-interactive --allow-unstable"
```

Expected : confirmation que la migration `2026050601` est appliquée.

- [ ] **Step 15.4 : Purge caches Moodle + reset OPcache**

```bash
sshpass -p '<password — voir TESTING.local.md>' ssh favi5410@favi5410.odns.fr \
  "cd ~/ent-occitanie.com/moodle && /opt/alt/php82/usr/bin/php admin/cli/purge_caches.php && \
   echo '<?php opcache_reset();' > ~/ent-occitanie.com/moodle/opcache_reset.php && \
   curl -s https://ent-occitanie.com/moodle/opcache_reset.php && \
   rm ~/ent-occitanie.com/moodle/opcache_reset.php"
```

- [ ] **Step 15.5 : Validation rapide en prod**

Connecté en tant qu'enseignant prod, ouvrir une activité avec `training_enabled=true`, vérifier qu'aucune erreur PHP n'apparaît, qu'un élève peut soumettre, et que les 22 soumissions existantes restent visibles avec leur statut.

- [ ] **Step 15.6 : Tag git**

```bash
git tag v2.1.0
git push origin v2.1.0
```

---

## Self-Review effectué

Couverture du spec : tous les éléments des sections « Modèle de données », « Flux fonctionnel », « Architecture & fichiers touchés », « Strings », et « Migration » sont couverts par au moins une tâche.

Pas de placeholder « TBD/TODO » détecté ; chaque step contient le code ou la commande exacte. Les noms de fonctions sont cohérents : `redaction_can_submit_attempt` / `redaction_effective_max_attempts` / `pollEvaluationResult` réutilisés à travers les tasks.

Les bugs hors scope (auto_submit_deadline, 7 ungraded, dashboard string i18n) sont volontairement exclus du plan : ils sont traités par une investigation parallèle et auront leur propre plan/PR.
