# Tableau de progression : actions groupées + nouvelle mise en page de l'évaluation IA

- **Date** : 2026-05-06
- **Auteur** : Emmanuel REMY (avec Claude)
- **Plugin** : `mod_redaction`
- **Fichiers JIRA / GitHub** : aucun (refonte interne)

## Contexte

Le plugin `mod_redaction` propose deux écrans clés pour l'enseignant :

1. **Tableau de progression** (`pages/grading_overview.php`) — vue tabulaire avec une ligne par élève (ou par groupe) et une colonne par tentative. Aujourd'hui, l'enseignant ne peut agir que sur un élève à la fois en cliquant sur son nom pour ouvrir la vue détaillée.
2. **Vue détaillée de l'évaluation IA** (`templates/ai_evaluation.mustache`) — affichage centré sur la note, avec plusieurs sections empilées verticalement (note, appréciation globale, forces/faiblesses, critères, mots-clés, suggestions, feedback général).

Cette spec couvre deux évolutions UI/UX :

- côté **tableau de progression** : nom-prénom au lieu de prénom-nom, sélection multiple à la manière des quiz Moodle, et deux actions groupées (Réévaluer, Déverrouiller).
- côté **évaluation IA** : suppression des blocs redondants et passage à un layout 2 colonnes pour mieux exploiter l'espace horizontal.

## Objectifs

- Permettre à l'enseignant de relancer une évaluation IA ou de déverrouiller plusieurs élèves en une seule action.
- Clarifier l'affichage de l'évaluation IA en supprimant les blocs redondants et en mettant note et critères côte-à-côte.
- Préserver les contraintes Moodle Plugin Directory : i18n complet, pas de CSS inline, AJAX via External Services, templates Mustache.

## Non-objectifs

- Pas de modification du comportement individuel (clic sur le nom → vue détaillée reste identique).
- Pas de refonte du modèle de données ; les schémas DB et les services existants ne changent pas.
- Pas d'évolution du flux d'évaluation IA (queueing, prompt, parsing) — uniquement le rendu.

---

## Section 1 — Tableau de progression : sélection multiple + actions groupées

### 1.1 Affichage du nom (lastname firstname)

- Dans `classes/output/grading_overview_data.php::build_student_rows()`, remplacer `fullname($user)` par `$user->lastname . ' ' . $user->firstname`.
- **Portée stricte** : modification limitée à ce renderable. Toutes les autres pages du plugin continuent d'utiliser `fullname()` pour respecter le format global Moodle.
- Le mode groupe (`build_group_rows()`) utilise `format_string($group->name)` ; pas de changement.

### 1.2 Colonne de sélection

Nouvelle colonne **à gauche** de la colonne nom dans `templates/grading_overview.mustache` :

- En-tête : checkbox maître (`.mod_redaction-overview-checkall`) cochant/décochant toutes les checkboxes éligibles visibles.
- Cellule par ligne : checkbox (`.mod_redaction-overview-rowcheck`) **uniquement si la ligne a au moins une soumission**. Sinon cellule vide. Cela évite à l'enseignant de cocher des élèves non actionnables.
- `data-attributes` portés par chaque checkbox de ligne :
  - `data-itemid` : `userid` (mode individuel) ou `groupid` (mode groupe).
  - `data-submissionid` : ID de la **dernière** soumission (pas la première).
  - `data-status` : `0` (brouillon) ou `1` (verrouillée).
  - `data-hascontent` : `"1"` ou `"0"` selon que `submission.contenu` est non vide.
  - `data-name` : nom affiché (utilisé par la modale de confirmation).

### 1.3 Adaptation du renderable

`grading_overview_data.php` aujourd'hui ne conserve, par utilisateur, que la **première** soumission (logique : `if (!isset($submissionByUser[$s->userid]))`), suffisante pour itérer les colonnes par tentative. Pour la sélection, on a besoin de la **dernière** soumission, qui n'est pas la même.

Modification :

- Conserver la logique actuelle de récupération chronologique des évaluations (sert à `build_cells()`).
- Ajouter, par utilisateur (ou groupe), la collecte du `submissionid` le plus récent (`max(timecreated, id)`) et son état (`status`, `contenu non vide`).
- Exposer dans le contexte du template un objet `latest` par ligne :
  ```
  'latest' => [
      'has' => bool,
      'submissionid' => int|null,
      'status' => int,
      'hascontent' => bool,
      'itemid' => int,
  ]
  ```

