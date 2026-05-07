# Évaluations IA repliables — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Spec source :** `docs/superpowers/specs/2026-05-07-ai-evaluations-collapsible-design.md`

**Goal :** Rendre chaque bloc d'évaluation IA (sidebar de `grading.php`) repliable, et n'afficher dépliée par défaut que la dernière évaluation (`is_latest`).

**Architecture :** Réutilisation du pattern collapsible existant (`mod_redaction-ai-section-open` + `toggleSection`) appliqué un cran au-dessus, au niveau du `mod_redaction-ai-attempt-block`. Une nouvelle fonction JS `toggleAttempt(headerElement)` complète `toggleSection` (cible différente, gestion `aria-expanded`).

**Tech Stack :** PHP 8.1 (Moodle 4.5+), Mustache, JavaScript AMD (jQuery, core/ajax, core/notification), CSS vanilla, terser pour le rebuild AMD.

---

## File Structure

| Fichier | Type | Responsabilité |
|---|---|---|
| `redaction/lang/en/redaction.php` | Modify | Référence i18n : nouvelles clés `attempt_toggle`, `attempt_summary_grade` |
| `redaction/lang/fr/redaction.php` | Modify | Traductions FR correspondantes |
| `redaction/amd/src/grading_actions.js` | Modify | Ajouter `toggleAttempt()`, exposition window, listener clavier (Enter/Space) |
| `redaction/templates/ai_evaluation.mustache` | Modify | Wrapper collapsible : header cliquable + `<div class="mod_redaction-ai-attempt-content">` + chevron + résumé note |
| `redaction/styles.css` | Modify | Règles pour `mod_redaction-ai-attempt-header`, `-content`, `-summary-grade`, état ouvert/fermé |
| `redaction/amd/build/grading_actions.min.js` | Modify | Rebuild via terser (commande dans la mémoire `feedback_amd_minify_at_end_of_dev`) |

Aucun fichier créé — tout est modification de fichiers existants.

---

## Task 1 : Ajouter les chaînes de langue

**Files :**
- Modify : `redaction/lang/en/redaction.php` (insertion près de `attempt_latest_badge` ligne 464)
- Modify : `redaction/lang/fr/redaction.php` (même clé, traduction)

- [ ] **Step 1 : Ajouter la clé EN après `attempt_latest_badge`**

Localiser dans `redaction/lang/en/redaction.php` la ligne :
```php
$string['attempt_latest_badge'] = 'Latest attempt';
```

Insérer juste après :
```php
$string['attempt_toggle'] = 'Toggle attempt details';
$string['attempt_summary_grade'] = 'Grade: {$a}';
```

- [ ] **Step 2 : Ajouter la traduction FR au même endroit**

Dans `redaction/lang/fr/redaction.php`, après la ligne équivalente `$string['attempt_latest_badge']`, insérer :
```php
$string['attempt_toggle'] = 'Afficher/masquer les détails de la tentative';
$string['attempt_summary_grade'] = 'Note : {$a}';
```

