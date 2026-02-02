# Documentation Technique - Plugin mod_redaction

## Vue d'ensemble

Le plugin **mod_redaction** est un module d'activité Moodle permettant aux enseignants de proposer des activités de rédaction avec évaluation manuelle ou assistée par IA. Il inclut un tableau de bord enseignant avec synthèse automatique des feedbacks.

**Version actuelle** : 2026012901
**Compatibilité** : Moodle 4.5+
**Auteur** : Emmanuel REMY

---

## Architecture du plugin

```
mod_redaction/
├── ajax/                           # Endpoints AJAX
│   ├── apply_ai_grade.php         # Appliquer une note IA
│   ├── autosave.php               # Sauvegarde automatique
│   ├── evaluate.php               # Déclencher évaluation IA
│   ├── generate_criteria.php      # Générer critères via IA
│   ├── get_evaluation_status.php  # Statut d'évaluation
│   ├── get_history.php            # Historique versions
│   └── submit.php                 # Soumettre/déverrouiller
│
├── amd/                           # Modules JavaScript AMD
│   ├── build/                     # Fichiers minifiés
│   │   ├── autosave.min.js
│   │   ├── dashboard.min.js
│   │   └── grading.min.js
│   └── src/                       # Sources
│       ├── autosave.js            # Sauvegarde auto côté client
│       ├── dashboard.js           # Tableau de bord enseignant
│       └── grading.js             # Interface de notation
│
├── backup/moodle2/                # Sauvegarde/restauration
│
├── classes/                       # Classes PHP
│   ├── ai_config.php              # Configuration IA
│   ├── ai_evaluator.php           # Orchestrateur évaluation IA
│   ├── ai_prompt_builder.php      # Construction des prompts
│   ├── ai_response_parser.php     # Parsing réponses IA
│   │
│   ├── ai_provider/               # Fournisseurs IA
│   │   ├── provider_interface.php # Interface commune
│   │   ├── base_provider.php      # Classe abstraite de base
│   │   ├── openai_provider.php    # OpenAI (GPT-4)
│   │   ├── anthropic_provider.php # Anthropic (Claude)
│   │   ├── mistral_provider.php   # Mistral AI
│   │   └── albert_provider.php    # Albert (Etalab)
│   │
│   ├── dashboard/                 # Tableau de bord enseignant
│   │   ├── ai_summary_generator.php   # Synthèse IA des feedbacks
│   │   ├── submission_stats.php       # Statistiques soumissions
│   │   └── token_stats.php            # Statistiques tokens
│   │
│   ├── external/                  # Web services externes
│   │   └── generate_ai_summary.php    # API synthèse IA
│   │
│   ├── event/                     # Événements Moodle
│   │   └── course_module_viewed.php
│   │
│   ├── privacy/                   # RGPD
│   │   └── provider.php
│   │
│   └── task/                      # Tâches asynchrones
│       └── evaluate_submission.php    # Évaluation IA en arrière-plan
│
├── db/                            # Base de données
│   ├── access.php                 # Capacités/permissions
│   ├── install.xml                # Schéma initial
│   ├── services.php               # Web services
│   └── upgrade.php                # Migrations
│
├── lang/                          # Traductions
│   ├── en/redaction.php
│   └── fr/redaction.php
│
├── pages/                         # Pages de l'interface
│   ├── home.php                   # Page d'accueil
│   ├── consignes.php              # Configuration consignes
│   ├── correction_model.php       # Modèle de correction
│   └── redaction.php              # Interface élève
│
├── templates/                     # Templates Mustache
│   └── dashboard_teacher.mustache # Tableau de bord
│
├── grading.php                    # Interface de notation
├── lib.php                        # Fonctions principales
├── mod_form.php                   # Formulaire de création
├── settings.php                   # Paramètres admin
├── version.php                    # Métadonnées version
└── view.php                       # Routeur principal
```

---

## Base de données

### Tables

#### `redaction` - Instances du module
| Champ | Type | Description |
|-------|------|-------------|
| id | INT | Clé primaire |
| course | INT | ID du cours |
| name | VARCHAR(255) | Nom de l'activité |
| intro | TEXT | Description |
| group_submission | TINYINT | 1=groupe, 0=individuel |
| autosave_interval | INT | Intervalle sauvegarde (sec) |
| ai_enabled | TINYINT | IA activée |
| ai_provider | VARCHAR(20) | openai/anthropic/mistral/albert |
| ai_api_key | TEXT | Clé API chiffrée |
| ai_auto_apply | TINYINT | Application auto des notes IA |

