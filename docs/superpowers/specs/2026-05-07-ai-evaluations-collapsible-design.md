# Évaluations IA repliables dans la vue détaillée des travaux d'un élève

**Date** : 2026-05-07
**Composant** : `mod_redaction`
**Fichier d'entrée** : `grading.php` (vue détaillée d'une soumission élève côté enseignant)
**Template ciblé** : `templates/ai_evaluation.mustache`

## Contexte

La vue détaillée des travaux d'un élève (`grading.php`) affiche, dans la sidebar AI, l'historique complet des évaluations IA pour la soumission courante : un bloc `mod_redaction-ai-attempt-block` par tentative, du plus récent au plus ancien, la dernière étant marquée d'un badge "latest".

Aujourd'hui, **toutes les évaluations sont entièrement dépliées en permanence**. Quand une soumission a été ré-évaluée plusieurs fois, la sidebar devient très longue : note, critères, feedback, mots-clés et suggestions sont répétés N fois. L'enseignant doit scroller et perd la lisibilité de la dernière évaluation, qui est pourtant celle qui pilote l'action de notation.

## Objectif

Permettre à l'enseignant de plier/déplier chaque évaluation IA, et n'afficher dépliée que la **dernière** par défaut.

## Comportement attendu

### État initial à l'ouverture de la page
- L'évaluation **`is_latest`** : dépliée.
- Toutes les autres évaluations (anciennes tentatives) : repliées.
- Si une seule évaluation existe : dépliée (cas `is_latest === true`, donc cohérent).
- S'il n'y a aucune évaluation : aucun changement (le message "no_ai_evaluation" reste affiché tel quel).

### Interaction
- Cliquer sur le **header** d'un bloc d'évaluation (zone qui contient déjà le numéro de tentative, la date et éventuellement le badge "latest") bascule l'état déplié/replié.
- Une icône chevron à droite du header indique l'état (▼ déplié, ▶ replié, via rotation CSS).
- L'état est **purement local à la session de la page** : pas de persistance en base ni en `localStorage`. Recharger la page ramène l'état initial (dernière dépliée, autres repliées).

### Aperçu visible quand replié
Le header d'un bloc replié reste informatif :
- Numéro de tentative (ex. "Tentative 3")
- Date formatée
- Badge "latest" si applicable (n'a normalement pas lieu d'être replié, mais reste cohérent)
- **Aperçu de la note** : `14.5/20` coloré selon le `gradelevel` (excellent/good/medium/low) — permet à l'enseignant de scanner les progrès sans déplier
- Pour les évaluations en `pending` ou `failed` : pas de note dans l'aperçu, juste un libellé d'état déjà géré par les sections internes (qui resteront dans le contenu replié).

### Accessibilité
- Le header cliquable expose `role="button"`, `tabindex="0"`, `aria-expanded="true"|"false"` mis à jour à chaque toggle.
- Support clavier : `Enter` et `Space` déclenchent le toggle.
- L'icône chevron est purement décorative (`aria-hidden="true"`).

## Approche technique

### Réutilisation du pattern collapsible existant

Le code possède déjà une mécanique éprouvée pour les sections internes rétractables (general feedback, keywords, suggestions) :
- Classe d'état `mod_redaction-ai-section-open` qui, en CSS, affiche `mod_redaction-ai-section-content` et fait pivoter `mod_redaction-toggle-icon`.
- Handler JS `toggleSection(this)` qui bascule cette classe sur l'ancêtre `.mod_redaction-ai-criteria-section`.

On étend ce pattern à un cran au-dessus, au niveau du `mod_redaction-ai-attempt-block`. La même classe d'état (`mod_redaction-ai-section-open`) est réutilisée pour rester cohérent.

### Modifications par fichier

#### `templates/ai_evaluation.mustache`
- Sur le `<div class="mod_redaction-ai-attempt-block">`, ajouter conditionnellement `mod_redaction-ai-section-open` quand `is_latest`.
- Restructurer le bloc en deux parties :
  1. **Header** (`mod_redaction-ai-attempt-header` existant) — le rendre cliquable :
     - Ajouter `role="button"`, `tabindex="0"`, `aria-expanded` (valeur `true` si `is_latest`, sinon `false`), un `aria-label` localisé (`{{#str}}attempt_toggle, redaction{{/str}}`), et `onclick="toggleAttempt(this)"`.
     - Y ajouter, après le badge "latest", le résumé de note (`{{#iscompleted}}<span class="mod_redaction-ai-attempt-summary-grade mod_redaction-ai-level-{{gradelevel}}">{{grade}}/20</span>{{/iscompleted}}`).
     - Y ajouter une icône chevron (`<span class="mod_redaction-toggle-icon" aria-hidden="true">&#9660;</span>`).
  2. **Contenu** : envelopper tout ce qui suit le header (`{{#ispending}}`, `{{#isfailed}}`, `{{#iscompleted}}`) dans un `<div class="mod_redaction-ai-attempt-content">`.

