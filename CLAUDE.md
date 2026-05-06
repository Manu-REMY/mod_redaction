# CLAUDE.md - Plugin mod_redaction

## Projet

Plugin Moodle `mod_redaction` - Module d'activité de rédaction avec évaluation IA.
- **Composant** : `mod_redaction`
- **Compatibilité** : Moodle 4.5+ (requires 2024100700)
- **PHP minimum** : 8.1
- **Maturité** : MATURITY_BETA
- **Dépôt** : https://github.com/music-practice-tools/moodle-mod_redaction
- **JIRA** : https://moodle.atlassian.net/browse/CONTRIB-10280

## Commandes utiles

```bash
# Vérifier la syntaxe PHP
php -l redaction/fichier.php

# Compiler les modules AMD (depuis la racine Moodle)
grunt amd --root=/mod/redaction

# Créer le ZIP de distribution
cd /Users/remyemmanuel/Documents/Claude\ code/mod_redaction && zip -r redaction.zip redaction/ -x "redaction/.git/*"
```

---

## Contraintes Moodle Plugin Directory (CONTRIB-10280)

Ces contraintes sont issues de la review officielle du plugin par Volodymyr Dovhan et doivent être respectées pour toute modification future.

### 1. Internationalisation (i18n) - AUCUN texte hard-codé

- **INTERDIT** : Tout texte affiché à l'utilisateur directement dans le PHP ou JS
- **OBLIGATOIRE** : Utiliser `get_string('identifiant', 'mod_redaction')` pour TOUT texte visible
- Le fichier de référence est **`lang/en/redaction.php`** (anglais = langue primaire Moodle)
- Le fichier `lang/fr/redaction.php` est la traduction française
- Chaque `get_string()` utilisé dans le code DOIT avoir une entrée correspondante dans `lang/en/redaction.php`
- Les chaînes JavaScript utilisent `core/str` : `Str.get_string('key', 'mod_redaction')`

```php
// INTERDIT
echo '<p>Vous n\'êtes dans aucun groupe</p>';
$html .= '<strong>Commentaires :</strong>';

// CORRECT
echo '<p>' . get_string('nogroup', 'mod_redaction') . '</p>';
$html .= '<strong>' . get_string('feedback', 'mod_redaction') . '</strong>';
```

### 2. Séparation CSS / PHP - Pas de CSS inline

- **INTERDIT** : CSS dans les fichiers PHP (`style="..."`, balises `<style>`)
- **OBLIGATOIRE** : Tout le CSS dans des fichiers `.css` dédiés
- Le fichier principal est `styles.css` à la racine du plugin
- Des fichiers additionnels peuvent être dans `styles/` si nécessaire
- **Tous les sélecteurs CSS doivent être préfixés** avec `.mod_redaction` ou `#mod_redaction` pour éviter les conflits avec d'autres plugins

```css
/* INTERDIT */
.feedback-display { color: green; }
.grading-table { width: 100%; }

/* CORRECT */
.mod_redaction .feedback-display { color: green; }
.mod_redaction .grading-table { width: 100%; }
```

### 3. Templates Mustache et Output API

- **INTERDIT** : Générer du HTML directement dans le PHP (`$html .= '<div>...'`)
- **OBLIGATOIRE** : Utiliser des templates Mustache (`.mustache`) dans le dossier `templates/`
- Créer des classes `renderable` dans `classes/output/` implémentant `\renderable` et `\templatable`
- Utiliser `$OUTPUT->render()` ou `$OUTPUT->render_from_template()` pour le rendu

```php
// INTERDIT
$html = '<div class="submission-panel">';
$html .= '<h3>' . $title . '</h3>';
$html .= '</div>';
echo $html;

// CORRECT
$data = new \mod_redaction\output\submission_panel($submission);
echo $OUTPUT->render_from_template('mod_redaction/submission_panel', $data->export_for_template($OUTPUT));
```

### 4. AJAX via External Services API

- **INTERDIT** : Endpoints AJAX custom (`ajax/monscript.php`) accédés directement
- **OBLIGATOIRE** : Utiliser l'API External Services de Moodle
- Déclarer les services dans `db/services.php`
- Créer les classes external dans `classes/external/`
- Chaque fonction externe doit définir :
  - `execute_parameters()` : validation des paramètres entrants
  - `execute()` : logique métier
  - `execute_returns()` : description de la structure de retour
- Côté JS, appeler via `core/ajax` : `Ajax.call([{methodname: 'mod_redaction_xxx', args: {...}}])`