#### `redaction_consignes` - Consignes enseignant
| Champ | Type | Description |
|-------|------|-------------|
| id | INT | Clé primaire |
| redactionid | INT | FK vers redaction |
| titre | VARCHAR(255) | Titre de l'activité |
| consignes | TEXT | Instructions détaillées |
| criteres | TEXT | Critères d'évaluation |
| documents | TEXT | Ressources/liens |
| locked | TINYINT | Verrouillé pour les élèves |

#### `redaction_submission` - Soumissions élèves
| Champ | Type | Description |
|-------|------|-------------|
| id | INT | Clé primaire |
| redactionid | INT | FK vers redaction |
| groupid | INT | ID groupe (0=individuel) |
| userid | INT | ID utilisateur |
| titre | VARCHAR(255) | Titre soumission |
| contenu | TEXT | Contenu rédaction |
| status | TINYINT | 0=brouillon, 1=soumis |
| grade | DECIMAL(10,2) | Note /20 |
| feedback | TEXT | Commentaires enseignant |
| timesubmitted | INT | Timestamp soumission |

**Index unique** : `(redactionid, groupid, userid)`

#### `redaction_correction` - Modèle de correction
| Champ | Type | Description |
|-------|------|-------------|
| id | INT | Clé primaire |
| redactionid | INT | FK vers redaction (unique) |
| modele_reponse | TEXT | Réponse attendue |
| grille_criteres | TEXT | Grille JSON |
| ai_instructions | TEXT | Instructions pour l'IA |
| submission_date | INT | Date soumission attendue |
| deadline_date | INT | Date limite |

#### `redaction_ai_evaluations` - Évaluations IA
| Champ | Type | Description |
|-------|------|-------------|
| id | INT | Clé primaire |
| redactionid | INT | FK vers redaction |
| submissionid | INT | FK vers submission |
| groupid | INT | ID groupe |
| userid | INT | ID utilisateur |
| provider | VARCHAR(20) | Fournisseur IA |
| model | VARCHAR(50) | Modèle utilisé |
| prompt_tokens | INT | Tokens prompt |
| completion_tokens | INT | Tokens réponse |
| raw_response | TEXT | Réponse brute |
| parsed_grade | DECIMAL(10,2) | Note extraite |
| parsed_feedback | TEXT | Feedback extrait |
| criteria_json | TEXT | Scores par critère (JSON) |
| status | VARCHAR(20) | pending/processing/completed/failed/applied |
| error_message | TEXT | Message d'erreur |
| applied_by | INT | Enseignant qui a appliqué |
| applied_at | INT | Timestamp application |

#### `redaction_ai_summaries` - Synthèses IA (tableau de bord)
| Champ | Type | Description |
|-------|------|-------------|
| id | INT | Clé primaire |
| redactionid | INT | FK vers redaction (unique) |
| difficulties | TEXT | JSON des difficultés |
| strengths | TEXT | JSON des points forts |
| recommendations | TEXT | JSON des recommandations |
| general_observation | TEXT | Observation générale |
| submissions_analyzed | INT | Nombre soumissions analysées |
| provider | VARCHAR(20) | Fournisseur IA |
| model | VARCHAR(50) | Modèle utilisé |
| prompt_tokens | INT | Tokens prompt |
| completion_tokens | INT | Tokens réponse |

#### `redaction_history` - Historique versions
| Champ | Type | Description |
|-------|------|-------------|
| id | INT | Clé primaire |
| submissionid | INT | FK vers submission |
| redactionid | INT | FK vers redaction |
| version_number | INT | Numéro de version |
| titre | VARCHAR(255) | Titre à cette version |
| contenu | TEXT | Contenu à cette version |
| word_count | INT | Nombre de mots |
| char_count | INT | Nombre de caractères |
| saved_by | INT | Utilisateur sauvegarde |

---

## Système d'évaluation IA

### Architecture des providers

```
provider_interface.php
        │
        ▼
base_provider.php (abstract)
        │
        ├── openai_provider.php
        ├── anthropic_provider.php
        ├── mistral_provider.php
        └── albert_provider.php
```

### Interface provider_interface

```php
interface provider_interface {
    public function get_name(): string;
    public function get_models(): array;
    public function get_default_model(): string;
    public function evaluate(string $systemprompt, string $userprompt, string $model, int $maxtokens): array;
    public function test_connection(): array;
    public function estimate_tokens(string $text): int;
}
```

### Méthode evaluate()

Signature : `evaluate(string $systemprompt, string $userprompt, string $model, int $maxtokens): array`

Retourne :
```php
[
    'content' => string,           // Contenu de la réponse
    'prompt_tokens' => int,        // Tokens utilisés (prompt)
    'completion_tokens' => int     // Tokens utilisés (réponse)
]
```

### Flux d'évaluation

