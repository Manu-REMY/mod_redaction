# Audit de conformite Moodle — mod_redaction v2.0.0

## Resume

Audit realise le 2026-02-24 sur le plugin `mod_redaction` (Activity Module).
- **77 fichiers PHP** analyses, **15 templates Mustache**, **8 modules AMD JS**
- **11 problemes identifies** : 2 critiques, 4 majeurs, 5 mineurs

---

## CRITIQUE

### 1. En-tetes GPL incomplets (67/77 fichiers)

**Probleme :** Le Moodle Plugin Directory exige l'en-tete GPL v3 complet (3 paragraphes) en haut de chaque fichier PHP. Seuls 10 fichiers sont conformes.

**Fichiers conformes :**
- `version.php`, `index.php`
- `backup/moodle2/backup_redaction_activity_task.class.php`
- `backup/moodle2/backup_redaction_stepslib.php`
- `backup/moodle2/restore_redaction_activity_task.class.php`
- `backup/moodle2/restore_redaction_stepslib.php`
- `classes/privacy/provider.php`
- `classes/task/auto_submit_deadline.php`
- `db/caches.php`, `db/tasks.php`

**Correction :** Ajouter les 2 paragraphes manquants apres "either version 3 of the License, or (at your option) any later version." :
```php
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
```

### 2. Chaines hardcodees dans ajax/submit.php

**Probleme :** 7 chaines sont ecrites en dur (dont 1 en francais), au lieu d'utiliser `get_string()`.

| Ligne | Chaine hardcodee | Correction |
|-------|-----------------|------------|
| 65 | `'Le contenu ne peut pas être vide'` | `get_string('error:empty_content', 'redaction')` |
| 75 | `'Submitted'` | `get_string('submitted', 'redaction')` |
| 77 | `'Failed to submit'` | `get_string('error:submitfailed', 'redaction')` |
| 89 | `'Invalid submission'` | `get_string('error:invalidsubmission', 'redaction')` |
| 115 | `'Unlocked'` | `get_string('unlocked', 'redaction')` |
| 117 | `'Failed to unlock'` | `get_string('error:unlockfailed', 'redaction')` |
| 122 | `'Invalid action'` | `get_string('error:invalidaction', 'redaction')` |

**Chaines lang a ajouter :**
- `en/redaction.php` : `submitted`, `unlocked`, `error:submitfailed`, `error:invalidsubmission`, `error:unlockfailed`, `error:invalidaction`
- `fr/redaction.php` : idem en francais

---

## MAJEUR

### 3. API External legacy (14 fichiers)

**Probleme :** Tous les `classes/external/*.php` utilisent `require_once($CFG->libdir . '/externallib.php')` et `extends external_api`. Depuis Moodle 4.2, ces classes sont dans le namespace `\core_external\`. Le plugin requiert Moodle 4.5+, donc la migration est obligatoire.

**Correction :** Dans chaque fichier :
- Supprimer `require_once($CFG->libdir . '/externallib.php');`
- Remplacer `extends external_api` par `extends \core_external\external_api`
- Remplacer `external_value` par `\core_external\external_value`
- Remplacer `external_single_structure` par `\core_external\external_single_structure`
- Remplacer `external_multiple_structure` par `\core_external\external_multiple_structure`
- Remplacer `external_function_parameters` par `\core_external\external_function_parameters`

**Fichiers concernes :**
`apply_ai_grade.php`, `autosave.php`, `bulk_apply_grade.php`, `bulk_evaluate.php`,
`check_similarity.php`, `evaluate_submission.php`, `generate_ai_summary.php`,
`generate_criteria.php`, `get_evaluation_status.php`, `get_history.php`,
`get_submission.php`, `submit_action.php`, `submit_work.php`, `training_submit.php`

### 4. Variables camelCase dans lib.php

**Probleme :** Moodle Coding Standards exigent `snake_case` pour les variables.

| Ligne | Variable actuelle | Correction |
|-------|------------------|------------|
| 214, 218, 508, 510 | `$isGroupSubmission` | `$isgroupsubmission` |
| 605-606 | `$submissionStats` | `$submissionstats` |
| 634-635 | `$summaryGenerator` | `$summarygenerator` |
| 651-652 | `$tokenStats` | `$tokenstats` |

Note : Moodle utilise `$lowercase` sans underscores pour les variables locales courtes.

### 5. Classe transform custom dans privacy/provider.php

**Probleme :** Une classe `transform` est definie en ligne 559. Moodle fournit `\core_privacy\local\request\transform`.

**Correction :** Supprimer la classe custom (lignes 559-572) et remplacer les appels par la classe core.

### 6. Chemin relatif dans index.php:25

**Probleme :** `require_once('../../config.php')` est fragile si le working directory change.

**Correction :** `require_once(__DIR__ . '/../../config.php')`

---

## MINEUR

### 7. grademax hardcode a 20 (lib.php:457)

**Probleme :** La note maximale est fixee a 20 au lieu de provenir d'un reglage configurable.

**Correction :** Ajouter un champ `grade` dans la table `redaction` et `mod_form.php`, puis l'utiliser dans `redaction_grade_item_update()`.

### 8. require_once manuel dans ajax/evaluate.php:22

**Probleme :** `require_once(__DIR__ . '/../classes/ai_evaluator.php')` est inutile car Moodle autocharge les classes dans `classes/`.

**Correction :** Supprimer la ligne et utiliser `new \mod_redaction\ai_evaluator()`.

### 9. Mapping backup incoherent (restore_redaction_stepslib.php:180)

**Probleme :** `set_mapping('redaction_ai_evaluations', ...)` utilise le nom de table au lieu du nom d'element (`redaction_ai_evaluation`).

**Correction :** Aligner sur la convention des autres mappings.

### 10. Pas de $plugin->dependencies dans version.php

**Probleme :** Recommande si le plugin depend de fonctionnalites specifiques.

**Correction :** Optionnel, a ajouter si necessaire.

### 11. grading.php procedural (~575 lignes)

**Probleme :** Fichier monolithique melangeant logique metier et affichage.

**Correction :** Refactoring long terme vers renderer + classes metier.

---

## Corrections appliquees

- [x] En-tetes GPL completes (67 fichiers)
- [x] Chaines hardcodees remplacees par get_string() + nouvelles chaines lang
- [x] Migration external API vers \core_external\ namespace (14 fichiers)
- [x] Variables renommees en snake_case dans lib.php
- [x] Classe transform custom supprimee dans privacy/provider.php
- [x] Chemin relatif corrige dans index.php
- [x] grademax rendu configurable
- [x] require_once manuel supprime dans ajax/evaluate.php
- [x] Mapping backup corrige dans restore_redaction_stepslib.php
