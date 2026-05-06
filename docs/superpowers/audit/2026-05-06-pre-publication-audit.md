# Pre-publication audit — mod_redaction

- **Date** : 2026-05-06
- **Auteur** : audit automatisé Claude (sub-agent)
- **Plugin** : `mod_redaction` v2.2.0 (`$plugin->version = 2026050603`)
- **Reference review** : CONTRIB-10280 (Volodymyr Dovhan, Moodle Plugin Directory)

## Verdict

Le plugin est **structurellement en bonne forme** : External Services API, Privacy provider, backup/restore, capabilities, events, message providers, scheduled tasks, mobile descriptor, AMD avec build artefacts — tous présents et bien formés. Standards de code (pas de superglobales, pas de fonctions de debug résiduelles, pas de SQL brute, MOODLE_INTERNAL guards) sont propres.

Avant soumission au **Moodle Plugin Directory**, il reste **4 blockers critiques** (3 ré-activent des points spécifiquement flaggés dans CONTRIB-10280) et **6 issues importantes**.

**Décision (2026-05-06)** : ne rien fixer maintenant. Le plugin tourne en prod, les nouveautés UX (actions groupées + nouveau layout évaluation IA) sont validées. La phase de préparation à la soumission Moodle se fera dans une session dédiée.

---

## Critique (bloquant Plugin Directory)

### C1 — Inline CSS extensif dans les templates et JS

~69 attributs `style="..."` statiques (cosmétiques, pas dynamiques) à travers :
- `redaction/templates/dashboard_teacher.mustache:48–225` — `border-radius`, `box-shadow`, `gap`, `font-size`, `linear-gradient(...)`, couleurs `#1e1b4b`…
- `redaction/templates/redaction.mustache:277` — `padding: 15px; background: #f8f9fa;`
- `redaction/templates/grading_form.mustache:19` — `margin-bottom: 15px; font-size: 16px;`
- `redaction/templates/correction_model.mustache:136` — `font-family: monospace; font-size: 12px; margin-top: 8px;`
- `redaction/templates/consignes.mustache:63` — `padding: 15px; background: #f8f9fa;`
- + d'autres dans `home.mustache`, `submission_panel.mustache`, `training_timeline.mustache`, `ai_evaluation.mustache`.
- `redaction/amd/src/grading_actions.js:64,150,204` — `'<span class="mod_redaction-spinner" style="display:inline-block;width:16px;height:16px;…"></span>'` injecté en JS.

**Action** : Tout migrer vers `redaction/styles.css` avec des classes préfixées `.mod_redaction-`. Garder uniquement les valeurs vraiment dynamiques (`width: {{percent}}%`, `left: {{positionpercent}}%`, etc.).

C'est **la critique principale** de Volodymyr Dovhan dans CONTRIB-10280.

### C2 — Endpoints AJAX legacy morts mais toujours présents

4 fichiers, 499 lignes au total :
- `redaction/ajax/apply_ai_grade.php` (95 LOC)
- `redaction/ajax/autosave.php` (174 LOC)
- `redaction/ajax/evaluate.php` (94 LOC)
- `redaction/ajax/submit.php` (136 LOC)

Plus invoqués par aucun module JS (la seule trace est documentaire dans `redaction/TECHNICAL.md:274,486`). Le reviewer Moodle a explicitement demandé à passer aux External Services — ces fichiers contredisent cette consigne.

**Action** : `git rm redaction/ajax/{apply_ai_grade,autosave,evaluate,submit}.php`. Vérifier au passage que rien dans `db/messages.php` / `db/events.php` / templates ne les pointe encore.

### C3 — Lang string `view_grade` manquante

Référencée à `redaction/classes/notification_manager.php:143` et `:264` via `get_string('view_grade', 'redaction')`. Absente de `lang/en/redaction.php` ET `lang/fr/redaction.php`.

→ Les emails de notification afficheront `[[view_grade]]` à la place du libellé.

**Action** : Ajouter `$string['view_grade'] = 'View grade';` (EN) et `'Voir la note'` (FR).

### C4 — Discordance triple sur le bugtracker

| Source | URL |
|---|---|
| `version.php:32` (`$plugin->bugtracker`) | `https://forge.apps.education.fr/moodle-ai-plugins/plugin-redaction/-/issues` |
| `CLAUDE.md` ("Dépôt") | `https://github.com/music-practice-tools/moodle-mod_redaction` |
| Remote `github` configuré | `https://github.com/Manu-REMY/mod_redaction.git` |

Le Moodle Plugin Directory exige un bugtracker **public** avec issues activées. Les trois URLs n'ont pas le même hébergeur ni la même org.

**Action** : Décider quel dépôt est canonique, aligner les trois (`version.php`, `CLAUDE.md`, remote git), s'assurer que les issues sont actives sur le canonique.

---

## Important (à fixer avant soumission)

### I1 — Bug d'injection des strings JS bulk_*

`redaction/amd/src/grading_actions.js` référence `config.strings.bulk_evaluate`, `bulk_apply`, `bulk_apply_confirm`, `bulk_apply_success`, `bulk_applying`, `bulk_evaluate_success`, `bulk_evaluating`, `no_evaluations` (lignes 151, 162, 172, 192, 196, 205, 216, 226). Mais l'appel `js_call_amd` dans `redaction/grading.php:631-640` ne passe que 8 strings différentes — **aucune des `bulk_*`**.