```
1. Enseignant clique "Évaluer avec l'IA"
        │
        ▼
2. ajax/evaluate.php
        │
        ▼
3. ai_evaluator::queue_evaluation()
   - Crée enregistrement status='pending'
   - Planifie tâche adhoc
        │
        ▼
4. task/evaluate_submission.php (cron)
   - status='processing'
   - Appelle ai_evaluator::process_evaluation()
        │
        ▼
5. ai_prompt_builder::build()
   - Construit prompt avec consignes + modèle + soumission
        │
        ▼
6. provider->evaluate()
   - Appel API externe
        │
        ▼
7. ai_response_parser::parse()
   - Extrait grade, feedback, critères
        │
        ▼
8. Sauvegarde en base
   - status='completed'
   - parsed_grade, parsed_feedback, criteria_json
```

### Format JSON attendu de l'IA

```json
{
    "grade": 15.5,
    "feedback": "Commentaire général...",
    "criteria": [
        {
            "name": "Structure",
            "score": 4.5,
            "max": 5,
            "comment": "Bonne organisation..."
        }
    ],
    "keywords_found": ["argument", "exemple"],
    "keywords_missing": ["conclusion"],
    "suggestions": ["Développer la conclusion"],
    "confidence": 0.95
}
```

---

## Tableau de bord enseignant

### Composants

1. **submission_stats.php** - Statistiques de soumissions
   - Nombre soumis/notés/brouillons
   - Pourcentages de progression
   - Distribution des notes (5 tranches)
   - Statistiques évaluations IA

2. **token_stats.php** - Consommation tokens
   - Total tokens (prompt + completion)
   - Répartition par fournisseur
   - Estimation coûts

3. **ai_summary_generator.php** - Synthèse IA
   - Génère synthèse des feedbacks
   - Cache de 1 heure (CACHE_DURATION = 3600)
   - Minimum 1 évaluation requise

### Génération de synthèse

```php
// Prompt système
$systemprompt = "Tu es un assistant pedagogique expert...";

// Prompt utilisateur (construit dynamiquement)
$userprompt = "Analyse les N feedbacks suivants...";

// Appel IA
$response = $provider->evaluate($systemprompt, $userprompt, $model, 2000);

// Format de sortie attendu
{
    "difficulties": ["difficulté 1", "difficulté 2"],
    "strengths": ["point fort 1", "point fort 2"],
    "recommendations": ["recommandation 1"],
    "general_observation": "Observation générale..."
}
```

### Web service AJAX

**Service** : `mod_redaction_generate_ai_summary`

```php
// Appel
Ajax.call([{
    methodname: 'mod_redaction_generate_ai_summary',
    args: { cmid: 123, force: true }
}]);

// Réponse
{
    success: true,
    message: "Synthèse générée",
    summary: {
        difficulties: [...],
        strengths: [...],
        recommendations: [...],
        general_observation: "...",
        submissions_analyzed: 10,
        provider: "openai",
        model: "gpt-4o-mini"
    }
}
```

---

## Capacités (permissions)

| Capacité | Description | Rôles par défaut |
|----------|-------------|------------------|
| mod/redaction:addinstance | Créer activité | editingteacher, manager |
| mod/redaction:view | Voir activité | student, teacher, manager |
| mod/redaction:editconsignes | Modifier consignes | editingteacher, manager |
| mod/redaction:submit | Soumettre rédaction | student |
| mod/redaction:viewallsubmissions | Voir toutes soumissions | teacher, manager |
| mod/redaction:grade | Noter | teacher, editingteacher, manager |
| mod/redaction:viewhistory | Voir historique | teacher, editingteacher, manager |

---

## Configuration IA

### Chiffrement des clés API

```php
// Chiffrement (sauvegarde)
$encrypted = ai_config::encrypt_api_key($apikey);

// Déchiffrement (utilisation)
$decrypted = ai_config::decrypt_api_key($encrypted);
```

Utilise `\core\encryption` de Moodle 4.0+ avec fallback base64.

### Clé intégrée (Albert)

Albert dispose d'une clé API intégrée :

```php
// Vérifier si clé intégrée
if (ai_config::has_builtin_key('albert')) {
    $key = ai_config::get_builtin_api_key('albert');
}

// Obtenir clé effective
$key = ai_config::get_effective_api_key($provider, $instanceKey);
```

---

## Modules JavaScript AMD

### Compilation

Les fichiers sources sont dans `amd/src/`, les fichiers minifiés dans `amd/build/`.

Pour compiler (depuis la racine Moodle) :
```bash
grunt amd --root=/mod/redaction
```

### Module dashboard.js

