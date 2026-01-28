# Plan de Développement : Plugin mod_redaction

## Vue d'ensemble

Création d'un plugin Moodle simplifié basé sur `mod_gestionprojet` avec :
- **1 step enseignant** : Page de consignes pour l'activité
- **1 step élève** : Page de rédaction de texte

Conservation des fonctionnalités clés :
- Soumission individuelle / de groupe
- Système d'autosave
- Évaluation IA avec modèle de correction
- Interface de notation pour l'enseignant
- Intégration au carnet de notes Moodle

---

## Structure des fichiers

```
mod_redaction/
├── version.php                    # [CRÉER] Métadonnées
├── lib.php                        # [ADAPTER] ~60% réutilisable
├── view.php                       # [ADAPTER] Router simplifié
├── mod_form.php                   # [ADAPTER] Formulaire paramètres
├── grading.php                    # [ADAPTER] Interface notation
│
├── db/
│   ├── install.xml                # [CRÉER] Schéma 4 tables
│   ├── upgrade.php                # [CRÉER] Vide au départ
│   ├── access.php                 # [ADAPTER] Capacités
│   └── services.php               # [OPTIONNEL] Web services
│
├── lang/
│   ├── en/redaction.php           # [CRÉER] ~80 chaînes
│   └── fr/redaction.php           # [CRÉER] ~80 chaînes
│
├── pages/
│   ├── home.php                   # [ADAPTER] Hub navigation (2 cartes)
│   ├── consignes.php              # [CRÉER] Page enseignant
│   ├── redaction.php              # [CRÉER] Page élève
│   └── correction_model.php       # [ADAPTER] Modèle correction IA
│
├── ajax/
│   ├── autosave.php               # [ADAPTER] ~90% réutilisable
│   ├── submit_step.php            # [ADAPTER] Soumettre/déverrouiller
│   ├── grade.php                  # [RÉUTILISER] Noter une soumission
│   ├── evaluate.php               # [ADAPTER] Évaluation IA
│   ├── test_api.php               # [RÉUTILISER] Test connexion IA
│   ├── apply_ai_grade.php         # [RÉUTILISER] Appliquer note IA
│   └── get_evaluation_status.php  # [RÉUTILISER] Statut évaluation
│
├── amd/
│   ├── src/
│   │   ├── autosave.js            # [RÉUTILISER] 100%
│   │   ├── ai_progress.js         # [RÉUTILISER] 100%
│   │   ├── notifications.js       # [RÉUTILISER] 100%
│   │   └── test_api.js            # [RÉUTILISER] 100%
│   └── build/                     # Auto-généré par grunt
│
├── classes/
│   ├── event/
│   │   └── course_module_viewed.php  # [ADAPTER]
│   ├── ai_config.php              # [RÉUTILISER] 100%
│   ├── ai_evaluator.php           # [ADAPTER] Simplifier pour 1 step
│   ├── ai_prompt_builder.php      # [ADAPTER] Nouveau prompt
│   ├── ai_response_parser.php     # [RÉUTILISER] 100%
│   └── ai_provider/
│       ├── base.php               # [RÉUTILISER] 100%
│       ├── openai.php             # [RÉUTILISER] 100%
│       ├── anthropic.php          # [RÉUTILISER] 100%
│       ├── mistral.php            # [RÉUTILISER] 100%
│       └── albert.php             # [RÉUTILISER] 100%
│
├── styles.css                     # [CRÉER] Styles minimalistes
├── README.md                      # [CRÉER] Documentation
└── CLAUDE.md                      # [CRÉER] Instructions développement
```

---

## Phase 1 : Squelette du plugin (Foundation)

### 1.1 Fichiers de base

| Fichier | Action | Source | Tokens estimés |
|---------|--------|--------|----------------|
| `version.php` | Créer | Nouveau | ~50 |
| `lib.php` | Adapter | gestionprojet/lib.php | ~400 |
| `view.php` | Adapter | gestionprojet/view.php | ~100 |
| `mod_form.php` | Adapter | gestionprojet/mod_form.php | ~150 |

