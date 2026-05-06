# Mode entraînement itératif (single-button)

**Date** : 2026-05-06
**Plugin** : `mod_redaction`
**Auteur** : Emmanuel REMY (via Claude)

## Contexte

En mode entraînement, l'élève dispose actuellement de deux mécanismes de soumission :

- Un bouton « Soumettre la correction finale » qui pose `submission.status = 1` (verrou définitif), déclenche une évaluation IA non-training et oblige l'enseignant à déverrouiller manuellement pour permettre une nouvelle tentative.
- Une fonction JavaScript `submitTraining()` qui appelle un endpoint `ajax/training_submit.php` **inexistant**, donc inopérante côté front même si le service externe `mod_redaction_training_submit` est enregistré.

Conséquence pratique : l'enseignant doit déverrouiller chaque soumission à la main pour permettre à l'élève d'itérer sur sa contribution après le retour IA. C'est non scalable.

## Objectif

Refondre le mode entraînement en un flux à **bouton unique itératif** : à chaque clic, l'élève soumet, reçoit le retour IA, peut éditer et re-soumettre tant qu'il n'a pas épuisé son quota de tentatives. La dernière tentative est verrouillée automatiquement et fait office de note finale.

## Décisions de design

Discutées et validées en brainstorming :

| Sujet | Décision |
|-------|----------|
| Modèle pédagogique | A — bouton unique itératif, auto-finalisation au quota |
| Quota par défaut quand `training_max_attempts = 0` | 5 tentatives (forcé côté code) |
| Cooldown entre soumissions | Supprimé |
| Min-change entre soumissions | Supprimé |
| Note au carnet de notes | G1 — la dernière évaluation IA fait foi |

## Modèle de données

### Suppressions de schéma