```javascript
define(['jquery', 'core/ajax', 'core/notification', 'core/str', 'core/chartjs'],
function($, Ajax, Notification, Str, Chart) {
    return {
        init: function(cmid, gradeDistribution) {
            // Initialise graphique et événements
        },
        refreshSummary: function(button) {
            // Actualise synthèse via AJAX
        }
    };
});
```

---

## Flux de données

### Workflow élève

```
1. Élève accède à l'activité
        │
        ▼
2. Vérifie consignes verrouillées
        │
        ▼
3. Affiche page rédaction
   - Charge soumission existante ou crée nouvelle
        │
        ▼
4. Rédaction avec autosave
   - ajax/autosave.php toutes les 30s (configurable)
   - Sauvegarde historique
        │
        ▼
5. Soumission
   - status = 1
   - timesubmitted = now()
```

### Workflow enseignant

```
1. Configure consignes → Verrouille
        │
        ▼
2. Configure modèle de correction
   - Modèle réponse
   - Grille critères (JSON)
   - Instructions IA
        │
        ▼
3. Page de notation
   - Tableau de bord (stats + synthèse)
   - Navigation entre soumissions
        │
        ▼
4. Pour chaque soumission :
   - Voir contenu + historique
   - Évaluer avec IA (optionnel)
   - Appliquer note IA ou noter manuellement
```

---

## Gestion des erreurs

### Codes d'erreur IA

| Code | Clé de langue | Description |
|------|---------------|-------------|
| - | ai_not_enabled | IA non activée |
| - | ai_request_failed | Échec requête API |
| - | ai_invalid_response | Réponse JSON invalide |
| - | ai_parse_error | Erreur parsing réponse |
| - | ai_unknown_provider | Fournisseur inconnu |

### Retry logic

Les providers implémentent une logique de retry :
- 3 tentatives maximum
- Délais : 5s, 30s, 120s
- Pas de retry sur erreurs d'authentification (401, 403)

---

## RGPD / Privacy API

Le plugin déclare les données personnelles dans `classes/privacy/provider.php` :

- `redaction_submission` : contenus et notes élèves
- `redaction_history` : historique des versions
- `redaction_ai_evaluations` : évaluations IA

Export et suppression des données implémentés selon les standards Moodle.

---

## Migrations (upgrade.php)

### Version 2026012901

Ajout table `redaction_ai_summaries` pour le tableau de bord :

```php
if ($oldversion < 2026012901) {
    $table = new xmldb_table('redaction_ai_summaries');
    // ... définition des champs
    $table->add_key('redactionid', XMLDB_KEY_FOREIGN_UNIQUE, ['redactionid'], 'redaction', ['id']);

    if (!$dbman->table_exists($table)) {
        $dbman->create_table($table);
    }
    upgrade_mod_savepoint(true, 2026012901, 'redaction');
}
```

---

## Tests et débogage

### Mode debug Moodle

```php
debugging('Message', DEBUG_DEVELOPER);
```

### Logs IA

Les erreurs d'évaluation IA sont stockées dans `redaction_ai_evaluations.error_message`.

### Vérification syntaxe

```bash
php -l fichier.php
```

### Validation plugin

Utiliser le validateur de plugins Moodle : https://moodle.org/plugins/browse.php?list=contribute

---

## Points d'extension

### Ajouter un nouveau fournisseur IA

1. Créer `classes/ai_provider/nouveau_provider.php`
2. Étendre `base_provider`
3. Implémenter les méthodes abstraites :
   - `get_endpoint()`
   - `build_headers()`
   - `build_body()`
   - `parse_response()`
   - `get_name()`, `get_models()`, `get_default_model()`

4. Enregistrer dans `ai_evaluator::get_provider()`
5. Ajouter dans `ai_config::PROVIDERS`
6. Ajouter option dans `ai_config::get_provider_options()`

### Ajouter des statistiques au dashboard

1. Créer classe dans `classes/dashboard/`
2. Implémenter méthode `get_stats()`
3. Appeler depuis `redaction_render_teacher_dashboard()` dans `lib.php`
4. Ajouter rendu dans `templates/dashboard_teacher.mustache`

---

## Conventions de code

- **PHP** : PSR-12, standards Moodle
- **JavaScript** : AMD modules, jQuery pour DOM
- **SQL** : Placeholders `?` ou nommés `:param`
- **Chaînes** : Toujours via `get_string()`
- **Formulaires** : Toujours `sesskey()` pour CSRF

---

## Ressources

- [Documentation développeur Moodle](https://moodledev.io/)
- [API de base de données Moodle](https://moodledev.io/docs/apis/core/dml)
- [Modules AMD Moodle](https://moodledev.io/docs/guides/javascript/modules)
- [Templates Mustache](https://moodledev.io/docs/guides/templates)