→ Les fallbacks anglais hardcodés sont systématiquement utilisés.

**Action** : Soit ajouter les `bulk_*` au tableau strings injecté côté PHP, soit retirer les chemins de code morts.

### I2 — Module `amd/src/autosave.js` mort

`autosave.js` n'est plus invoqué par aucune page (l'autosave est désormais dans `redaction_page.js`). Référence interne morte au `self.strings.unsaved` qui n'est jamais injecté.

**Action** : `git rm redaction/amd/src/autosave.js redaction/amd/build/autosave.min.js`.

### I3 — Méthode morte `ai_response_parser::format_for_display()`

`redaction/classes/ai_response_parser.php:236-313` — 76 lignes de HTML construit par concaténation de strings. Appelée uniquement par les tests `test_format_for_display*` dans `redaction/tests/ai_response_parser_test.php`.

**Action** : Supprimer la méthode + les 3 tests associés.

### I4 — Shells HTML directement dans `grading.php`

`redaction/grading.php:223-621` — environ 17 `echo '<div class="…">'` / `echo '</div>'` qui composent l'enveloppe externe de la page de grading. Pas de texte visible, mais c'est du HTML brut dans du PHP — anti-pattern Moodle.

**Action** : Créer un template `templates/grading_shell.mustache` et y déplacer les wrappers, rendre via `$OUTPUT->render_from_template()`.

### I5 — Fallbacks anglais hardcodés en JS

Dans `redaction/amd/src/grading_actions.js` :
- `:50, :77, :100, :168, :222` — `alert(data.message || 'Error');`
- `:142` — `alert('No submissions found.');`
- `:151` — `'Evaluating all...'`
- `:162` — `'{queued} queued, {skipped} skipped'`
- `:172` — `'Evaluate all'`
- `:192` — `'No evaluations to apply.'`
- `:196` — `'Apply all AI grades?'`
- `:205` — `'Applying...'`
- `:216` — `'{applied} applied, {skipped} skipped'`
- `:226` — `'Apply all grades'`

**Action** : Idem I1 (passer par `Str.get_string`) ou supprimer si chemins de code morts.

### I6 — `redaction.zip` n'exclut pas `.DS_Store`

La commande de build dans `CLAUDE.md` est `zip -r redaction.zip redaction/ -x "redaction/.git/*"`. Manque l'exclusion `.DS_Store`.

**Action** : `zip -r redaction.zip redaction/ -x "redaction/.git/*" -x "**/.DS_Store"`. Mettre à jour `CLAUDE.md` et le `.gitignore`.

---

## Mineur

- **M1** — Inconsistance composant : 146 `get_string(…, 'redaction')` vs 78 `get_string(…, 'mod_redaction')`. Choisir un et appliquer partout.
- **M2** — ~146 clés dans `lang/en/redaction.php` jamais référencées (ex. `bulk_apply_grade`, `compare_versions`, `criterion_*`, `dashboard_ai_stats`, `error:cannotgrade`, `error:rate_limit_exceeded`, `grille_criteres_*`). Pruner pour réduire le bruit.
- **M3** — Capability `mod/redaction:viewallsubmissions` déclarée dans `db/access.php` mais jamais vérifiée dans le code.
- **M4** — 4 `console.error()` en JS (`autosave.js:225`, `grading_actions.js:54,104,280`). À remplacer par `Notification.exception` pour rester dans les conventions Moodle.
- **M5** — `redaction/TECHNICAL.md` (22 KB) embarqué dans le ZIP. Documentation interne — déplacer hors du dossier shipped (par ex. dans `docs/` à la racine du repo, pas dans `redaction/`).
- **M6** — Privacy provider : 4 tables non déclarées (`redaction`, `redaction_consignes`, `redaction_correction`, `redaction_ai_summaries`). Légitime car aucune donnée personnelle, mais ajouter un commentaire explicatif dans `get_metadata()` pour devancer la question du reviewer.

---

## Hors-scope (passé)

- Internationalisation : EN/FR ont une parité parfaite (460 clés chacun). ✅
- Pas de `$_GET`, `$_POST`, `$_REQUEST` directs. ✅
- Pas de `error_log()`, `var_dump()`, `print_r()`. ✅
- Pas de SQL brute / `mysql_*` / `mysqli_*` / `PDO` direct. ✅
- `db/access.php` couvre toutes les capabilities utilisées en code (sauf `:viewallsubmissions` orpheline — voir M3). ✅
- Privacy API présente avec `metadata`, `plugin\provider`, `core_userlist_provider`. ✅
- `backup/moodle2/` complet (backup_task + restore_task + stepslib). ✅
- `version.php` au bon format (`YYYYMMDDXX`), `requires`, `maturity`, `release` corrects. ✅
- AMD `src/` ↔ `build/` alignés (mtime des build ≥ source). ✅
- `require_login()` + `require_capability()` sur chaque page d'entrée. ✅
- `require_sesskey()` sur tous les endpoints qui mutent (même les morts). ✅
- Pas de TODO/FIXME/XXX dans le code. ✅