```php
// db/services.php
$functions = [
    'mod_redaction_autosave' => [
        'classname'   => 'mod_redaction\external\autosave',
        'methodname'  => 'execute',
        'description' => 'Autosave student submission',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/redaction:submit',
    ],
];
```

### 5. Bug Tracker public

- Le dépôt GitHub du plugin DOIT être public
- URL : https://github.com/music-practice-tools/moodle-mod_redaction
- Les issues doivent être activées pour que les utilisateurs puissent signaler des bugs

### 6. Fichier de langue anglais comme référence

- `lang/en/redaction.php` est le fichier MAÎTRE
- Toute nouvelle chaîne doit d'abord être ajoutée en anglais
- Le fichier français `lang/fr/redaction.php` est une traduction secondaire
- Pas de chaîne dans `fr/` qui n'existe pas dans `en/`

---

## Contraintes Moodle générales

### Standards de code PHP

- Respecter les [Moodle Coding Style](https://moodledev.io/general/development/policies/codingstyle)
- PSR-12 avec adaptations Moodle
- Indentation : 4 espaces (pas de tabs)
- Ligne max : 180 caractères (recommandé 132)
- Accolades sur la même ligne pour les structures de contrôle
- PHPDoc obligatoire sur toutes les fonctions/méthodes publiques
- `defined('MOODLE_INTERNAL') || die();` en tête de chaque fichier PHP (sauf les points d'entrée)

### Sécurité

- `require_sesskey()` pour toute action POST/modification
- `require_login($course, true, $cm)` avant tout accès
- `require_capability()` pour vérifier les permissions
- Utiliser `$DB->get_record()` avec paramètres liés (jamais de concaténation SQL)
- `clean_param()` ou `required_param()` / `optional_param()` pour les entrées utilisateur
- Pas de `$_GET`, `$_POST`, `$_REQUEST` directs

### Base de données

- Utiliser l'API DML Moodle (`$DB->get_record()`, `$DB->insert_record()`, etc.)
- Jamais de requêtes SQL directes avec des valeurs non échappées
- Les migrations dans `db/upgrade.php` avec `upgrade_mod_savepoint()`
- Le schéma initial dans `db/install.xml` (format XMLDB)

### JavaScript AMD

- Tous les modules JS dans `amd/src/`
- Les fichiers minifiés dans `amd/build/` (générés par `grunt`)
- Format AMD : `define(['dependency'], function(Dep) { ... });`
- Utiliser les modules core Moodle : `core/ajax`, `core/notification`, `core/str`, `core/templates`
- Ne pas inclure de bibliothèques JS externes sans nécessité absolue

### Événements et Privacy API

- Émettre des événements Moodle pour les actions significatives (`classes/event/`)
- Implémenter la Privacy API (`classes/privacy/provider.php`) pour la conformité RGPD
- Déclarer toutes les tables contenant des données personnelles

### Backup & Restore

- Implémenter les classes de backup/restore dans `backup/moodle2/`
- Permettre la sauvegarde et restauration des instances du module

### Capacités (permissions)

- Déclarer toutes les capacités dans `db/access.php`
- Vérifier les capacités avant chaque action
- Rôles par défaut appropriés (student, teacher, editingteacher, manager)

---

## Checklist avant soumission au Plugin Directory

1. [ ] Aucun texte hard-codé (tout via `get_string()`)
2. [ ] `lang/en/redaction.php` complet et cohérent avec le code
3. [ ] Aucun CSS inline - tout dans `styles.css` avec préfixe `.mod_redaction`
4. [ ] Templates Mustache pour tout le HTML généré
5. [ ] AJAX via External Services (`db/services.php` + `classes/external/`)
6. [ ] Dépôt GitHub public avec issues activées
7. [ ] `version.php` à jour (version, requires, maturity, release)
8. [ ] `db/access.php` avec toutes les capacités
9. [ ] Privacy API implémentée (`classes/privacy/provider.php`)
10. [ ] Backup/restore implémenté
11. [ ] PHPDoc sur toutes les fonctions publiques
12. [ ] Pas de `$_GET`/`$_POST` directs
13. [ ] `require_sesskey()` sur les actions de modification
14. [ ] `require_login()` et `require_capability()` sur chaque page
15. [ ] Fichiers AMD compilés à jour dans `amd/build/`
16. [ ] Pas de bibliothèques tierces non nécessaires
17. [ ] Pas de code mort ou commenté
18. [ ] Pas d'appels `error_log()` ou `var_dump()` résiduels