### 1.4 Barre d'actions au-dessus du tableau

Nouveau bloc Mustache rendu **dans `grading_overview.mustache`**, au-dessus de la `<table>` :

- Compteur dynamique : `0 sélectionné(s)` (string i18n `overview_selection_count`).
- Bouton **Réévaluer** — classe `.mod_redaction-overview-action-reevaluate`, icône 🔄. Désactivé tant que `selectedCount === 0`.
- Bouton **Déverrouiller** — classe `.mod_redaction-overview-action-unlock`, icône 🔓. Désactivé tant que `selectedCount === 0`.

Les boutons et le compteur ne sont rendus que si l'utilisateur a la capacité `mod/redaction:grade` (vérification dans le renderable, exposée en booléen `can_grade`).

### 1.5 Modale de confirmation

Au clic sur Réévaluer ou Déverrouiller :

- Le module JS construit deux listes en parcourant les checkboxes cochées :
  - **Affectés** :
    - Réévaluer : ceux dont `data-hascontent === "1"`.
    - Déverrouiller : ceux dont `data-status === "1"`.
  - **Ignorés** : tous les autres, avec une raison courte :
    - Réévaluer : *pas de contenu*.
    - Déverrouiller : *déjà déverrouillée*.
- Une modale `core/modal_save_cancel` est ouverte avec deux sections (✅ "Sera affecté(e) (N) :" et ⚠️ "Sera ignoré(e) (M) :"). Chaque liste contient les noms (issus de `data-name`) et la raison pour les ignorés.
- Boutons **Confirmer** / **Annuler**.
- Sur Confirmer, appel de l'external service correspondant via `core/ajax`, puis affichage d'un toast Moodle (`core/notification.addNotification`) avec le résumé retourné par le service. Rechargement de la page pour rafraîchir le tableau.

### 1.6 External service `bulk_unlock`

Nouveau fichier `classes/external/bulk_unlock.php` :

- `execute_parameters()` : `cmid` (PARAM_INT), `submissionids` (array of PARAM_INT).
- `execute()` :
  - `validate_context()` + `require_capability('mod/redaction:grade')`.
  - Pour chaque `submissionid` : vérifier `redactionid == $redaction->id` ET `status == 1`. Si OK → bascule à `0`, met à jour `timemodified`, incrémente `unlocked`. Sinon → `skipped`.
  - Émet l'événement existant pour le déverrouillage (vérifier `classes/event/` ; en créer un si absent — nom suggéré `submission_unlocked`).
- `execute_returns()` :
  ```
  [
      'success' => PARAM_BOOL,
      'unlocked' => PARAM_INT,
      'skipped' => PARAM_INT,
      'errors' => array of PARAM_TEXT,
  ]
  ```
- Déclaration dans `db/services.php` :
  ```
  'mod_redaction_bulk_unlock' => [
      'classname'    => 'mod_redaction\\external\\bulk_unlock',
      'methodname'   => 'execute',
      'description'  => 'Bulk unlock submissions',
      'type'         => 'write',
      'ajax'         => true,
      'capabilities' => 'mod/redaction:grade',
  ],
  ```

### 1.7 External service `bulk_evaluate` (réutilisé)

Le service existant `mod_redaction_bulk_evaluate` est utilisé tel quel. Le module JS lui passe la liste des `submissionid` des élèves cochés et éligibles (`data-hascontent === "1"`).

### 1.8 JS — extension du module `grading_overview`

Le module AMD existant `amd/src/grading_overview.js` est étendu :

- Conserve le tri par nom existant.
- Ajoute :
  - Gestion de la checkbox maître ↔ checkboxes de ligne.
  - Mise à jour du compteur et de l'état désactivé/activé des boutons d'action.
  - Construction des listes affectés/ignorés et ouverture de la modale de confirmation (via `core/modal_factory` + `core/modal_save_cancel`).
  - Appels AJAX `mod_redaction_bulk_evaluate` et `mod_redaction_bulk_unlock`.
  - Toast de résultat puis `window.location.reload()` pour rafraîchir.

Le module est initialisé depuis `pages/grading_overview.php` avec les nouvelles options : `cmid`, et un libellé `selectionLabel` localisé pour le compteur.

### 1.9 i18n — chaînes nouvelles (lang/en/redaction.php + lang/fr/redaction.php)

Format suggéré :

