# Essais, soumission finale, retours explicatifs : plan de mise en œuvre

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** en mode sujets, des essais d'entraînement non notés au carnet, une seule soumission finale notée (automatique à la date limite), des retours explicatifs sur le fond et la langue, dans une page élève à deux colonnes avec le volet « Mon avancement ».

**Architecture:** une correction (`redaction_ai_evaluations`) porte `finale` (0 essai, 1 finale) et `empreinte` (sha1 des réponses corrigées) ; la note de la copie ne lit que la dernière finale réussie ou la note du professeur. La copie se verrouille par `redaction_submission.status = 1`. Une tâche relance les finales en échec ; `auto_submit_deadline` soumet le mode sujets. Les retours du fond passent de `commentaire` à `juste` / `a_preciser` / `piste`, filtrés par le serveur ; la langue gagne `regle` par faute et un `conseil`.

**Tech Stack:** PHP 8.1, Moodle 4.5+/5.0 (DML, XMLDB, external API, tâches), Mustache, AMD (terser pour `amd/build`), tests purs `php tests/pure/*.php`, test JS `node tests/js/test_sujets.js`, vérifications CLI `tests/cli/verif_*.php` sur la préprod.

**Spec:** `docs/superpowers/specs/2026-11-12-soumission-finale-design.md` (lire en entier ; maquette validée : https://claude.ai/artifact/JL5jpSTe8EfW6nyqc6qsk7).

## Global Constraints

- Aucun texte en dur : `get_string(…, 'mod_redaction')`, clé d'abord dans `lang/en`, puis `lang/fr` ; mêmes clés des deux côtés.
- Aucun CSS en ligne ; sélecteurs préfixés `.mod_redaction-…` dans `styles.css` ; HTML par gabarits Mustache.
- Couleurs : bleu = validé/juste (`#2f5f93`), orange = à revoir (`#a4500f`), toujours avec ✓ / ✗ / → et le mot.
- Le retour ne donne jamais le corrigé (D-002) ; la note vient du serveur, jamais d'une note annoncée par l'IA.
- Pas de repli silencieux : une réponse d'IA invalide = correction en échec, jamais une note par défaut.
- Version 2.7.0, `version.php` 2026111300 ; une seule étape de mise à jour.
- `amd/build/*.min.js` régénérés par `npx -y terser@5.31.6 … --compress --mangle --comments '/@module/'` après toute modification de `amd/src`.
- Ne jamais écrire « define( » dans un commentaire d'un module AMD.

## Review Focus

1. Double clic sur « Soumettre ma version finale », ou deux membres du groupe en même temps : une seule finale (verrou `redaction_submit_<id>` + relecture de `status`). → test Task 4.
2. Élève qui garde la page ouverte après la date limite et clique : refus explicite « date limite passée », rien envoyé. → test Task 4.
3. Correction finale qui échoue (429, délai) : la copie reste soumise, relancée, jamais notée 0 par défaut. → test Task 5.
4. Texte modifié d'un seul caractère après le dernier essai : nouvelle correction, pas de reprise. → test Task 2 (empreinte) et Task 3.
5. Ancienne correction (2.6.x) avec un seul `commentaire` : s'affiche encore côté élève et professeur. → test Task 7.

---

### Task 1: Schéma, mise à jour, sauvegarde

**Files:**
- Modify: `db/install.xml`, `db/upgrade.php`, `version.php`, `backup/moodle2/backup_redaction_stepslib.php`, `tests/cli/verif_schema.php`

**Interfaces:**
- Produces: `redaction_ai_evaluations.finale` (int 1, NOT NULL, défaut 0, après `langue_points`), `redaction_ai_evaluations.empreinte` (char 40, NULL, après `finale`) ; tâche `mod_redaction\task\relancer_finales` déclarée dans `db/tasks.php` (`minute => '*/10'`).

- [ ] **Step 1:** ajouter les deux champs à `verif_schema.php` (`$expectedfields['redaction_ai_evaluations']`) et un contrôle : « toutes les corrections existantes sont des essais » (`count_records('redaction_ai_evaluations', ['finale' => 1]) === 0` juste après la mise à jour).
- [ ] **Step 2:** `install.xml`, étape `2026111300` dans `upgrade.php` (ajout des champs si absents, puis file de `mettre_a_jour_carnet` pour chaque activité `sujets_enabled = 1`), `version.php` 2026111300 / `2.7.0`, deux champs dans l'élément `ai_evaluation` de la sauvegarde.
- [ ] **Step 3:** `php -l` des fichiers, `xmllint --noout db/install.xml`.
- [ ] **Step 4:** Commit `feat(sujets): final flag and answers fingerprint on each correction`.

### Task 2: Règles pures (note finale, empreinte, retours filtrés)

**Files:**
- Modify: `classes/notes_sujets.php`, `classes/langue.php`
- Test: `tests/pure/test_notes_sujets.php`, `tests/pure/test_langue.php`

**Interfaces:**
- Produces:
  - `notes_sujets::empreinte(array $textes, float $reglage): string` — sha1 de `json_encode([array_values($textes), round($reglage, 2)])`.
  - `notes_sujets::note_finale(?float $finale, ?float $enseignant): ?float` — l'enseignant gagne, sinon la finale.
  - `notes_sujets::lire_reponse_ia(string $brut): array` — accepte `juste`, `a_preciser`, `piste` (chaînes, au moins `juste` non vide) **ou** l'ancien `commentaire` ; renvoie toujours les clés `juste`, `a_preciser`, `piste`, `commentaire` (les trois bout à bout quand l'IA donne les trois).
  - `notes_sujets::retour_final(array $lu, float $seuil, array $phrases, string $corrige, string $permis): array` — clés `juste`, `a_preciser`, `piste`, `commentaire` ; remplace `commentaire_final` (le garder comme enveloppe qui renvoie `retour_final(...)['commentaire']`, pour les tests existants).
  - `notes_sujets::filtrer(string $texte, string $corrige, string $permis): string` — version publique de `sans_mots_du_corrige`.
  - `langue::lire_reponse_ia` : `fautes[i]['regle']` et `remarques[i]['regle']` (chaîne, `''` si absente), `conseil` (chaîne, `''` si absent).

- [ ] **Step 1: tests** (assertions exactes) :
  - `empreinte(['a', 'b'], 2.0) === empreinte(['a', 'b'], 2.0)` ; différente avec `['a', 'b ']`, avec `['b', 'a']`, avec réglage `3.0`.
  - `note_finale(12.5, null) === 12.5` ; `note_finale(null, 14.0) === 14.0` ; `note_finale(18.0, 9.0) === 9.0` ; `note_finale(null, null) === null`.
  - `lire_reponse_ia('{"attendus":["E1"],"erreurs":[],"note":12,"juste":"J.","a_preciser":"P.","piste":"Q ?"}')` → `juste === 'J.'`, `piste === 'Q ?'`, `commentaire === 'J. P. Q ?'`.
  - ancien format `{"attendus":[],"erreurs":[],"note":5,"commentaire":"C."}` → `juste === 'C.'`, `a_preciser === ''`, `commentaire === 'C.'`.
  - ni `juste` ni `commentaire` → `UnexpectedValueException`.
  - `retour_final` sous le seuil, corrigé `'E1 : la semelle comprimée. E2 : traction en bas.'`, permis `'Ma section en I. Explique pourquoi ta poutre résiste.'` : une `piste` contenant « comprimée » est remplacée par `$phrases['les_deux']` ; une `piste` « Que se passe-t-il quand ta poutre résiste ? » est gardée (mots de l'énoncé permis).
  - au-dessus du seuil sans erreur X : `a_preciser === ''` et `piste === ''`.
  - `langue::lire_reponse_ia` : une faute avec `"regle":"Le verbe s'accorde."` la garde ; sans `regle` → `''` ; `"conseil":"Relis tes verbes."` gardé.
- [ ] **Step 2:** lancer `php tests/pure/test_notes_sujets.php` et `php tests/pure/test_langue.php` : échecs attendus.
- [ ] **Step 3:** implémenter.
- [ ] **Step 4:** les cinq fichiers de `tests/pure/` au vert.
- [ ] **Step 5:** Commit `feat(sujets): final grade, fingerprint and explanatory feedback rules`.

### Task 3: Correction essai / finale dans `ai_evaluator`

**Files:**
- Modify: `classes/ai_evaluator.php`, `classes/ai_prompt_builder.php`, `lang/en/redaction.php`, `lang/fr/redaction.php`, `lib.php` (`redaction_get_user_grades_sujets`)
- Test: `tests/cli/verif_correction.php`

**Interfaces:**
- Consumes: Task 1 (champs), Task 2 (`empreinte`, `retour_final`, `note_finale`, `filtrer`, champs `regle`/`conseil`).
- Produces: `ai_evaluator::queue_evaluation(int $redactionid, int $submissionid, int $groupid, int $userid, bool $skipratelimit = false, bool $finale = false): int`.

- [ ] **Step 1: vérifications CLI** ajoutées à `verif_correction.php` (fournisseur simulé) :
  - essai réussi : `finale = 0`, `empreinte` remplie, **aucune note au carnet** (`redaction_get_user_grades_sujets` renvoie `rawgrade = null`).
  - finale après un essai sur des réponses identiques : **aucun appel** au fournisseur (`$sim->appels` vide), note finale = note de l'essai, `finale = 1`, carnet = cette note.
  - finale après modification d'une réponse : un appel par sujet + un de langue, nouvelle note.
  - finale avec un sujet sans réponse : pas d'appel pour ce sujet, sa part vaut 0, les autres sujets corrigés.
  - la note du professeur gagne sur la finale.
  - changement de `langue_points` après la finale : la note du carnet ne change pas.
- [ ] **Step 2:** lancer sur la préprod, constater les échecs.
- [ ] **Step 3: implémenter** :
  - `queue_evaluation` écrit `finale`.
  - `process_evaluation_sujets` : textes de chaque sujet ; si `finale` et un essai réussi a la même `empreinte` (réglage de langue actuel compris) → recopier `parsed_grade`, `parsed_feedback`, `sujets_json`, `langue_json`, `langue_points`, `empreinte` dans la finale, statut comme une correction réussie, sans appel. Sinon corriger ; en finale, un sujet vide vaut 0 (`sujets_json` : `note: 0`, `juste: ''`), en essai il reste refusé (`sujets_reponse_vide`). Écrire `empreinte` sur chaque correction réussie.
  - Retour du fond : `retour_final` avec `$permis` = réponse + titre + énoncé du sujet (`texte_reponse($sujet->contenu, FORMAT_HTML)`) ; `sujets_json` par sujet : `juste`, `a_preciser`, `piste`, `commentaire`, `note`.
  - Consignes : `ai_prompt_sujet_commentaire` réécrite (trois parties, longueurs du spec), `ai_prompt_sujet_format` = `{"attendus": [...], "erreurs": [...], "note": <0 à 20>, "juste": "...", "a_preciser": "...", "piste": "..."}` ; langue : règle « pour chaque faute et chaque remarque, `regle` : la règle en une phrase appliquée à la copie » et « `conseil` : une phrase pour progresser » dans `ai_prompt_langue_format` ; `LANGUE_MAX_TOKENS` relevé à 2500.
  - Filtrer `regle` et `conseil` par `notes_sujets::filtrer($texte, $lexiques . "\n" . $corriges, $textes)` (phrase retirée, jamais la faute).
  - `redaction_get_user_grades_sujets` : ne lire que `finale = 1 AND status IN ('completed','applied')`, la plus récente par copie, **sans** filtre `langue_points` ; note du professeur prioritaire (`note_finale`).
- [ ] **Step 4:** `verif_correction.php` et les autres `verif_*.php` au vert sur la préprod.
- [ ] **Step 5:** Commit `feat(sujets): training attempts and a single final correction, explanatory feedback`.

### Task 4: Soumission finale et essais (services, verrou, date limite)

**Files:**
- Create: `classes/external/submit_final.php`
- Modify: `classes/external/submit_sujets.php`, `db/services.php`, `lib.php` (`redaction_can_submit_attempt`), `lang/*`
- Test: `tests/cli/verif_enseignant.php` (ou nouveau `tests/cli/verif_finale.php`, même harnais)

**Interfaces:**
- Produces: service `mod_redaction_submit_final` (`cmid` → `{success: bool, evaluationid: int, message: string}`) ; `redaction_can_submit_attempt` refuse en mode sujets `already_submitted` (status 1) et `deadline_passed` (date effective dépassée) ; `submit_sujets` = un essai (inchangé sinon).

- [ ] **Step 1: vérifications** : finale verrouille (`status = 1`, `timesubmitted > 0`) ; deuxième appel → `success = false`, une seule finale en base ; deux processus en même temps (même harnais que `verif_attribution.php`) → une seule finale ; essai après finale refusé ; essai et finale après la date limite refusés ; finale possible avec 0 essai restant ; copie avec un sujet vide → finale refusée (`empty_answer`).
- [ ] **Step 2:** échecs constatés.
- [ ] **Step 3:** implémenter (`submit_final` sur le modèle de `submit_sujets` : même `sujets_base::contexte`, même verrou, relecture sous verrou, `status = 1`, `queue_evaluation(…, finale: true)`, événement `submission_submitted` existant si présent).
- [ ] **Step 4:** vérifications au vert.
- [ ] **Step 5:** Commit `feat(sujets): final submission service, locked copy, deadline enforced`.

### Task 5: Date limite automatique et relance des finales

**Files:**
- Create: `classes/task/relancer_finales.php`
- Modify: `classes/task/auto_submit_deadline.php`, `db/tasks.php`, `lang/*`
- Test: `tests/cli/verif_finale.php`

**Interfaces:**
- Produces: `relancer_finales::execute()` ; constantes `RELANCES_MAX = 12`, `ERREURS_DEFINITIVES = ['sujets_corrige_vide', 'sujets_aucun']` (codes reconnus dans `error_message` par leur texte de langue).

- [ ] **Step 1: vérifications** : brouillon avec réponses, date passée → `status = 1` + une finale en file ; brouillon vide, date passée → `status = 1`, aucune correction, pas de note ; brouillon sans date → rien ; finale en échec (`failed`, message « 429 ») → la tâche crée une **nouvelle** ligne finale ; après 12 échecs → plus de relance ; erreur définitive → pas de relance ; une finale réussie → plus rien.
- [ ] **Step 2:** échecs constatés.
- [ ] **Step 3:** implémenter (retirer `AND r.sujets_enabled = 0` et traiter les sujets à part ; date effective par `redaction_get_effective_deadline`).
- [ ] **Step 4:** au vert.
- [ ] **Step 5:** Commit `feat(sujets): auto final submission at the deadline, failed final corrections retried`.

### Task 6: Écrans du professeur

**Files:**
- Modify: `classes/output/grading_sujets.php`, `classes/sujets.php` (`traiter_action`), `pages/grading_sujets.php`, `templates/grading_sujets_overview.mustache`, `templates/grading_sujets_detail.mustache`, `lang/*`, `styles.css`
- Test: `tests/cli/verif_ecran.php`, `tests/cli/verif_enseignant.php`

**Interfaces:**
- Produces: action `rouvrir` (données : `date` facultative, timestamp) : `status = 0`, `timesubmitted = 0`, note retirée du carnet ; date passée sans `date` → refus `sujets_ens_rouvrir_date` ; avec `date` → dérogation `redaction_overrides` (userid en mode élève, groupid en mode groupe) ; action `relancer` (finale échouée, plus de relance automatique) → nouvelle finale en file. Clés d'export : `etat` (`brouillon|soumise|correction|notee`), `isfinale` par correction.

- [ ] **Step 1: vérifications** : colonne « État » dans l'aperçu pour chaque cas ; alerte dès 3 finales en échec sur une copie ; détail : la finale marquée « finale », les essais « essai » ; `rouvrir` remet en brouillon et retire la note ; `rouvrir` après la date sans date → message d'erreur ; avec date → dérogation créée ; `relancer` → une finale en file.
- [ ] **Step 2-4:** échec, implémentation, vert.
- [ ] **Step 5:** Commit `feat(sujets): teacher sees copy state, final vs training, can reopen or retry`.

### Task 7: Page élève à deux colonnes et volet « Mon avancement »

**Files:**
- Modify: `classes/output/sujets_eleve.php`, `templates/sujets_eleve.mustache`, `classes/output/langue_bloc.php`, `templates/langue_bloc.mustache`, `amd/src/sujets.js` (+ `amd/build/sujets.min.js`), `pages/sujets_eleve.php`, `styles.css`, `lang/*`
- Test: `tests/js/test_sujets.js` (+ fixtures `tests/js/fixtures/*.html` régénérées depuis le gabarit), `tests/cli/verif_ecran.php`

**Interfaces:**
- Consumes: services `mod_redaction_submit_sujets` (essai) et `mod_redaction_submit_final` ; `sujets_json` (Task 3) ; `langue_json` avec `regle` / `conseil`.
- Produces: export du volet : `etat`, `datelimite`, `hasdernier`, `dernier` (`note`, `numero`, `atteint`, `sujets[]` : `titre`, `note`, `sur`, `juste`, `a_preciser`, `piste`), `restants`, `max`, `canessai`, `canfinale`, `finale` (note, date) ; `langue_bloc` : `groupes[]` (`type`, `fautes[]` : `cite`, `correction`, `fois`, `regle`), `conseil`.

- [ ] **Step 1: tests JS** : bouton essai → `mod_redaction_submit_sujets` (avec l'avertissement des phrases existant) ; bouton finale → `saveCancelPromise` puis `mod_redaction_submit_final` ; annulation → rien envoyé ; après succès de la finale : zones en lecture seule, deux boutons retirés, état « Correction en cours » ; `canessai = false` → bouton essai absent, finale toujours là.
- [ ] **Step 2: vérification CLI d'écran** : rendu sans `[[`, ancienne correction à un seul `commentaire` affichée ; volet avec ✓ / ✗ / → et leurs mots.
- [ ] **Step 3:** implémenter selon la maquette (grille `minmax(0, 1fr) 420px`, volet `position: sticky`, sous 900 px le volet passe sous la rédaction et une barre fixe en bas reprend l'état et les deux boutons, comme `gp-progress-bottombar` de mod_gestionprojet).
- [ ] **Step 4:** `node tests/js/test_sujets.js` et `node tests/js/test_sujets.js amd/build/sujets.min.js` au vert ; vérifications CLI au vert.
- [ ] **Step 5:** Commit `feat(sujets): two-column student page with the progress panel`.

### Task 8: Livraison préprod et conséquences

- [ ] **Step 1:** déployer en préprod, mise à jour, purge, tous les `verif_*.php` et tests purs/JS au vert.
- [ ] **Step 2:** vault : ligne de la demande 16 (préprod, attente d'accord) ; **nouvelle ligne pour l'agent des activités** : `reussites.php` (`reussites_redaction_moodle`) ne doit lire que `finale = 1` (bloquant, même jour que la production) et le pilotage doit écrire les dates limites en dérogations de groupe `redaction_overrides` ; message à mod-gestionprojet-e5.
- [ ] **Step 3:** montrer la préprod à Emmanuel (page élève réelle de la poutre) avant toute production.