Champs retirés de `db/install.xml` et de `db/upgrade.php` (drop dans l'upgrade) :

- `redaction.training_cooldown`
- `redaction.training_min_change`
- `redaction_submission.last_training_time`
- `redaction_ai_evaluations.is_training`

### Conservations

- `redaction.training_enabled` — interrupteur du mode itératif
- `redaction.training_max_attempts` — quota ; valeur stockée `0` interprétée comme `5` côté code (constante `REDACTION_DEFAULT_TRAINING_ATTEMPTS = 5`)
- `redaction_submission.training_count` — nombre de tentatives consommées
- `redaction_submission.status` — `0` (brouillon) / `1` (verrouillé)

Aucun renommage de champ : on garde `training_*` pour limiter le bruit de migration.

## Flux fonctionnel

### Activité avec `training_enabled = true`

1. Élève écrit. Autosave et bouton « Enregistrer » inchangés.
2. Bouton **unique** « Soumettre » remplace les deux boutons actuels.
3. Au clic :
   - Sauvegarde du contenu via `file_postupdate_standard_editor`.
   - Incrément de `training_count`.
   - Mise en file d'une évaluation IA via `ai_evaluator::queue_evaluation` (sans flag `is_training`).
   - **Si `training_count >= max_effectif`** → `status = 1` (verrou définitif). C'est la dernière tentative.
   - **Sinon** → `status` reste à `0`.
4. Page rechargée. Bandeau « Évaluation en cours… » via le polling existant (réécrit via `core/ajax`).
5. Quand l'éval revient :
   - Critères + feedback affichés dans l'historique.
   - Si `status = 0` → éditeur ré-éditable, bouton libellé selon le compteur restant.
   - Si `status = 1` → éditeur en lecture seule, message « Tu as utilisé tes N tentatives. Note finale : X/20. »

### Activité avec `training_enabled = false`

Comportement actuel inchangé : 1 clic = verrou définitif, déverrouillage par l'enseignant via `submit_action`.

### Interface enseignant

- L'écran de grading liste toutes les tentatives chronologiquement (1 carte par évaluation), avec critères et feedback IA. La dernière est mise en avant (badge « tentative finale »).
- Le bouton manuel « Déverrouiller » de l'enseignant reste disponible comme filet de sécurité. Action : `status` repasse à `0` **sans toucher à `training_count`**. L'élève peut donc continuer à itérer s'il lui reste des tentatives ; sinon le quota le bloque immédiatement (UX cohérente avec « tu as utilisé tes N tentatives »).

### Note au carnet de notes

`redaction_get_user_grades` retourne la note de la **dernière** évaluation au statut `completed` ou `applied` (ordre `timecreated DESC`, première ligne). Si la dernière évaluation est `failed` ou `pending`, on renvoie la note de la précédente `completed`/`applied`. Si aucune n'est encore disponible, pas de note (comportement actuel inchangé).

## Architecture & fichiers touchés

### Schéma & version

- `db/install.xml` — drop des 4 champs.
- `db/upgrade.php` — étape upgrade qui drop les 4 champs si présents.
- `version.php` — bump (release + version YYYYMMDDXX).

### Backend PHP

- `mod_form.php` — retrait des champs `training_cooldown` et `training_min_change` du formulaire.
- `lib.php` :
  - Constante `REDACTION_DEFAULT_TRAINING_ATTEMPTS = 5`.
  - Helper `redaction_effective_max_attempts($redaction)` retournant `training_max_attempts` ou `5` si zéro.
  - Renommer `redaction_can_training_submit` en `redaction_can_submit_attempt` (signature simplifiée : drop des checks cooldown / min_change). Wrapper conservé sous l'ancien nom pour ne pas casser les tests qui le référencent encore, à supprimer en fin d'implémentation après mise à jour des tests.
  - `redaction_get_user_grades` — pointe sur la dernière éval `completed`/`applied`.
- `pages/redaction.php` :
  - Suppression de la branche `action === 'submit'` actuelle ; remplacée par une logique unifiée :
    - Sauvegarde + `training_count++` + queue éval.
    - Calcul du flag `islastattempt = ($training_count >= $maxeffectif)` ; si vrai → `status = 1`.
  - Calcul du libellé du bouton (premier / restantes / dernière) et de la string de confirmation côté template.
- `classes/external/training_submit.php` — **supprimé** (plus utilisé). Le service `mod_redaction_training_submit` est aussi retiré.
- `classes/ai_evaluator.php` — suppression des branches `is_training` (méthode `queue_training_evaluation` supprimée, `process_evaluation` simplifiée).
- `classes/external/evaluate_submission.php` — pas de changement attendu.
- `classes/external/get_evaluation_status.php` — pas de changement attendu.

### Frontend

- `templates/redaction.mustache` :
  - Un seul bouton dans la branche `trainingenabled`.
  - Suppression des branches caduques liées à `training_final_*` et `training_submit`.
  - Affichage de l'éditeur conditionné à `(!issubmitted)` (inchangé).
  - Historique des tentatives présenté chronologiquement, dernière mise en avant.
- `amd/src/redaction_page.js` :
  - Suppression de `submitTraining()` (cassée, plus utilisée).
  - `pollTrainingResult` → réécrit via `core/ajax` ciblant le service `mod_redaction_get_evaluation_status` (au lieu d'un fetch vers un fichier inexistant). Renommer en `pollEvaluationResult`.
- `amd/build/redaction_page.min.js` — synchronisé avec le src.

### Strings

`lang/en/redaction.php` et `lang/fr/redaction.php` :

- **Supprimer** : `training_min_change`, `training_error_min_change`, `training_error_cooldown_remaining`, `training_final_submit`, `training_final_confirm`, `training_submit` (ancien libellé du bouton)
- **Ajouter** :
  - `attempt_button_first` — « Soumettre »
  - `attempt_button_remaining` — « Soumettre à nouveau ({$a->used}/{$a->max} utilisées) »
  - `attempt_button_last` — « Soumettre la version finale (dernière tentative) »
  - `attempt_last_confirm` — « C'est ta dernière tentative. Tu ne pourras plus modifier après. Continuer ? »
  - `attempts_exhausted` — « Tu as utilisé tes {$a} tentatives. Note finale enregistrée. »

### Grading enseignant

- `grading.php` et templates de grading — adaptation de l'affichage : afficher toutes les évals (plus de filtre `is_training`), trier par date, mettre la dernière en avant.

### Tests

- `tests/ai_evaluator_test.php` — adapter les cas qui utilisent `is_training`.
- Pas de tests Behat planifiés pour cette refonte (couverture testée manuellement en preprod puis prod).

## Migration

- Drop des 4 champs en `upgrade.php`.
- Évaluations `is_training=1` historiques deviennent des tentatives ordinaires (l'historique chronologique les inclut).
- Soumissions déjà verrouillées (`status=1`) restent verrouillées. Pas de « résurrection » de l'historique.
- Valeurs `training_count` existantes respectées comme tentatives déjà consommées.

## Risques

- Données inhabituelles : un élève ayant déjà `training_count > max_effectif` après migration. Le helper `redaction_can_submit_attempt` doit refuser proprement (pas de division par zéro, pas de compteur négatif).
- Si l'enseignant abaisse `training_max_attempts` après que des élèves ont déjà soumis, certains se retrouvent au-dessus du quota. L'éditeur passe en lecture seule sans toucher au verrou DB. Acceptable.
- Suppression du service externe `mod_redaction_training_submit` : appel public théoriquement possible (peu probable car non documenté). Pas de window de dépréciation prévue.

## Hors scope

Bugs adjacents repérés pendant la session, à traiter séparément :

- Soumissions « non notées » (22 soumises / 15 notées sur le dashboard) — investigation séparée.
- Tâche scheduled `auto_submit_deadline` qui ne crée pas les adhoc d'évaluation — investigation séparée.
- String `dashboard_grade_distribution` manquante (rendu littéral dans le graphique de distribution des notes) — fix séparé.