| Clé                                         | EN                                              | FR                                                  |
| ------------------------------------------- | ----------------------------------------------- | --------------------------------------------------- |
| `overview_select_all`                       | Select all students                             | Tout sélectionner                                   |
| `overview_selection_count`                  | {$a} selected                                   | {$a} sélectionné(s)                                 |
| `overview_action_reevaluate`                | Re-evaluate                                     | Réévaluer                                           |
| `overview_action_unlock`                    | Unlock                                          | Déverrouiller                                       |
| `overview_confirm_reevaluate_title`         | Confirm re-evaluation                           | Confirmer la réévaluation                           |
| `overview_confirm_unlock_title`             | Confirm unlock                                  | Confirmer le déverrouillage                         |
| `overview_confirm_affected`                 | Affected ({$a}):                                | Sera affecté(e) ({$a}) :                            |
| `overview_confirm_ignored`                  | Ignored ({$a}):                                 | Sera ignoré(e) ({$a}) :                             |
| `overview_skip_reason_nocontent`            | no content                                      | pas de contenu                                      |
| `overview_skip_reason_alreadyunlocked`      | already unlocked                                | déjà déverrouillée                                  |
| `overview_bulk_reevaluate_result`           | {$a->queued} queued, {$a->skipped} skipped      | {$a->queued} lancée(s), {$a->skipped} ignorée(s)    |
| `overview_bulk_unlock_result`               | {$a->unlocked} unlocked, {$a->skipped} skipped  | {$a->unlocked} déverrouillée(s), {$a->skipped} ignorée(s) |

`lang/en/redaction.php` est la source de vérité ; `fr` doit refléter exactement les mêmes clés.

### 1.10 CSS — nouvelles classes (styles.css)

Toutes les classes sont préfixées `.mod_redaction-overview-*`. Ajout d'une zone "actions bar" en flex (compteur à gauche, boutons à droite), de styles pour les checkboxes et de l'état désactivé des boutons. Aucun CSS inline.

---

## Section 2 — Évaluation IA : nouvelle mise en page

### 2.1 Suppressions dans `templates/ai_evaluation.mustache`

À retirer :

- Le bloc `{{#hasappreciation}} … {{/hasappreciation}}` (`overall_appreciation`) — le "C'est un bon début…" jugé redondant avec le commentaire général.
- Les trois variantes de blocs forces/faiblesses (`hasstrengths` × `hasweaknesses`, et les deux cas seul-uns) — jugés redondants avec les "conseils pour progresser".

Côté CSS (`styles.css`) : nettoyer les classes orphelines (`mod_redaction-ai-appreciation*`, `mod_redaction-ai-strengths-weaknesses`, `mod_redaction-ai-sw-*`).

Côté backend : les champs `overall_appreciation`, `strengths`, `weaknesses` continuent d'exister dans le JSON parsé, **on n'y touche pas**. Le template ne les rend simplement plus.

### 2.2 Nouvelle structure (par tentative)

```
┌─────────────────────────────────────────────────────────────┐
│  En-tête tentative (date + badge "tentative finale")        │
└─────────────────────────────────────────────────────────────┘

┌──────────────────┬──────────────────────────────────────────┐
│   Note IA        │   Détail des critères                    │
│   (note ronde,   │   (toujours ouvert ; titre, liste des    │
│    niveau,       │    critères avec barre, score, niveau,   │
│    fiabilité)    │    commentaire)                          │
└──────────────────┴──────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  Commentaire général (pleine largeur, collapsible, ouvert)  │
└─────────────────────────────────────────────────────────────┘

┌──────────────────┬──────────────────────────────────────────┐
│   Mots-clés      │   Conseils pour progresser               │
│   (collapsible,  │   (collapsible, ouvert ; suggestions)    │
│    ouvert)       │                                          │
└──────────────────┴──────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  Boutons : Appliquer la note IA  │  Re-évaluer  (latest)    │
└─────────────────────────────────────────────────────────────┘
```

### 2.3 Détails par bloc

- **Note IA (gauche, ligne 1)** : composant existant inchangé (note ronde, niveau, label "Note IA", barre de fiabilité). Largeur fixe (~280-320 px).
- **Critères (droite, ligne 1)** : aujourd'hui dans une `mod_redaction-ai-criteria-section` collapsible. **Le toggle est retiré** : titre "📊 Détail des critères d'évaluation" affiché en permanence, contenu toujours visible. Le rendu interne des critères (barre, score, niveau, commentaire) ne change pas.
- **Commentaire général (ligne 2)** : reste collapsible (toggle 💬). **Par défaut ouvert** (déplie au chargement de la page).
- **Mots-clés (gauche, ligne 3)** : reste collapsible (toggle 🔑). **Par défaut ouvert**. Organisation interne (mots identifiés / manquants) inchangée.
- **Conseils (droite, ligne 3)** : reste collapsible (toggle 💡). **Par défaut ouvert**.