- [ ] **Step 3 : Vérifier la syntaxe PHP des deux fichiers**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
php -l redaction/lang/en/redaction.php
php -l redaction/lang/fr/redaction.php
```
Expected : `No syntax errors detected` pour chaque fichier.

- [ ] **Step 4 : Vérifier que les clés sont symétriques entre EN et FR**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
diff <(grep -oE "\\\$string\['[^']+'\]" redaction/lang/en/redaction.php | sort -u) \
     <(grep -oE "\\\$string\['[^']+'\]" redaction/lang/fr/redaction.php | sort -u)
```
Expected : aucun output (clés identiques de part et d'autre).

- [ ] **Step 5 : Commit**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
git add redaction/lang/en/redaction.php redaction/lang/fr/redaction.php
git commit -m "$(cat <<'EOF'
i18n(redaction): add strings for collapsible AI attempt headers

Adds attempt_toggle (aria-label) and attempt_summary_grade (header
preview) strings used by the upcoming collapsible AI evaluation
blocks in the grading sidebar.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2 : Ajouter `toggleAttempt()` et le support clavier au module AMD

**Files :**
- Modify : `redaction/amd/src/grading_actions.js` (ajout d'une méthode + extension de `init`)

- [ ] **Step 1 : Ajouter la méthode `toggleAttempt` au retour du module**

Dans `redaction/amd/src/grading_actions.js`, juste après le bloc `toggleSection: function(toggleElement) { ... }` (vers la ligne 118), insérer la nouvelle méthode :

```javascript
        toggleAttempt: function(headerElement) {
            // Walk up to the parent attempt block and toggle the open state.
            // CSS hides .mod_redaction-ai-attempt-content unless the parent block
            // carries .mod_redaction-ai-section-open.
            var block = headerElement.closest('.mod_redaction-ai-attempt-block');
            if (!block) {
                return;
            }
            var isOpen = block.classList.toggle('mod_redaction-ai-section-open');
            headerElement.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        },
```

- [ ] **Step 2 : Exposer `toggleAttempt` sur `window` dans `init`**

Toujours dans `grading_actions.js`, dans la méthode `init`, juste après la ligne `window.toggleSection = this.toggleSection;` (ligne 28), ajouter :

```javascript
            window.toggleAttempt = this.toggleAttempt;
```

- [ ] **Step 3 : Ajouter un listener clavier (Enter / Space) pour les headers d'attempt**

Toujours dans `init`, à la fin de la méthode (juste avant la `}` qui ferme `init`), ajouter une délégation d'événement :

```javascript
            // Keyboard support for collapsible attempt headers.
            // Delegated listener avoids re-binding when blocks are re-rendered.
            document.addEventListener('keydown', function(ev) {
                if (ev.key !== 'Enter' && ev.key !== ' ' && ev.key !== 'Spacebar') {
                    return;
                }
                var target = ev.target;
                if (!target || !target.classList ||
                    !target.classList.contains('mod_redaction-ai-attempt-header')) {
                    return;
                }
                ev.preventDefault();
                if (typeof window.toggleAttempt === 'function') {
                    window.toggleAttempt(target);
                }
            });
```

- [ ] **Step 4 : Vérifier la syntaxe JS du source**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
node --check redaction/amd/src/grading_actions.js
```
Expected : aucune sortie (pas d'erreur de syntaxe).

- [ ] **Step 5 : Commit (la rebuild du min suit en Task 6)**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
git add redaction/amd/src/grading_actions.js
git commit -m "$(cat <<'EOF'
feat(grading): add toggleAttempt + keyboard support for collapsible AI blocks

Introduces a toggleAttempt() helper that flips
mod_redaction-ai-section-open on the closest .mod_redaction-ai-attempt-block
and keeps aria-expanded in sync. Adds a delegated keydown listener so
Enter/Space activate attempt headers for keyboard users.

The matching minified bundle is rebuilt in a follow-up build commit.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3 : Rendre chaque bloc d'évaluation IA collapsible dans le template

**Files :**
- Modify : `redaction/templates/ai_evaluation.mustache` (lignes ~84-99 pour le wrapper, lignes ~99-253 pour envelopper le contenu)

- [ ] **Step 1 : Ajouter la classe d'état conditionnelle au wrapper du bloc**

Localiser la ligne :
```mustache
<div class="mod_redaction-ai-attempt-block{{#is_latest}} mod_redaction-ai-attempt-latest{{/is_latest}}">
```

La remplacer par :
```mustache
<div class="mod_redaction-ai-attempt-block{{#is_latest}} mod_redaction-ai-attempt-latest mod_redaction-ai-section-open{{/is_latest}}">
```

- [ ] **Step 2 : Transformer le header en bouton toggle**

Localiser le bloc :
```mustache
            {{! Attempt header with date and latest badge }}
            <div class="mod_redaction-ai-attempt-header">
                <span class="mod_redaction-ai-attempt-label">
                    {{#str}}training_attempt, redaction, {{attemptnum}}{{/str}}
                    &mdash; {{dateformatted}}
                </span>
                {{#is_latest}}
                <span class="badge badge-primary mod_redaction-ai-latest-badge">
                    {{#str}}attempt_latest_badge, redaction{{/str}}
                </span>
                {{/is_latest}}
            </div>
```

Le remplacer par :
```mustache
            {{! Collapsible header — clicking or pressing Enter/Space toggles
                the .mod_redaction-ai-section-open class on the parent block. }}
            <div class="mod_redaction-ai-attempt-header"
                 role="button"
                 tabindex="0"
                 aria-expanded="{{#is_latest}}true{{/is_latest}}{{^is_latest}}false{{/is_latest}}"
                 aria-label="{{#str}}attempt_toggle, redaction{{/str}}"
                 onclick="toggleAttempt(this)">
                <span class="mod_redaction-ai-attempt-label">
                    {{#str}}training_attempt, redaction, {{attemptnum}}{{/str}}
                    &mdash; {{dateformatted}}
                </span>
                {{#is_latest}}
                <span class="badge badge-primary mod_redaction-ai-latest-badge">
                    {{#str}}attempt_latest_badge, redaction{{/str}}
                </span>
                {{/is_latest}}
                {{#iscompleted}}
                <span class="mod_redaction-ai-attempt-summary-grade mod_redaction-ai-level-{{gradelevel}}">
                    {{grade}}/20
                </span>
                {{/iscompleted}}
                <span class="mod_redaction-toggle-icon" aria-hidden="true">&#9660;</span>
            </div>
```

- [ ] **Step 3 : Envelopper le contenu (pending / failed / completed) dans `mod_redaction-ai-attempt-content`**

Juste après la fermeture `</div>` du header inséré au Step 2, AVANT le bloc `{{#ispending}}`, ajouter :
```mustache
            <div class="mod_redaction-ai-attempt-content">
```

Puis, juste AVANT la fermeture `</div>` qui ferme `mod_redaction-ai-attempt-block` (cherche la ligne `</div>` suivie de `{{/evaluations}}` à la ligne ~253), ajouter une `</div>` supplémentaire pour fermer le wrapper de contenu :

Avant modification :
```mustache
                </div>
            {{/iscompleted}}

        </div>
        {{/evaluations}}
```

Après modification :
```mustache
                </div>
            {{/iscompleted}}

            </div>{{! /mod_redaction-ai-attempt-content }}
        </div>
        {{/evaluations}}
```

- [ ] **Step 4 : Mettre à jour le bloc d'en-tête de documentation Mustache**

En haut du fichier (commentaire `@template`), ajouter aux variables documentées une ligne pour `gradelevel` (déjà présent dans la liste) et confirmer que `is_latest` pilote l'état initial. Si `gradelevel` est déjà documenté, aucun changement nécessaire — vérifier en relisant le commentaire ligne 25 (`gradelevel - Level string ...`). Pas d'autre modification de doc requise.

- [ ] **Step 5 : Vérifier qu'aucun balise Mustache n'est restée déséquilibrée**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
# Compte les ouvertures/fermetures de balises Mustache section pour repérer les déséquilibres.
grep -oE "\{\{#[a-z_]+\}\}|\{\{\^[a-z_]+\}\}|\{\{/[a-z_]+\}\}" redaction/templates/ai_evaluation.mustache | \
  awk '/\{\{#|\{\{\^/ {open[$0]++} /\{\{\// {close[$0]++} END {for (k in open) printf "%s open=%d\n", k, open[k]; for (k in close) printf "%s close=%d\n", k, close[k]}'
```
Vérifier visuellement que pour chaque section ouverte (`{{#x}}` ou `{{^x}}`), il y a un `{{/x}}` correspondant. Compter manuellement les `<div>` autour du bloc attempt pour confirmer l'équilibre.

- [ ] **Step 6 : Commit**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
git add redaction/templates/ai_evaluation.mustache
git commit -m "$(cat <<'EOF'
feat(grading): collapsible AI attempt blocks in grading sidebar

Each attempt block is now collapsible. The header acts as a toggle
button (role=button, tabindex, aria-expanded, aria-label) and
displays a compact grade pill so teachers can scan attempts at a
glance without expanding. Only the latest attempt opens by default
via the existing mod_redaction-ai-section-open state class.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4 : Ajouter les règles CSS pour le collapsible attempt-block

**Files :**
- Modify : `redaction/styles.css` (append en fin de fichier, après la dernière règle ligne 2036)

- [ ] **Step 1 : Append la nouvelle section CSS en fin de `styles.css`**

À la fin de `redaction/styles.css` (après la ligne 2036, qui ferme `.mod_redaction-ai-section-open .mod_redaction-toggle-icon`), ajouter :

```css

/* === AI attempt blocks — collapsible per attempt === */
/* Header is the toggle target. */
.mod_redaction .mod_redaction-ai-attempt-header {
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding: 8px 4px;
    user-select: none;
}

.mod_redaction .mod_redaction-ai-attempt-header:focus-visible {
    outline: 2px solid #667eea;
    outline-offset: 2px;
    border-radius: 4px;
}

/* Push the chevron to the far right of the header row. */
.mod_redaction .mod_redaction-ai-attempt-block > .mod_redaction-ai-attempt-header .mod_redaction-toggle-icon {
    margin-left: auto;
    transition: transform 0.2s ease;
    font-size: 12px;
    color: #6b7280;
}

/* Open state rotates the chevron. */
.mod_redaction .mod_redaction-ai-attempt-block.mod_redaction-ai-section-open
    > .mod_redaction-ai-attempt-header .mod_redaction-toggle-icon {
    transform: rotate(180deg);
}

/* Content is hidden by default, shown when the parent block is open. */
.mod_redaction .mod_redaction-ai-attempt-content {
    display: none;
}

.mod_redaction .mod_redaction-ai-attempt-block.mod_redaction-ai-section-open
    > .mod_redaction-ai-attempt-content {
    display: block;
}

/* Compact grade pill shown in collapsed-attempt headers. */
.mod_redaction .mod_redaction-ai-attempt-summary-grade {
    font-weight: 600;
    font-size: 13px;
    padding: 2px 10px;
    border-radius: 12px;
    background: #f1f5f9;
    color: #1f2937;
    line-height: 1.4;
    white-space: nowrap;
}
```

- [ ] **Step 2 : Vérifier que le fichier CSS reste parsable**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
# Petit sanity check : pas d'accolades déséquilibrées.
awk 'BEGIN{o=0;c=0} {for(i=1;i<=length($0);i++){ch=substr($0,i,1); if(ch=="{")o++; if(ch=="}")c++}} END{printf "open=%d close=%d\n", o, c}' redaction/styles.css
```
Expected : `open=N close=N` avec N identique des deux côtés.

- [ ] **Step 3 : Vérifier que toutes les nouvelles règles sont préfixées `.mod_redaction`**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
# Les nouvelles règles ajoutées doivent toutes commencer par `.mod_redaction`.
tail -50 redaction/styles.css | grep -nE "^\." | grep -vE "^\d+:\.mod_redaction" || echo "OK: tous les sélecteurs sont préfixés"
```
Expected : `OK: tous les sélecteurs sont préfixés`. Si une ligne sort, corriger en préfixant `.mod_redaction `.

- [ ] **Step 4 : Commit**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
git add redaction/styles.css
git commit -m "$(cat <<'EOF'
style(redaction): add collapsible CSS for AI attempt blocks

Adds focus-visible outline, chevron rotation, content
show/hide rules, and the summary-grade pill used by the
collapsed-attempt header. All new selectors are prefixed
with .mod_redaction per project convention.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5 : Rebuild du bundle AMD minifié

**Files :**
- Modify : `redaction/amd/build/grading_actions.min.js` (régénération via terser)

- [ ] **Step 1 : Régénérer le bundle minifié via terser**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
npx -y terser@latest redaction/amd/src/grading_actions.js \
    --compress --mangle \
    --output redaction/amd/build/grading_actions.min.js
```
Expected : pas d'erreur, le fichier `redaction/amd/build/grading_actions.min.js` est mis à jour.

- [ ] **Step 2 : Vérifier la syntaxe du bundle**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
node --check redaction/amd/build/grading_actions.min.js
```
Expected : aucune sortie.

- [ ] **Step 3 : Spot-check qu'un littéral représentatif est bien préservé**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
grep -c "mod_redaction-ai-attempt-block" redaction/amd/build/grading_actions.min.js
grep -c "mod_redaction-ai-section-open" redaction/amd/build/grading_actions.min.js
grep -c "aria-expanded" redaction/amd/build/grading_actions.min.js
```
Expected : chaque commande retourne `1` ou plus (les chaînes ont survécu à la minification).

- [ ] **Step 4 : Commit**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
git add redaction/amd/build/grading_actions.min.js
git commit -m "$(cat <<'EOF'
build(amd): rebuild grading_actions.min.js with toggleAttempt

Picks up the new toggleAttempt helper, window exposure, and
keyboard listener added to grading_actions.js so the production
bundle matches the source.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6 : Recette manuelle (validation comportementale)

**Files :** aucun (smoke test sur l'environnement de pré-prod renseigné dans `TESTING.md`).

> Référence des credentials et URLs : `TESTING.md` à la racine du projet.

- [ ] **Step 1 : Déployer les fichiers modifiés sur la pré-prod** selon la procédure documentée dans `TESTING.md`. Purger le cache Moodle (`Administration du site → Développement → Purger les caches`) pour invalider les bundles AMD et les templates Mustache.

- [ ] **Step 2 : Ouvrir la vue détaillée d'une soumission ayant ≥ 2 évaluations IA**

Compte enseignant, accéder à une activité `mod_redaction` qui contient au moins une soumission ré-évaluée plusieurs fois (ex. via le bouton "Re-évaluer" appliqué deux fois sur le même élève en pré-prod). Aller sur l'onglet "Vue détaillée".

Attendu : la dernière évaluation est dépliée (note + critères + feedback visibles, badge "latest" présent). Toutes les évaluations précédentes sont repliées : on voit uniquement le numéro de tentative, la date, et un pill `XX.X/20` coloré à droite.

- [ ] **Step 3 : Cliquer sur le header d'une évaluation repliée**

Attendu : le bloc se déplie, le chevron pivote (180°), `aria-expanded` passe à `true` (vérifiable dans l'inspecteur DevTools).

- [ ] **Step 4 : Re-cliquer sur le header pour replier**

Attendu : le contenu se masque, le chevron revient à sa position initiale, `aria-expanded="false"`.

- [ ] **Step 5 : Tester la navigation clavier**

Cliquer dans le header avec Tab jusqu'à ce qu'un header d'attempt soit focus (un outline bleu doit apparaître). Appuyer sur `Enter` puis sur `Space` : chaque pression doit basculer l'état du bloc.

- [ ] **Step 6 : Cas limite — soumission avec une seule évaluation**

Aller sur un élève qui n'a qu'une évaluation IA. Attendu : ce bloc unique est dépliée par défaut (`is_latest` = true), comportement identique à avant.

- [ ] **Step 7 : Cas limite — aucune évaluation**

Aller sur un élève qui n'a pas encore été évalué. Attendu : le message "no_ai_evaluation" et le bouton `evaluate_ai` s'affichent inchangés ; aucun bloc d'attempt n'est rendu.

- [ ] **Step 8 : Vérifier qu'il n'y a pas de régression sur les sections internes**

Sur le bloc dépliée (latest), cliquer sur les sous-sections "feedback", "keywords", "suggestions" : elles doivent toujours être pliables/dépliables comme avant (ne pas confondre avec le toggle d'attempt). L'événement clic ne doit pas se propager indésirablement et fermer le bloc parent.

> Si le clic sur une sous-section ferme le bloc parent, ajouter un `event.stopPropagation()` dans `toggleSection` ou un `if (target.closest('.mod_redaction-ai-section-toggle')) return;` au début de `toggleAttempt`. À traiter comme bug bloquant si rencontré.

- [ ] **Step 9 : Si tout est OK, marquer la recette comme passée**

Aucun commit nécessaire ici (recette manuelle). Si un bug est rencontré, documenter et corriger en revenant sur la tâche concernée.

---

## Task 7 : Mise à jour du backlog projet

**Files :**
- Modify (optionnel) : `docs/superpowers/audit/2026-05-06-pre-publication-audit.md` si la check-list mentionne ce point

- [ ] **Step 1 : Vérifier si l'audit liste l'absence de collapsible des évaluations IA**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
grep -n "collapsible\|repliable\|attempt block" docs/superpowers/audit/2026-05-06-pre-publication-audit.md 2>/dev/null || echo "rien à mettre à jour"
```

Si une entrée existe, la cocher comme résolue. Sinon, passer.

- [ ] **Step 2 : Aucun commit si rien n'a changé.** Si une mise à jour de l'audit a été faite :

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
git add docs/superpowers/audit/
git commit -m "$(cat <<'EOF'
docs(audit): mark AI evaluation collapsible as done

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review (post-rédaction)

**Spec coverage :**
- Comportement initial (latest dépliée, autres repliées) → Task 3 step 1 (classe conditionnelle)
- Header cliquable + chevron + aperçu de note → Task 3 step 2
- État local non persisté → garanti by-design (aucun stockage ajouté)
- Accessibilité (`role`, `tabindex`, `aria-expanded`, clavier Enter/Space, focus visible) → Task 2 steps 1-3 + Task 3 step 2 + Task 4 step 1 (`:focus-visible`)
- Réutilisation de `mod_redaction-ai-section-open` → Task 3 step 1 + Task 4 step 1
- i18n via `get_string()` → Task 1 + Task 3 (utilise `{{#str}} attempt_toggle, redaction {{/str}}`)
- Pas de CSS inline ajouté → Task 4 (tout dans styles.css avec préfixe `.mod_redaction`)
- Rebuild AMD → Task 5
- Recette manuelle (cas nominaux + limites) → Task 6
- Tous les critères de réussite de la spec sont couverts.

**Placeholder scan :** aucun TBD/TODO. Toutes les commandes sont concrètes. Tous les snippets de code sont complets, prêts à coller.

**Type/identifier consistency :**
- Classe d'état : `mod_redaction-ai-section-open` partout (Task 2 JS, Task 3 Mustache, Task 4 CSS).
- Wrapper contenu : `mod_redaction-ai-attempt-content` partout (Task 3 + Task 4).
- Fonction JS : `toggleAttempt` partout (Task 2 définition, Task 2 exposition window, Task 3 onclick, Task 2 listener clavier).
- Header class : `mod_redaction-ai-attempt-header` (préexistant) — inchangée et utilisée comme sélecteur du listener clavier (Task 2) et du focus-visible (Task 4).

Pas d'incohérence détectée.