Le sous-template AI interne (sections critères/feedback/keywords/suggestions) n'est pas modifié.

#### `amd/src/grading_actions.js`
- Ajouter une fonction publique `toggleAttempt(headerElement)` exposée sur `window` au même titre que `toggleSection` :
  - Remonter au `.mod_redaction-ai-attempt-block` parent.
  - Basculer `mod_redaction-ai-section-open`.
  - Mettre à jour `aria-expanded` du header en fonction du nouvel état.
- Ajouter un listener clavier (Enter/Space) sur tout élément avec `[data-mod-redaction-toggle]` ou directement sur les headers concernés via une initialisation au `init`. Approche retenue : délégation d'événement au niveau du conteneur `mod_redaction-ai-evaluation-container` pour `keydown` (Enter / Space).
- Ne pas toucher à `toggleSection` existant — on préfère une fonction séparée parce que la cible (`closest`) diffère et la mise à jour d'`aria-expanded` n'a de sens qu'au niveau attempt.

#### `styles.css`
Toutes les règles préfixées `.mod_redaction` (contrainte projet). Ajouter dans une nouvelle section "AI evaluation collapsible attempts" :

- `.mod_redaction .mod_redaction-ai-attempt-header` : `cursor: pointer`, `display: flex`, `align-items: center`, `gap: …` — à harmoniser avec le rendu existant. Ajouter un `:focus-visible` outline pour l'accessibilité clavier.
- `.mod_redaction .mod_redaction-ai-attempt-content` : `display: none`.
- `.mod_redaction .mod_redaction-ai-attempt-block.mod_redaction-ai-section-open > .mod_redaction-ai-attempt-content` : `display: block`.
- `.mod_redaction .mod_redaction-ai-attempt-block > .mod_redaction-ai-attempt-header .mod_redaction-toggle-icon` : `margin-left: auto`, `transition: transform 0.2s ease`.
- `.mod_redaction .mod_redaction-ai-attempt-block.mod_redaction-ai-section-open > .mod_redaction-ai-attempt-header .mod_redaction-toggle-icon` : `transform: rotate(180deg)`.
- `.mod_redaction .mod_redaction-ai-attempt-summary-grade` : style pill compact — taille de police, padding, border-radius, repris des autres pills `mod_redaction-ai-level-*`.

Aucun style inline ajouté dans le PHP ou le Mustache.

#### `lang/en/redaction.php` (référence)
Nouvelles chaînes :
- `attempt_toggle` → `"Toggle attempt details"` (utilisé pour `aria-label` du header).

#### `lang/fr/redaction.php`
Traduction correspondante :
- `attempt_toggle` → `"Afficher/masquer les détails de la tentative"`.

#### `amd/build/grading_actions.min.js`
Régénération via terser après modification du source AMD (contrainte projet documentée dans la mémoire `feedback_amd_minify_at_end_of_dev`).

## Hors scope

- Pas de persistance de l'état (aucune table, aucun `localStorage`).
- Pas de bouton "tout déplier / tout replier" — peut faire l'objet d'une itération ultérieure si besoin.
- Pas de modification de la training timeline (les attempts d'entraînement ne sont pas concernés).
- Pas de modification du `submission_panel` (la soumission elle-même reste affichée comme aujourd'hui).
- Pas de modification du backend, des endpoints External Services, ni de la logique de notation.
- Pas de réécriture du système collapsible interne existant (general feedback, keywords, suggestions).

## Critères de réussite

1. À l'ouverture de `grading.php` pour une soumission ayant ≥ 2 évaluations IA, seule la plus récente est dépliée ; les autres sont repliées avec leur header informatif (numéro, date, note résumée).
2. Cliquer sur n'importe quel header bascule l'état du bloc correspondant.
3. La navigation clavier (Tab pour atteindre le header, Enter/Space pour toggler) fonctionne, et `aria-expanded` est correctement mis à jour.
4. Aucune chaîne hard-codée introduite (toute nouvelle string passe par `get_string()`).
5. Aucun CSS inline introduit dans le PHP ou le Mustache.
6. `php -l` clean sur les fichiers PHP modifiés ; pas de régression visuelle sur les sections internes existantes (feedback, keywords, suggestions restent ouverts par défaut comme avant).
7. `amd/build/grading_actions.min.js` régénéré et cohérent avec le source.

## Risques et points d'attention

- **Conflit éventuel avec `toggleSection` existant** : non, car on introduit `toggleAttempt` séparément. Les sélecteurs `.closest()` sont disjoints (`.mod_redaction-ai-criteria-section` vs `.mod_redaction-ai-attempt-block`).
- **Effet de bord visuel** : le header existant utilise `display: flex` implicitement via du style ailleurs ? À vérifier au moment de l'implémentation et adapter le CSS pour préserver la mise en page actuelle (badge à droite, etc.).
- **Bouton "Apply AI grade" / "Re-evaluate"** : ces boutons n'apparaissent que dans le bloc `is_latest`, qui est dépliée par défaut → comportement inchangé pour l'enseignant.
