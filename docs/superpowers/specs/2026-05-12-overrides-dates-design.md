# Spec — Dérogations aux dates de soumission

**Date** : 2026-05-12
**Auteur** : Emmanuel REMY (avec assistance Claude)
**Statut** : Validé, prêt pour writing-plans

## Objectif

Permettre à un enseignant de définir des dérogations aux dates de soumission d'une instance `mod_redaction`, à la manière des autres activités Moodle (`mod_assign`, `mod_quiz`). Deux types de dérogations : **utilisateur** et **groupe**.

Cas d'usage typiques :

- Étendre la deadline pour un élève hospitalisé.
- Accorder un délai supplémentaire à un groupe-classe rattrapant un cours manqué.
- Raccourcir la deadline pour un sous-groupe d'élèves en évaluation anticipée (cas rare mais valide).

## Périmètre

**Inclus** :

- Dérogations utilisateur et groupe sur le champ `deadline_date` uniquement (la date dure qui bloque les soumissions et déclenche l'auto-submit cron).
- Menu Admin du module dédié (« Dérogations utilisateur » + « Dérogations de groupe »), pattern Moodle standard.
- Précédence : override user > override groupe (le plus petit `sortorder`) > deadline d'instance.
- Backup/restore.
- Privacy API.
- Events Moodle pour les CRUD.

**Hors périmètre v1** :

- Dérogations sur `submission_date` (champ d'affichage uniquement, faible valeur ajoutée).
- Notification message à l'élève lors de l'octroi (nécessite un message provider, à ajouter en v2).
- Tests Behat (le plugin n'en a pas).
- Module AMD (pas de JS custom requis).

## Schéma de données

### Nouvelle table `redaction_overrides`

```xml
<TABLE NAME="redaction_overrides" COMMENT="Per-user/group deadline overrides">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="redactionid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
    <FIELD NAME="groupid" TYPE="int" LENGTH="10" NOTNULL="false" SEQUENCE="false"
           COMMENT="Mutually exclusive with userid"/>
    <FIELD NAME="userid" TYPE="int" LENGTH="10" NOTNULL="false" SEQUENCE="false"
           COMMENT="Mutually exclusive with groupid"/>
    <FIELD NAME="deadline_date" TYPE="int" LENGTH="10" NOTNULL="false" SEQUENCE="false"
           COMMENT="Override deadline timestamp; NULL = no override of this field"/>
    <FIELD NAME="sortorder" TYPE="int" LENGTH="10" NOTNULL="false" SEQUENCE="false"
           COMMENT="Precedence order between group overrides (lowest wins)"/>
    <FIELD NAME="timecreated" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
    <FIELD NAME="timemodified" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
  </FIELDS>
  <KEYS>
    <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
    <KEY NAME="redactionid" TYPE="foreign" FIELDS="redactionid" REFTABLE="redaction" REFFIELDS="id"/>
    <KEY NAME="groupid" TYPE="foreign" FIELDS="groupid" REFTABLE="groups" REFFIELDS="id"/>
    <KEY NAME="userid" TYPE="foreign" FIELDS="userid" REFTABLE="user" REFFIELDS="id"/>
  </KEYS>
  <INDEXES>
    <INDEX NAME="redactionid_userid" UNIQUE="false" FIELDS="redactionid, userid"/>
    <INDEX NAME="redactionid_groupid" UNIQUE="false" FIELDS="redactionid, groupid"/>
  </INDEXES>
</TABLE>
```

**Notes** :

- Exclusion mutuelle `userid` / `groupid` : exactement un des deux doit être non-NULL. Garantie au niveau formulaire et helpers (pas au niveau DB pour rester portable XMLDB).
- `deadline_date` nullable pour préparer l'extension future à d'autres champs : une ligne dont tous les champs override sont NULL est invalide (validation form).
- Pas d'index unique sur `(redactionid, userid)` ni `(redactionid, groupid)` : on autorise plusieurs overrides groupe (utile avec `sortorder`). Pour les user overrides, unicité forcée côté formulaire.

### Migration

Dans `db/upgrade.php` :

```php
if ($oldversion < 2026051200) {
    $table = new xmldb_table('redaction_overrides');
    // ... création de la table via $dbman->create_table($table) ...
    upgrade_mod_savepoint(true, 2026051200, 'redaction');
}
```

### Bump version

`version.php` : `$plugin->version = 2026051200` (date du jour + 00).

## Capacité

Nouvelle entrée dans `db/access.php` :

```php
'mod/redaction:manageoverrides' => [
    'riskbitmask' => RISK_PERSONAL,
    'captype' => 'write',
    'contextlevel' => CONTEXT_MODULE,
    'archetypes' => [
        'editingteacher' => CAP_ALLOW,
        'manager' => CAP_ALLOW,
    ],
],
```

## UI et navigation

### Intégration au menu Admin

Via `lib.php` → `redaction_extend_settings_navigation($settings, $redactionnode)` :

- Nœud « Dérogations d'utilisateur » → `pages/overrides.php?id=<cmid>&mode=user`
- Nœud « Dérogations de groupe » → `pages/overrides.php?id=<cmid>&mode=group` (affiché uniquement si des groupes existent dans le cours)

Visible uniquement si la capacité `mod/redaction:manageoverrides` est accordée.

### Pages

| Fichier | Rôle |
|---|---|
| `pages/overrides.php` | Listing. Params : `id` (cmid), `mode` (`user`/`group`). |
| `pages/override_edit.php` | Add/edit form. Params : `id` (cmid), soit `overrideid` (édition) soit `mode` (création). |
| `pages/override_delete.php` | Confirmation suppression. Params : `id` (cmid), `overrideid`, `sesskey`. |

### Formulaire

`classes/form/override_form.php` étend `moodleform` :

- Champ cible : `userid` (autocomplete via `core_user/form_user_selector`) OU `groupid` (`select` peuplé via `groups_get_all_groups($courseid)`), selon `mode`. Un seul champ visible.
- Champ `deadline_date` : `date_time_selector` avec `optional => true` (permet override ou pas).
- Champ `sortorder` (mode `group` uniquement) : `int`, défaut = max+1 des overrides existants du même redaction.
- Validation :
  - `deadline_date` doit être défini (en v1, c'est le seul champ overridable ; une ligne sans deadline n'a aucun effet et est rejetée). Quand d'autres champs overridables seront ajoutés, la règle deviendra « au moins un champ override doit être non-NULL ».
  - Mode `user` : pas de doublon utilisateur sur la même redaction (check via `$DB->record_exists`).
  - User filtré par `get_enrolled_users($context, 'mod/redaction:submit')`.
- Pré-remplissage : édition → charge depuis DB. Création → vide, placeholder = deadline d'instance.

### Listing

Template `templates/overrides_table.mustache` + renderable `classes/output/overrides_table.php` (implémente `\renderable` et `\templatable`).

Colonnes :

- Mode `user` : Nom utilisateur, deadline overridée, deadline d'origine, actions (édit, suppr, dupliquer).
- Mode `group` : Nom groupe, deadline overridée, deadline d'origine, sortorder, actions.

Tri : `sortorder ASC` pour group, `fullname ASC` pour user. Pagination si > 25 lignes (param `page`).

### Sécurité de chaque page

```php
require_login($course, false, $cm);
require_capability('mod/redaction:manageoverrides', $context);
// Pour POST :
require_sesskey();
```

### i18n

Nouvelles chaînes dans `lang/en/redaction.php` (puis `lang/fr/redaction.php`) :

```
overrides, useroverrides, groupoverrides,
addoverride, editoverride, deleteoverride,
overrides_none, overrides_for, overridedeadline, overridedeadline_help,
override_user_in_group_mode_warning, override_no_field_warning,
override_duplicate_user, override_sortorder, override_sortorder_help,
override_created, override_updated, override_deleted, override_deleted_event_desc,
override_confirm_delete
```

### CSS

Ajouts dans `styles.css` avec préfixe `.mod_redaction-overrides-*` (single-class préfixée, conforme à la convention du projet) :

```css
.mod_redaction-overrides-table { ... }
.mod_redaction-overrides-empty { ... }
.mod_redaction-overrides-actions { ... }
.mod_redaction-overrides-deadline-original { color: gray; text-decoration: line-through; }
```

## Enforcement et précédence

### Fonction centrale

Dans `lib.php` :

```php
/**
 * Return the effective deadline for a given user/group on a redaction instance.
 *
 * Precedence (user mode):
 *   1. User override (if any with deadline_date NOT NULL) — short-circuit
 *   2. Group override (lowest sortorder among groups the user is member of)
 *   3. Instance deadline (redaction_correction.deadline_date)
 *
 * Precedence (group submission mode, userid=0 + groupid>0):
 *   1. Group override for that groupid
 *   2. Instance deadline
 *
 * @param stdClass $redaction
 * @param int $userid 0 if checking against a group draft
 * @param int $groupid 0 for individual drafts
 * @return int|null Timestamp, or null if no deadline applies
 */
function redaction_get_effective_deadline($redaction, $userid, $groupid = 0) { ... }
```

### Helpers

```php
redaction_get_user_override($redactionid, $userid) // record or null
redaction_get_group_overrides_for_user($redactionid, $userid) // array sorted by sortorder
redaction_get_group_override($redactionid, $groupid) // record or null (for group drafts)
```

### Points d'intégration

1. **`lib.php` ligne 763** dans `redaction_can_submit_attempt()` : remplacer
   ```php
   if ($correction && !empty($correction->deadline_date) && time() > $correction->deadline_date) {
   ```
   par
   ```php
   $effective = redaction_get_effective_deadline($redaction, $submission->userid, $submission->groupid);
   if (!empty($effective) && time() > $effective) {
   ```

2. **`classes/task/auto_submit_deadline.php`** : refonte. L'algo actuel filtre les redactions par `deadline_date <= NOW`. Il faut maintenant parcourir tous les drafts ouverts et calculer la deadline effective par draft :

   ```php
   $sql = "SELECT s.id as submissionid, s.userid, s.groupid, s.contenu,
                  r.id as redactionid, r.name, r.course, r.group_submission, r.ai_enabled
           FROM {redaction_submission} s
           JOIN {redaction} r ON r.id = s.redactionid
           WHERE s.status = 0";
   ```

   Pour chaque draft : `$effective = redaction_get_effective_deadline($r, $s->userid, $s->groupid);` puis auto-submit si `$effective !== null && $effective <= $now`.

   Coût : O(drafts ouverts), acceptable vu le volume métier attendu.

3. **`view.php` + templates** affichant la deadline à l'élève : utiliser la deadline effective pour l'utilisateur courant via `redaction_get_effective_deadline($redaction, $USER->id, $groupid)`.

### Cas spécifique : soumission groupe

Pour un draft groupe (`userid=0`, `groupid>0`) :

- Priorité 1 : override **groupe** pour ce `groupid` exact.
- Priorité 2 : deadline d'instance.

Les overrides user n'ont aucun effet sur les drafts groupe. Le formulaire d'override user affiche un help text le signalant si l'activité est en mode groupe (`group_submission=1`).

## Backup / Restore

### Backup (`backup/moodle2/backup_redaction_stepslib.php`)

Ajouter un nested `<OVERRIDES>` dans la structure XML de l'activité :

```php
$overrides = new backup_nested_element('overrides');
$override = new backup_nested_element('override', ['id'], [
    'groupid', 'userid', 'deadline_date', 'sortorder', 'timecreated', 'timemodified',
]);
$redaction->add_child($overrides);
$overrides->add_child($override);
$override->set_source_table('redaction_overrides', ['redactionid' => backup::VAR_PARENTID]);
$override->annotate_ids('user', 'userid');
$override->annotate_ids('group', 'groupid');
```

### Restore (`backup/moodle2/restore_redaction_stepslib.php`)

Ajouter le chemin `/activity/redaction/overrides/override` dans `define_structure()`.

Méthode `process_redaction_override($data)` :

```php
$data->redactionid = $this->get_new_parentid('redaction');
if (!empty($data->userid)) {
    $data->userid = $this->get_mappingid('user', $data->userid);
    if (!$data->userid) { return; } // user not restored, skip
}
if (!empty($data->groupid)) {
    $data->groupid = $this->get_mappingid('group', $data->groupid);
    if (!$data->groupid) { return; } // group not restored, skip
}
$DB->insert_record('redaction_overrides', $data);
```

Skip silencieux si la cible n'est pas remappable, cohérent avec `mod_assign`.

## Privacy API

`classes/privacy/provider.php` mise à jour :

### `get_metadata()`

```php
$items->add_database_table('redaction_overrides', [
    'userid' => 'privacy:metadata:redaction_overrides:userid',
    'deadline_date' => 'privacy:metadata:redaction_overrides:deadline_date',
    'timecreated' => 'privacy:metadata:redaction_overrides:timecreated',
    'timemodified' => 'privacy:metadata:redaction_overrides:timemodified',
], 'privacy:metadata:redaction_overrides');
```

### `get_contexts_for_userid($userid)`

Ajouter une jointure sur `redaction_overrides` pour récupérer les contextes des modules où l'utilisateur a une dérogation.

### `export_user_data($contextlist)`

Pour chaque contexte module concerné, exporter les overrides de l'utilisateur en sous-collection `'overrides'` du JSON Privacy.

### `delete_data_for_user($contextlist)`

```php
$DB->delete_records_select('redaction_overrides',
    'userid = :userid AND redactionid IN (...)',
    ['userid' => $userid, ...]);
```

### `delete_data_for_all_users_in_context($context)`

```php
$DB->delete_records('redaction_overrides', ['redactionid' => $redactionid]);
```

### Note

Les overrides **groupe** ne contiennent pas de données personnelles (juste un `groupid`). Non concernées par le Privacy API.

## Suppression en cascade

### Suppression de l'instance

`lib.php` → `redaction_delete_instance($id)` : ajouter
```php
$DB->delete_records('redaction_overrides', ['redactionid' => $id]);
```

### Suppression d'un user ou d'un groupe

Observers déclarés dans `db/events.php` :

```php
$observers = [
    [
        'eventname' => '\core\event\user_deleted',
        'callback'  => '\mod_redaction\observer::user_deleted',
    ],
    [
        'eventname' => '\core\event\group_deleted',
        'callback'  => '\mod_redaction\observer::group_deleted',
    ],
];
```

Implémentation dans `classes/observer.php` (nouveau fichier) :

```php
public static function user_deleted(\core\event\user_deleted $event) {
    global $DB;
    $DB->delete_records('redaction_overrides', ['userid' => $event->objectid]);
}

public static function group_deleted(\core\event\group_deleted $event) {
    global $DB;
    $DB->delete_records('redaction_overrides', ['groupid' => $event->objectid]);
}
```

## Events émis

Six nouveaux events dans `classes/event/`, tous étendant `\core\event\base` :

- `user_override_created` (CRUD `c`, niveau `EDUCATIONAL`, objecttable `redaction_overrides`)
- `user_override_updated` (CRUD `u`)
- `user_override_deleted` (CRUD `d`)
- `group_override_created`
- `group_override_updated`
- `group_override_deleted`

Émis depuis les handlers POST de `override_edit.php` et `override_delete.php`. Permet aux logs Moodle de tracer qui a accordé quelle dérogation et quand.

## Cas limites

| Cas | Comportement |
|---|---|
| User non inscrit au cours | Filtré par l'autocomplete (`get_enrolled_users($context, 'mod/redaction:submit')`) |
| Suppression user/groupe | Cleanup auto via observer |
| Instance sans `deadline_date` mais override avec deadline | L'override l'emporte → deadline effective = override |
| Override avec `deadline_date = NULL` | Rejeté à la création (validation form, cf. plus haut). Si présent en base via migration future, la ligne est silencieusement ignorée par `redaction_get_effective_deadline()`. |
| Deux overrides groupe applicables au même user | `sortorder` tranche (plus petit gagne) |
| User override + groupe override applicable | User override court-circuite |
| Mode `group_submission=1` + override user | Override user ignorée silencieusement à l'enforcement ; help text dans le formulaire signale le fait |
| Override en doublon (même user, même redaction) | Validation côté formulaire bloque (`override_duplicate_user`) |
| Deadline override **plus tôt** que l'originale | Valide (cas anticipé) |

## Tests PHPUnit

Nouveaux fichiers dans `tests/` :

- `tests/lib_overrides_test.php` : couvre `redaction_get_effective_deadline()` — instance only, user override, group override, user > group, sortorder entre groupes, NULL deadline, group draft.
- `tests/task_auto_submit_overrides_test.php` : la tâche cron `auto_submit_deadline` respecte les deadlines effectives (user override, group override, sortorder).
- `tests/privacy_overrides_test.php` (ou extension de `tests/privacy_provider_test.php` existant) : export, delete user, delete context couvrent la nouvelle table.
- `tests/backup_restore_overrides_test.php` : roundtrip backup → restore préserve les overrides et remappe correctement user/group, skip silencieux si cible absente.

Pas de tests Behat dans v1 (cohérent avec l'existant).

## Compilation AMD

Aucun nouveau module AMD. Formulaires `moodleform` classiques, autocomplete user géré par le core Moodle. Si interactivité ajoutée plus tard, suivre la convention projet : `amd/src/` → `terser` ou `grunt` → `amd/build/`.

## Checklist de validation pré-soumission

- [ ] Aucune chaîne hard-codée (toutes via `get_string()`)
- [ ] `lang/en/redaction.php` complet
- [ ] CSS uniquement dans `styles.css` avec préfixe `.mod_redaction-overrides-*`
- [ ] Templates Mustache pour tout HTML généré
- [ ] AJAX inexistant dans cette feature (formulaires classiques) — sinon External Services
- [ ] `version.php` bumpé à `2026051200`
- [ ] Capacité `mod/redaction:manageoverrides` dans `db/access.php`
- [ ] Privacy API étendue à la nouvelle table
- [ ] Backup/restore implémentés
- [ ] PHPDoc sur toutes les fonctions publiques
- [ ] `require_sesskey()` sur les actions POST
- [ ] `require_login()` + `require_capability()` sur chaque page

## Futures évolutions (v2+)

- Notifications message à l'élève lors de l'octroi d'une dérogation (message provider).
- Override sur `submission_date` (date d'affichage).
- Override sur une date d'ouverture (`allowsubmissionsfrom`, si ajoutée au schéma instance).
- Export CSV des overrides depuis le listing.
- Import en masse (CSV) pour les classes entières.