Le défaut "ouvert" est obtenu en supprimant la classe initiale `collapsed` (ou en ajoutant la classe `expanded`) selon l'implémentation actuelle de `toggleSection()`. À vérifier au moment de l'implémentation.

### 2.4 CSS — nouveaux wrappers en grid

- `.mod_redaction-ai-row-grade-criteria` :
  ```
  display: grid;
  grid-template-columns: minmax(280px, 320px) 1fr;
  gap: 1rem;
  ```
- `.mod_redaction-ai-row-keywords-suggestions` :
  ```
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  ```
- Fallback responsive : `@media (max-width: 768px) { grid-template-columns: 1fr; }` pour empiler sur mobile/tablette étroite.

### 2.5 Inchangés

- Les états `pending` / `failed` / fallback "pas d'évaluation" (en-tête de tentative et messages d'erreur).
- Le bouton "Évaluer" pour la première évaluation (état `^hasevaluation`).
- Les états de tentatives passées (`^is_latest`) : la même structure 2 colonnes s'applique à toutes les tentatives ; seuls les boutons Apply / Re-évaluer restent réservés à la dernière tentative.

---

## Plan de test (manuel)

### Section 1
- [ ] Tableau s'affiche avec nom-prénom au lieu de prénom-nom.
- [ ] Checkbox maître coche/décoche toutes les lignes éligibles.
- [ ] Lignes sans soumission n'ont pas de checkbox.
- [ ] Compteur de sélection se met à jour à chaque clic.
- [ ] Boutons Réévaluer / Déverrouiller désactivés sans sélection.
- [ ] Modale de confirmation liste correctement affectés vs ignorés (avec raisons).
- [ ] Confirmer Réévaluer → toast "X lancée(s), Y ignorée(s)" + page rechargée.
- [ ] Confirmer Déverrouiller → toast "X déverrouillée(s), Y ignorée(s)" + page rechargée.
- [ ] Annuler ferme la modale sans action.
- [ ] Mode groupe : actions appliquées au niveau groupe.
- [ ] Utilisateur sans `mod/redaction:grade` : barre d'actions et checkboxes absentes.

### Section 2
- [ ] Bloc "C'est un bon début…" supprimé.
- [ ] Blocs Points forts / Axes d'amélioration supprimés.
- [ ] Note à gauche, critères à droite sur écran large.
- [ ] Commentaire général en pleine largeur entre les deux lignes.
- [ ] Mots-clés à gauche, Conseils à droite.
- [ ] Sur écran < 768 px : tout s'empile.
- [ ] Critères toujours visibles (plus de toggle).
- [ ] Commentaire général / Mots-clés / Conseils ouverts par défaut, repliables au clic.
- [ ] Tentatives passées affichent la même structure (sans boutons Apply / Re-évaluer).

---

## Risques et points d'attention

- **Performance** : la barre d'actions et la modale ne nécessitent pas de requête supplémentaire (toutes les données sont déjà dans le DOM via `data-*`). L'appel AJAX n'a lieu qu'à la confirmation.
- **Cohérence avec le détail individuel** : le bouton Re-évaluer du détail (`ai_evaluation.mustache`) reste indépendant. Pas de risque de doublon de file (le service `bulk_evaluate` réutilise `ai_evaluator::queue_evaluation` qui est idempotent grâce à `has_pending_evaluation`).
- **Recompilation AMD** : `grading_overview.js` est modifié → il faut regénérer `grading_overview.min.js` via `grunt amd --root=/mod/redaction`.
- **Backup/Restore** : aucune nouvelle table, aucun changement de schéma → pas d'impact backup/restore.
- **Privacy API** : aucune nouvelle donnée personnelle stockée → pas de mise à jour du provider.

## Ouvertures (hors scope)

- Pourrait à terme s'étendre à un bouton "Appliquer la note IA en masse" (similaire à Réévaluer mais appellerait `bulk_apply_grade`). Pas demandé pour l'instant.
- Une option de tri par note (en plus du tri par nom) dans le tableau pourrait être pertinente, mais n'est pas dans cette spec.