**version.php** - Créer nouveau :
```php
$plugin->component = 'mod_redaction';
$plugin->version = 2026012900;
$plugin->requires = 2024042200; // Moodle 4.4
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '1.0.0';
```

**lib.php** - Fonctions à conserver :
- `redaction_add_instance()`
- `redaction_update_instance()`
- `redaction_delete_instance()`
- `redaction_supports()`
- `redaction_get_or_create_submission()`
- `redaction_submit_step()`
- `redaction_revert_to_draft()`
- `redaction_grade_item_update()`
- `redaction_update_grades()`
- `redaction_get_user_grades()`
- `redaction_get_user_group()`

**Fonctions à supprimer** (spécifiques aux 8 steps) :
- `gestionprojet_get_navigation_links()`
- `gestionprojet_get_step_table()`
- `gestionprojet_step_to_itemnumber()`
- `gestionprojet_get_teacher_steps()`
- `gestionprojet_get_student_steps()`
- `gestionprojet_teacher_pages_complete()`
- `gestionprojet_create_teacher_pages()`

### 1.2 Base de données

**db/install.xml** - 6 tables :

```xml
<!-- Table principale -->
<TABLE NAME="redaction">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="course" TYPE="int" LENGTH="10" NOTNULL="true"/>
    <FIELD NAME="name" TYPE="char" LENGTH="255" NOTNULL="true"/>
    <FIELD NAME="intro" TYPE="text" NOTNULL="false"/>
    <FIELD NAME="introformat" TYPE="int" LENGTH="4" DEFAULT="0"/>
    <FIELD NAME="group_submission" TYPE="int" LENGTH="1" DEFAULT="1"/>
    <FIELD NAME="autosave_interval" TYPE="int" LENGTH="10" DEFAULT="30"/>
    <FIELD NAME="ai_enabled" TYPE="int" LENGTH="1" DEFAULT="0"/>
    <FIELD NAME="ai_provider" TYPE="char" LENGTH="20" NOTNULL="false"/>
    <FIELD NAME="ai_api_key" TYPE="text" NOTNULL="false"/>
    <FIELD NAME="ai_auto_apply" TYPE="int" LENGTH="1" DEFAULT="0"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10"/>
    <FIELD NAME="timemodified" TYPE="int" LENGTH="10"/>
  </FIELDS>
</TABLE>

<!-- Consignes enseignant -->
<TABLE NAME="redaction_consignes">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="redactionid" TYPE="int" LENGTH="10" NOTNULL="true"/>
    <FIELD NAME="titre" TYPE="char" LENGTH="255" NOTNULL="false"/>
    <FIELD NAME="consignes" TYPE="text" NOTNULL="false"/>
    <FIELD NAME="criteres" TYPE="text" NOTNULL="false" COMMENT="Critères d'évaluation"/>
    <FIELD NAME="documents" TYPE="text" NOTNULL="false" COMMENT="Ressources/liens"/>
    <FIELD NAME="locked" TYPE="int" LENGTH="1" DEFAULT="0"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10"/>
    <FIELD NAME="timemodified" TYPE="int" LENGTH="10"/>
  </FIELDS>
</TABLE>

<!-- Soumissions élèves -->
<TABLE NAME="redaction_submission">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="redactionid" TYPE="int" LENGTH="10" NOTNULL="true"/>
    <FIELD NAME="groupid" TYPE="int" LENGTH="10" DEFAULT="0"/>
    <FIELD NAME="userid" TYPE="int" LENGTH="10" DEFAULT="0"/>
    <FIELD NAME="titre" TYPE="char" LENGTH="255" NOTNULL="false"/>
    <FIELD NAME="contenu" TYPE="text" NOTNULL="false"/>
    <FIELD NAME="status" TYPE="int" LENGTH="2" DEFAULT="0" COMMENT="0=brouillon, 1=soumis"/>
    <FIELD NAME="grade" TYPE="number" LENGTH="10" DECIMALS="2" NOTNULL="false"/>
    <FIELD NAME="feedback" TYPE="text" NOTNULL="false"/>
    <FIELD NAME="timesubmitted" TYPE="int" LENGTH="10" DEFAULT="0"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10"/>
    <FIELD NAME="timemodified" TYPE="int" LENGTH="10"/>
  </FIELDS>
  <INDEXES>
    <INDEX NAME="submission_unique_idx" UNIQUE="true" FIELDS="redactionid, groupid, userid"/>
  </INDEXES>
</TABLE>

<!-- Modèle de correction (enseignant + IA) -->
<TABLE NAME="redaction_correction">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="redactionid" TYPE="int" LENGTH="10" NOTNULL="true"/>
    <FIELD NAME="modele_reponse" TYPE="text" NOTNULL="false" COMMENT="Réponse attendue"/>
    <FIELD NAME="grille_criteres" TYPE="text" NOTNULL="false" COMMENT="JSON critères notation"/>
    <FIELD NAME="ai_instructions" TYPE="text" NOTNULL="false"/>
    <FIELD NAME="submission_date" TYPE="int" LENGTH="10" NOTNULL="false"/>
    <FIELD NAME="deadline_date" TYPE="int" LENGTH="10" NOTNULL="false"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10"/>
    <FIELD NAME="timemodified" TYPE="int" LENGTH="10"/>
  </FIELDS>
</TABLE>

<!-- Évaluations IA -->
<TABLE NAME="redaction_ai_evaluations">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="redactionid" TYPE="int" LENGTH="10" NOTNULL="true"/>
    <FIELD NAME="submissionid" TYPE="int" LENGTH="10" NOTNULL="true"/>
    <FIELD NAME="groupid" TYPE="int" LENGTH="10" DEFAULT="0"/>
    <FIELD NAME="userid" TYPE="int" LENGTH="10" DEFAULT="0"/>
    <FIELD NAME="provider" TYPE="char" LENGTH="20" NOTNULL="true"/>
    <FIELD NAME="model" TYPE="char" LENGTH="50" NOTNULL="true"/>
    <FIELD NAME="prompt_tokens" TYPE="int" LENGTH="10" NOTNULL="false"/>
    <FIELD NAME="completion_tokens" TYPE="int" LENGTH="10" NOTNULL="false"/>
    <FIELD NAME="raw_response" TYPE="text" NOTNULL="false"/>
    <FIELD NAME="parsed_grade" TYPE="number" LENGTH="10" DECIMALS="2" NOTNULL="false"/>
    <FIELD NAME="parsed_feedback" TYPE="text" NOTNULL="false"/>
    <FIELD NAME="criteria_json" TYPE="text" NOTNULL="false"/>
    <FIELD NAME="status" TYPE="char" LENGTH="20" DEFAULT="pending"/>
    <FIELD NAME="error_message" TYPE="text" NOTNULL="false"/>
    <FIELD NAME="applied_by" TYPE="int" LENGTH="10" NOTNULL="false"/>
    <FIELD NAME="applied_at" TYPE="int" LENGTH="10" NOTNULL="false"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10"/>
    <FIELD NAME="timemodified" TYPE="int" LENGTH="10"/>
  </FIELDS>
</TABLE>

<!-- Historique des versions (rédaction élève) -->
<TABLE NAME="redaction_history">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="submissionid" TYPE="int" LENGTH="10" NOTNULL="true" COMMENT="FK redaction_submission"/>
    <FIELD NAME="redactionid" TYPE="int" LENGTH="10" NOTNULL="true"/>
    <FIELD NAME="groupid" TYPE="int" LENGTH="10" DEFAULT="0"/>
    <FIELD NAME="userid" TYPE="int" LENGTH="10" DEFAULT="0"/>
    <FIELD NAME="titre" TYPE="char" LENGTH="255" NOTNULL="false"/>
    <FIELD NAME="contenu" TYPE="text" NOTNULL="false"/>
    <FIELD NAME="version_number" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="1"/>
    <FIELD NAME="word_count" TYPE="int" LENGTH="10" NOTNULL="false"/>
    <FIELD NAME="char_count" TYPE="int" LENGTH="10" NOTNULL="false"/>
    <FIELD NAME="saved_by" TYPE="int" LENGTH="10" NOTNULL="true" COMMENT="User who saved"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true"/>
  </FIELDS>
  <KEYS>
    <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
    <KEY NAME="submissionid" TYPE="foreign" FIELDS="submissionid" REFTABLE="redaction_submission" REFFIELDS="id"/>
  </KEYS>
  <INDEXES>
    <INDEX NAME="submission_version_idx" UNIQUE="false" FIELDS="submissionid, version_number"/>
  </INDEXES>
</TABLE>
```

### 1.3 Capacités

**db/access.php** - Simplifier :

```php
$capabilities = [
    'mod/redaction:addinstance' => [...],
    'mod/redaction:view' => [...],
    'mod/redaction:editconsignes' => [...],  // Remplace configureteacherpages
    'mod/redaction:submit' => [...],
    'mod/redaction:grade' => [...],
    'mod/redaction:viewallsubmissions' => [...],
];
```

---

## Phase 2 : Pages (Interface)

### 2.1 Page d'accueil (home.php)

Adapter depuis `gestionprojet/pages/home.php` :
- **Enseignant** : 2 cartes (Consignes, Modèle correction)
- **Élève** : 1 carte (Ma rédaction)

Tokens estimés : ~200 (beaucoup de code réutilisable)

### 2.2 Page Consignes (consignes.php)

Créer nouvelle page simple :
- Titre de l'activité
- Zone de texte riche (consignes)
- Zone de texte (critères d'évaluation)
- Zone liens/ressources
- Bouton verrouiller
- Autosave activé

Tokens estimés : ~300

### 2.3 Page Rédaction (redaction.php)

Créer nouvelle page :
- Affichage consignes (lecture seule)
- Titre de la rédaction
- Zone de texte riche (contenu principal)
- Indicateur mode groupe/individuel
- Bouton soumettre
- Affichage note/feedback si noté
- Autosave activé

Tokens estimés : ~400

### 2.4 Modèle de correction (correction_model.php)

Adapter depuis `gestionprojet/pages/step4_teacher.php` :
- Modèle de réponse attendue
- Grille de critères (JSON)
- Instructions IA
- Dates soumission/deadline

Tokens estimés : ~250

---

## Phase 3 : AJAX & JavaScript

### 3.1 Endpoints AJAX

| Fichier | Action | Modifications |
|---------|--------|---------------|
| `autosave.php` | Adapter | Simplifier validation + créer version historique |
| `submit_step.php` | Adapter | Renommer en submit.php |
| `grade.php` | Réutiliser | Adapter noms tables |
| `evaluate.php` | Adapter | Retirer logique multi-step |
| `test_api.php` | Réutiliser | 100% identique |
| `apply_ai_grade.php` | Adapter | Simplifier |
| `get_evaluation_status.php` | Réutiliser | 100% identique |
| `get_history.php` | Créer | Récupérer versions historiques |

Tokens estimés total : ~300

### 3.2 Modules JavaScript AMD

| Module | Action |
|--------|--------|
| `autosave.js` | Copier tel quel |
| `ai_progress.js` | Copier tel quel |
| `notifications.js` | Copier tel quel |
| `test_api.js` | Copier tel quel |

**Coût tokens** : 0 (copie directe)

---

## Phase 4 : Classes IA

### 4.1 Providers

Copier intégralement le dossier `classes/ai_provider/` :
- `base.php`
- `openai.php`
- `anthropic.php`
- `mistral.php`
- `albert.php`

Seule modification : namespace `mod_redaction` au lieu de `mod_gestionprojet`

Tokens estimés : ~100 (rechercher/remplacer)

### 4.2 Classes support IA

| Classe | Action | Tokens |
|--------|--------|--------|
| `ai_config.php` | Adapter namespace | ~50 |
| `ai_evaluator.php` | Simplifier (1 step) | ~200 |
| `ai_prompt_builder.php` | Nouveau prompt rédaction | ~300 |
| `ai_response_parser.php` | Adapter namespace | ~50 |

---

## Phase 5 : Interface de notation (grading.php)

Adapter depuis `gestionprojet/grading.php` :
- Retirer navigation multi-step
- Conserver liste groupes/individus
- Conserver affichage soumission
- Conserver évaluation IA
- Conserver application notes

Tokens estimés : ~400

---

## Phase 6 : Chaînes de langue

### Structure fichiers lang

```php
// lang/fr/redaction.php (~80 chaînes)
$string['modulename'] = 'Rédaction';
$string['modulenameplural'] = 'Rédactions';
$string['pluginname'] = 'Rédaction';
$string['pluginadministration'] = 'Administration Rédaction';

// Consignes
$string['consignes'] = 'Consignes';
$string['consignes_titre'] = 'Titre de l\'activité';
$string['consignes_content'] = 'Consignes détaillées';
$string['consignes_criteres'] = 'Critères d\'évaluation';
$string['consignes_locked'] = 'Consignes verrouillées';

// Rédaction
$string['redaction'] = 'Ma rédaction';
$string['redaction_titre'] = 'Titre';
$string['redaction_contenu'] = 'Contenu';
$string['status_draft'] = 'Brouillon';
$string['status_submitted'] = 'Soumis';

// Correction
$string['correction_model'] = 'Modèle de correction';
$string['modele_reponse'] = 'Réponse attendue';
$string['grille_criteres'] = 'Grille de critères';
$string['ai_instructions'] = 'Instructions pour l\'IA';

// Notation
$string['grading'] = 'Notation';
$string['grade'] = 'Note';
$string['feedback'] = 'Commentaires';
$string['evaluate_ai'] = 'Évaluer avec l\'IA';
$string['apply_grade'] = 'Appliquer la note';

// Autosave
$string['autosave_interval'] = 'Intervalle de sauvegarde';
$string['saving'] = 'Sauvegarde en cours...';
$string['saved'] = 'Sauvegardé';

// IA
$string['ai_settings'] = 'Paramètres IA';
$string['ai_enabled'] = 'Activer l\'évaluation IA';
$string['ai_provider'] = 'Fournisseur IA';
// ... (réutiliser chaînes existantes)
```

Tokens estimés : ~400

---

## Récapitulatif des coûts tokens

| Phase | Description | Tokens estimés |
|-------|-------------|----------------|
| 1.1 | Fichiers de base | ~700 |
| 1.2 | Base de données | ~300 |
| 1.3 | Capacités | ~100 |
| 2.x | Pages | ~1150 |
| 3.x | AJAX | ~300 |
| 4.x | Classes IA | ~700 |
| 5 | Grading | ~400 |
| 6 | Langues | ~400 |
| **Total** | | **~4050** |

**Économie vs création from scratch** : ~60-70%

---

## Ordre d'exécution recommandé

### Sprint 1 : Fondation (Jour 1)
1. `version.php`
2. `db/install.xml`
3. `db/access.php`
4. `lib.php` (fonctions essentielles)
5. `mod_form.php`
6. `lang/fr/redaction.php` (chaînes minimales)
7. `lang/en/redaction.php`

### Sprint 2 : Interface (Jour 2)
1. `view.php`
2. `pages/home.php`
3. `pages/consignes.php`
4. `pages/redaction.php`
5. Copier `amd/src/autosave.js`
6. `ajax/autosave.php`

### Sprint 3 : Soumission & Notation (Jour 3)
1. `ajax/submit.php`
2. `ajax/grade.php`
3. `ajax/get_history.php`
4. `grading.php` (avec vue historique versions)
5. Compléter `lib.php` (fonctions gradebook + historique)

### Sprint 4 : IA (Jour 4)
1. Copier `classes/ai_provider/*`
2. `classes/ai_config.php`
3. `classes/ai_evaluator.php`
4. `classes/ai_prompt_builder.php`
5. `classes/ai_response_parser.php`
6. `ajax/evaluate.php`
7. `ajax/test_api.php`
8. `ajax/apply_ai_grade.php`
9. `pages/correction_model.php`

### Sprint 5 : Finalisation (Jour 5)
1. Compléter chaînes de langue
2. `styles.css`
3. Tests fonctionnels
4. Documentation

---

## Fichiers à copier directement (0 modification)

Ces fichiers peuvent être copiés puis simplement renommés :

1. `amd/src/autosave.js`
2. `amd/src/ai_progress.js`
3. `amd/src/notifications.js`
4. `amd/src/test_api.js`
5. `classes/ai_provider/base.php`
6. `classes/ai_provider/openai.php`
7. `classes/ai_provider/anthropic.php`
8. `classes/ai_provider/mistral.php`
9. `classes/ai_provider/albert.php`
10. `classes/ai_response_parser.php`

---

## Fichiers à adapter (modifications mineures)

1. `lib.php` - Supprimer fonctions multi-step, adapter noms tables
2. `view.php` - Simplifier routing
3. `mod_form.php` - Retirer checkboxes steps
4. `db/access.php` - Réduire capabilities
5. `ajax/autosave.php` - Simplifier validation
6. `ajax/grade.php` - Adapter noms tables
7. `classes/ai_config.php` - Changer namespace
8. `classes/ai_evaluator.php` - Retirer logique multi-step

---

## Fichiers à créer (nouveaux)

1. `version.php`
2. `db/install.xml`
3. `pages/consignes.php`
4. `pages/redaction.php`
5. `pages/correction_model.php`
6. `classes/ai_prompt_builder.php` (nouveau prompt)
7. `lang/fr/redaction.php`
8. `lang/en/redaction.php`
9. `styles.css`
10. `CLAUDE.md`

---

## Notes techniques

### Gestion groupe/individu
Conserver la logique exacte de `gestionprojet` :
```php
if ($isGroupSubmission && $groupid != 0) {
    $params['groupid'] = $groupid;
    $params['userid'] = 0;
} else {
    $params['userid'] = $userid;
    $params['groupid'] = 0;
}
```

### Historique des versions
À chaque sauvegarde (autosave ou manuelle), créer une entrée dans `redaction_history` :
- Numéro de version incrémental
- Contenu complet (titre + texte)
- Comptage mots/caractères
- Timestamp et auteur de la sauvegarde

L'enseignant peut consulter l'historique dans l'interface de notation.
L'élève peut voir ses versions précédentes (optionnel, à confirmer).

### Éditeur de texte
Utiliser la fonction Moodle standard qui respecte la configuration admin :
```php
$editor = editors_get_preferred_editor();
// Ou utiliser le form element 'editor' de moodleform
$mform->addElement('editor', 'contenu', get_string('contenu', 'redaction'));
```

### Autosave
Le module JS `autosave.js` est entièrement réutilisable. Seul le endpoint AJAX doit être adapté pour les nouveaux noms de champs.

### Gradebook
Simplifier à un seul item de note (pas de mode `per_step`).

### Prompt IA pour la rédaction
Créer un nouveau prompt spécialisé dans `ai_prompt_builder.php` :
- Analyser le contenu textuel
- Comparer avec le modèle de réponse
- Évaluer selon la grille de critères
- Générer feedback constructif

---

## Décisions validées

1. **Nom du plugin** : `mod_redaction` ✓
2. **Éditeur de texte** : Respecter le choix de l'administrateur Moodle (Atto/TinyMCE selon config instance)
3. **Longueur maximale** : Pas de limite (paragraphe argumenté)
4. **Pièces jointes** : Non
5. **Mode hors connexion** : Non
6. **Historique des versions** : Oui - Conserver les versions précédentes de la rédaction élève
