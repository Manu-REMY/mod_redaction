# Vue tableau de progression + filtre groupe Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre à l'enseignant de filtrer la page grading par groupe (issu du grouping de l'activité) avec dashboard et synthèse IA adaptés, et d'accéder à un tableau de progression « élève × tentative ».

**Architecture:** Étendre `submission_stats` et `ai_summary_generator` pour accepter un `groupid`. Ajouter une colonne `groupid` à `redaction_ai_summaries` avec clé unique composite. `grading.php` route entre `tab=detail` (existant) et `tab=overview` (nouveau). Nouvelle page `pages/grading_overview.php` rend une matrice statique côté serveur via une renderable.

**Tech Stack:** Moodle 5.0+, PHP 8.1+, DML, XMLDB, Mustache, AMD (jQuery), CSS namespaced.

**Spec source:** `docs/superpowers/specs/2026-05-06-grading-overview-and-group-filter-design.md`
**Cible de version:** `2026050602` (release `2.2.0`).
**Branche de travail:** `feat/grading-overview` (créée depuis `main` au début).

---

## File Structure

| Fichier | Rôle | Action |
|---|---|---|
| `version.php` | Métadonnées | Bump 2.2.0 |
| `db/install.xml` | Schéma | Ajouter `groupid` + clé composite sur `redaction_ai_summaries` |
| `db/upgrade.php` | Migration | Étape 2026050602 idempotente |
| `lib.php` | Helpers + dashboard renderer | Ajouter `redaction_get_grading_filter_groups`, `redaction_get_filtered_userids`. Étendre `redaction_render_teacher_dashboard($cm, $redaction, $groupid=0)` |
| `classes/dashboard/submission_stats.php` | Stats par activité | Constructeur accepte `$groupid`, requêtes filtrent `userid IN (...)` |
| `classes/dashboard/ai_summary_generator.php` | Synthèse IA | Constructeur accepte `$groupid`, BDD lookup avec clé composite, `get_completed_evaluations` filtre par userid |
| `classes/external/generate_ai_summary.php` | Service externe Refresh | Accepter param `groupid`, propager au generator |
| `classes/output/grading_overview_data.php` | Renderable matrice | Nouveau — construit la table |
| `pages/grading_overview.php` | Page tableau | Nouveau — inclus depuis grading.php quand tab=overview |
| `templates/grading_overview.mustache` | Template tableau | Nouveau |
| `templates/grading_navtabs.mustache` | Onglets | Nouveau |
| `templates/grading_group_filter.mustache` | Dropdown filtre | Nouveau |
| `grading.php` | Routing tab + groupid + dashboard filtré | Modifier |
| `amd/src/grading_overview.js` | Tri colonne, tooltips minibars | Nouveau |
| `amd/build/grading_overview.min.js` | Build JS | Nouveau (copie de src) |
| `lang/en/redaction.php` | Strings EN | Ajouter clés du tableau et filtre |
| `lang/fr/redaction.php` | Strings FR | Idem |
| `styles.css` | Styles tableau | Ajouter `.mod_redaction-overview-table` etc. |

---

### Task 1 : Branche + bump version + schéma BDD

**Files:**
- Create branch
- Modify: `redaction/version.php`
- Modify: `redaction/db/install.xml`

- [ ] **Step 1.1 : Créer la branche**

Depuis le repo `/Volumes/DONNEES/Claude code/mod_redaction` :

```bash
git checkout main && git pull origin main && git checkout -b feat/grading-overview
```

- [ ] **Step 1.2 : Bump version**

Modifier `redaction/version.php` :

```php
$plugin->version = 2026050602;  // YYYYMMDDXX format
$plugin->requires = 2024100700; // Moodle 4.5+
$plugin->maturity = MATURITY_BETA;
$plugin->release = '2.2.0';
```

- [ ] **Step 1.3 : Étendre install.xml — `groupid` + composite key**

Dans `redaction/db/install.xml`, sur la table `redaction_ai_summaries` :

1. Insérer le nouveau champ juste après `<FIELD NAME="redactionid" ... />` :
```xml
        <FIELD NAME="groupid" TYPE="int" LENGTH="10" NOTNULL="true" DEFAULT="0" SEQUENCE="false" COMMENT="0=global summary, !=0=per-group summary"/>
```

2. Remplacer la clé unique existante sur `redactionid` par une clé composite. Si la clé actuelle est :
```xml
        <KEY NAME="redactionid" TYPE="foreign-unique" FIELDS="redactionid" REFTABLE="redaction" REFFIELDS="id"/>
```
La transformer en clé étrangère simple + clé unique composite :
```xml
        <KEY NAME="redactionid" TYPE="foreign" FIELDS="redactionid" REFTABLE="redaction" REFFIELDS="id"/>
        <KEY NAME="redactionid_groupid" TYPE="unique" FIELDS="redactionid, groupid"/>
```

- [ ] **Step 1.4 : Vérification**

```bash
xmllint --noout redaction/db/install.xml
/usr/local/Cellar/php/8.4.4/bin/php -l redaction/version.php
```
Expected : exit 0 + `No syntax errors detected`.

- [ ] **Step 1.5 : Commit**

```bash
git add redaction/version.php redaction/db/install.xml
git commit -m "feat(schema): add groupid to redaction_ai_summaries; bump 2.2.0"
```

---

### Task 2 : Migration upgrade.php

**Files:**
- Modify: `redaction/db/upgrade.php`

- [ ] **Step 2.1 : Ajouter l'étape**

Dans `xmldb_redaction_upgrade()`, juste avant le `return true;` final :

```php
    if ($oldversion < 2026050602) {
        $table = new xmldb_table('redaction_ai_summaries');

        // Add groupid field if not present.
        $field = new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '10', null,
            XMLDB_NOTNULL, null, '0', 'redactionid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Drop the old foreign-unique key on redactionid (if it exists).
        $oldkey = new xmldb_key('redactionid', XMLDB_KEY_FOREIGN_UNIQUE,
            ['redactionid'], 'redaction', ['id']);
        if ($dbman->find_key_name($table, $oldkey)) {
            $dbman->drop_key($table, $oldkey);
        }

        // Add a plain foreign key on redactionid.
        $fkey = new xmldb_key('redactionid', XMLDB_KEY_FOREIGN,
            ['redactionid'], 'redaction', ['id']);
        if (!$dbman->find_key_name($table, $fkey)) {
            $dbman->add_key($table, $fkey);
        }

        // Add the composite unique key.
        $newkey = new xmldb_key('redactionid_groupid', XMLDB_KEY_UNIQUE,
            ['redactionid', 'groupid']);
        if (!$dbman->find_key_name($table, $newkey)) {
            $dbman->add_key($table, $newkey);
        }

        upgrade_mod_savepoint(true, 2026050602, 'redaction');
    }
```

- [ ] **Step 2.2 : Vérification**

```bash
/usr/local/Cellar/php/8.4.4/bin/php -l redaction/db/upgrade.php
```
Expected : `No syntax errors detected`.

- [ ] **Step 2.3 : Commit**

```bash
git add redaction/db/upgrade.php
git commit -m "feat(upgrade): migrate redaction_ai_summaries to (redactionid, groupid)"
```

---

### Task 3 : Helpers `lib.php`

**Files:**
- Modify: `redaction/lib.php`

- [ ] **Step 3.1 : Ajouter les helpers**

Quelque part dans `redaction/lib.php` (ajouter à la fin, juste avant la fermeture du fichier ou après les autres `redaction_*` helpers) :

```php
/**
 * Returns the groups available for filtering on the grading page.
 *
 * If the activity has a grouping assigned, only its groups are returned.
 * Otherwise all course groups are returned.
 *
 * @param stdClass $cm Course module record (with groupingid)
 * @param int $courseid
 * @return array array of group records keyed by id
 */
function redaction_get_grading_filter_groups($cm, int $courseid): array {
    if (!empty($cm->groupingid)) {
        return groups_get_all_groups($courseid, 0, $cm->groupingid);
    }
    return groups_get_all_groups($courseid);
}

/**
 * Returns the user IDs belonging to a group, restricted to those with
 * the redaction:submit capability in the course.
 *
 * @param int $courseid
 * @param int $groupid 0 means no filter; returns all enrolled with the capability
 * @return int[] array of user IDs
 */
function redaction_get_filtered_userids(int $courseid, int $groupid): array {
    $coursecontext = context_course::instance($courseid);
    $users = get_enrolled_users($coursecontext, 'mod/redaction:submit', $groupid, 'u.id');
    return array_keys($users);
}
```

- [ ] **Step 3.2 : Étendre `redaction_render_teacher_dashboard` pour accepter $groupid**

Localiser la fonction `redaction_render_teacher_dashboard($cm, $redaction)` (vers la ligne 658 dans le fichier actuel). Modifier sa signature et propager le paramètre :

Avant :
```php
function redaction_render_teacher_dashboard($cm, $redaction) {
    global $OUTPUT, $PAGE;
    // Pre-load JS strings used by the dashboard AMD module via M.util.get_string.
    $PAGE->requires->strings_for_js(['dashboard_grade_distribution'], 'mod_redaction');
    // Get submission statistics.
    $submissionstats = new \mod_redaction\dashboard\submission_stats($redaction->id);
```

Après :
```php
function redaction_render_teacher_dashboard($cm, $redaction, int $groupid = 0) {
    global $OUTPUT, $PAGE;
    // Pre-load JS strings used by the dashboard AMD module via M.util.get_string.
    $PAGE->requires->strings_for_js(['dashboard_grade_distribution'], 'mod_redaction');
    // Get submission statistics for the current group filter (0 = all).
    $submissionstats = new \mod_redaction\dashboard\submission_stats($redaction->id, $groupid);
```

Plus loin dans la même fonction, propager `$groupid` à l'`ai_summary_generator` :

Avant :
```php
        $summarygenerator = new \mod_redaction\dashboard\ai_summary_generator($redaction->id);
        $summary = $summarygenerator->get_summary();
```

Après :
```php
        $summarygenerator = new \mod_redaction\dashboard\ai_summary_generator($redaction->id, $groupid);
        $summary = $summarygenerator->get_summary();
```

Et ajouter `'groupid' => $groupid` dans le `$context` du template :
```php
    $context = [
        'cmid' => $cm->id,
        'groupid' => $groupid,
        'ai_enabled' => (bool) $redaction->ai_enabled,
        // ... reste inchangé ...
    ];
```

- [ ] **Step 3.3 : Vérification**

```bash
/usr/local/Cellar/php/8.4.4/bin/php -l redaction/lib.php
```

- [ ] **Step 3.4 : Commit**

```bash
git add redaction/lib.php
git commit -m "feat(lib): grading filter helpers + dashboard accepts groupid"
```

---

### Task 4 : `submission_stats` accepte `$groupid`

**Files:**
- Modify: `redaction/classes/dashboard/submission_stats.php`

- [ ] **Step 4.1 : Modifier le constructeur et ajouter `$userids`**

Localiser la classe `submission_stats` (`classes/dashboard/submission_stats.php`). Modifier le constructeur :

Avant :
```php
    public function __construct(int $redactionid) {
        global $DB;

        $this->redactionid = $redactionid;
        $this->redaction = $DB->get_record('redaction', ['id' => $redactionid], '*', MUST_EXIST);
        $this->courseid = $this->redaction->course;
    }
```

Après :
```php
    /** @var int Group ID filter (0 = no filter) */
    protected $groupid;

    /** @var array|null Filtered user IDs, or null when no filter */
    protected $userids;

    public function __construct(int $redactionid, int $groupid = 0) {
        global $DB;

        $this->redactionid = $redactionid;
        $this->groupid = $groupid;
        $this->redaction = $DB->get_record('redaction', ['id' => $redactionid], '*', MUST_EXIST);
        $this->courseid = $this->redaction->course;

        if ($groupid > 0) {
            require_once($GLOBALS['CFG']->dirroot . '/mod/redaction/lib.php');
            $this->userids = redaction_get_filtered_userids($this->courseid, $groupid);
        } else {
            $this->userids = null;
        }
    }
```

- [ ] **Step 4.2 : Étendre la clé de cache pour inclure le groupid**

Localiser dans `get_stats()` :

Avant :
```php
        $cache = \cache::make('mod_redaction', 'dashboard_stats');
        $key = 'stats_' . $this->redactionid;
```

Après :
```php
        $cache = \cache::make('mod_redaction', 'dashboard_stats');
        $key = 'stats_' . $this->redactionid . '_' . $this->groupid;
```

- [ ] **Step 4.3 : Filtrer les requêtes par `userid IN (...)`**

Ajouter un helper privé en haut de la classe :

```php
    /**
     * Build a SQL fragment + params array for the optional userid filter.
     *
     * @return array [sqlfragment, params] — empty fragment '' when no filter.
     */
    protected function build_userid_filter(): array {
        global $DB;
        if ($this->userids === null) {
            return ['', []];
        }
        if (empty($this->userids)) {
            // Group is empty — force no rows.
            return [' AND 1=0', []];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($this->userids, SQL_PARAMS_QM);
        return [' AND userid ' . $insql, $inparams];
    }
```

Modifier les méthodes existantes pour utiliser ce filtre. Pour `count_by_status` :

Avant :
```php
    protected function count_by_status(int $status): int {
        global $DB;

        return $DB->count_records('redaction_submission', [
            'redactionid' => $this->redactionid,
            'status' => $status,
        ]);
    }
```

Après :
```php
    protected function count_by_status(int $status): int {
        global $DB;

        [$userfilter, $userparams] = $this->build_userid_filter();
        $params = array_merge([$this->redactionid, $status], $userparams);
        return $DB->count_records_select(
            'redaction_submission',
            'redactionid = ? AND status = ?' . $userfilter,
            $params
        );
    }
```

Pour `count_graded` :

Avant :
```php
    protected function count_graded(): int {
        global $DB;

        return $DB->count_records_select(
            'redaction_submission',
            'redactionid = ? AND grade IS NOT NULL',
            [$this->redactionid]
        );
    }
```

Après :
```php
    protected function count_graded(): int {
        global $DB;

        [$userfilter, $userparams] = $this->build_userid_filter();
        $params = array_merge([$this->redactionid], $userparams);
        return $DB->count_records_select(
            'redaction_submission',
            'redactionid = ? AND grade IS NOT NULL' . $userfilter,
            $params
        );
    }
```

Pour `get_grade_stats` (la requête `get_records_select`) :

Avant :
```php
        $grades = $DB->get_records_select(
            'redaction_submission',
            'redactionid = ? AND grade IS NOT NULL',
            [$this->redactionid],
            '',
            'grade'
        );
```

Après :
```php
        [$userfilter, $userparams] = $this->build_userid_filter();
        $params = array_merge([$this->redactionid], $userparams);
        $grades = $DB->get_records_select(
            'redaction_submission',
            'redactionid = ? AND grade IS NOT NULL' . $userfilter,
            $params,
            '',
            'grade'
        );
```

Pour `get_ai_evaluation_stats` (la requête `GROUP BY status`) :

Avant :
```php
        $sql = "SELECT status, COUNT(*) as count
                FROM {redaction_ai_evaluations}
                WHERE redactionid = ?
                GROUP BY status";

        $counts = $DB->get_records_sql($sql, [$this->redactionid]);
```

Après :
```php
        [$userfilter, $userparams] = $this->build_userid_filter();
        $sql = "SELECT status, COUNT(*) as count
                FROM {redaction_ai_evaluations}
                WHERE redactionid = ?" . $userfilter . "
                GROUP BY status";
        $counts = $DB->get_records_sql($sql, array_merge([$this->redactionid], $userparams));
```

Pour `get_expected_submission_count` :

Avant :
```php
    protected function get_expected_submission_count(): int {
        if ($this->redaction->group_submission) {
            // Group mode - count groups.
            $groups = groups_get_all_groups($this->courseid);
            return count($groups);
        } else {
            // Individual mode - count enrolled students with submit capability.
            $context = \context_course::instance($this->courseid);
            $users = get_enrolled_users($context, 'mod/redaction:submit');
            return count($users);
        }
    }
```

Après :
```php
    protected function get_expected_submission_count(): int {
        if ($this->redaction->group_submission) {
            if ($this->groupid > 0) {
                // Group mode + group filter: 1 expected (the filtered group itself).
                return 1;
            }
            $groups = groups_get_all_groups($this->courseid);
            return count($groups);
        }

        if ($this->userids !== null) {
            return count($this->userids);
        }
        $context = \context_course::instance($this->courseid);
        $users = get_enrolled_users($context, 'mod/redaction:submit');
        return count($users);
    }
```

- [ ] **Step 4.4 : Vérification**

```bash
/usr/local/Cellar/php/8.4.4/bin/php -l redaction/classes/dashboard/submission_stats.php
```

- [ ] **Step 4.5 : Commit**

```bash
git add redaction/classes/dashboard/submission_stats.php
git commit -m "feat(stats): submission_stats supports per-group filtering"
```

---

### Task 5 : `ai_summary_generator` accepte `$groupid`

**Files:**
- Modify: `redaction/classes/dashboard/ai_summary_generator.php`

- [ ] **Step 5.1 : Ajouter `$groupid` au constructeur**

Localiser la classe `ai_summary_generator`. Le constructeur actuel :

```php
    public function __construct(int $redactionid) {
        global $DB;
        $this->redactionid = $redactionid;
        $this->redaction = $DB->get_record('redaction', ['id' => $redactionid], '*', MUST_EXIST);
    }
```

Devient :

```php
    /** @var int Group ID filter (0 = global summary) */
    protected $groupid;

    public function __construct(int $redactionid, int $groupid = 0) {
        global $DB;
        $this->redactionid = $redactionid;
        $this->groupid = $groupid;
        $this->redaction = $DB->get_record('redaction', ['id' => $redactionid], '*', MUST_EXIST);
    }
```

- [ ] **Step 5.2 : Filtrer les évaluations par groupe**

Localiser `get_completed_evaluations` :

Avant :
```php
    protected function get_completed_evaluations(int $page = 0, int $perpage = 0): array {
        global $DB;

        $sql = 'SELECT *
                FROM {redaction_ai_evaluations}
                WHERE redactionid = ? AND status IN (?, ?)
                ORDER BY timecreated DESC';
        $params = [$this->redactionid, 'completed', 'applied'];
```

Après :
```php
    protected function get_completed_evaluations(int $page = 0, int $perpage = 0): array {
        global $DB;

        $userfilter = '';
        $userparams = [];
        if ($this->groupid > 0) {
            require_once($GLOBALS['CFG']->dirroot . '/mod/redaction/lib.php');
            $userids = redaction_get_filtered_userids($this->redaction->course, $this->groupid);
            if (empty($userids)) {
                return [];
            }
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_QM);
            $userfilter = ' AND userid ' . $insql;
            $userparams = $inparams;
        }

        $sql = 'SELECT *
                FROM {redaction_ai_evaluations}
                WHERE redactionid = ? AND status IN (?, ?)' . $userfilter . '
                ORDER BY timecreated DESC';
        $params = array_merge([$this->redactionid, 'completed', 'applied'], $userparams);
```

- [ ] **Step 5.3 : Lookup et écriture par clé composite (redactionid, groupid)**

Modifier `get_summary` pour passer le groupid au DB lookup. Avant :

```php
    public function get_summary(bool $force = false): ?object {
        global $DB;

        $summary = $DB->get_record('redaction_ai_summaries', ['redactionid' => $this->redactionid]);
```

Après :
```php
    public function get_summary(bool $force = false): ?object {
        global $DB;

        $summary = $DB->get_record('redaction_ai_summaries', [
            'redactionid' => $this->redactionid,
            'groupid' => $this->groupid,
        ]);
```

Localiser `save_summary` (ailleurs dans le fichier) et modifier la lecture/écriture :

Avant (selon le pattern habituel) :
```php
    protected function save_summary(object $newSummary, int $count): void {
        global $DB;

        $existing = $DB->get_record('redaction_ai_summaries', ['redactionid' => $this->redactionid]);
        if ($existing) {
            $newSummary->id = $existing->id;
            $newSummary->redactionid = $this->redactionid;
            $newSummary->timemodified = time();
            $DB->update_record('redaction_ai_summaries', $newSummary);
        } else {
            $newSummary->redactionid = $this->redactionid;
            $newSummary->timecreated = time();
            $newSummary->timemodified = time();
            $DB->insert_record('redaction_ai_summaries', $newSummary);
        }
    }
```

Après :
```php
    protected function save_summary(object $newSummary, int $count): void {
        global $DB;

        $existing = $DB->get_record('redaction_ai_summaries', [
            'redactionid' => $this->redactionid,
            'groupid' => $this->groupid,
        ]);
        if ($existing) {
            $newSummary->id = $existing->id;
            $newSummary->redactionid = $this->redactionid;
            $newSummary->groupid = $this->groupid;
            $newSummary->timemodified = time();
            $DB->update_record('redaction_ai_summaries', $newSummary);
        } else {
            $newSummary->redactionid = $this->redactionid;
            $newSummary->groupid = $this->groupid;
            $newSummary->timecreated = time();
            $newSummary->timemodified = time();
            $DB->insert_record('redaction_ai_summaries', $newSummary);
        }
    }
```

**Avant d'éditer**, lis la fonction `save_summary` exacte du fichier — adapte si la structure interne diffère, mais préserve la logique upsert.

Localiser `invalidate_cache` :

Avant :
```php
    public function invalidate_cache(): void {
        global $DB;
        $DB->delete_records('redaction_ai_summaries', ['redactionid' => $this->redactionid]);
    }
```

Après :
```php
    public function invalidate_cache(): void {
        global $DB;
        $DB->delete_records('redaction_ai_summaries', [
            'redactionid' => $this->redactionid,
            'groupid' => $this->groupid,
        ]);
    }
```

- [ ] **Step 5.4 : Vérification**

```bash
/usr/local/Cellar/php/8.4.4/bin/php -l redaction/classes/dashboard/ai_summary_generator.php
```

- [ ] **Step 5.5 : Commit**

```bash
git add redaction/classes/dashboard/ai_summary_generator.php
git commit -m "feat(ai_summary): per-group cached summaries via composite key"
```

---

### Task 6 : Web service `generate_ai_summary` propage `$groupid`

**Files:**
- Modify: `redaction/classes/external/generate_ai_summary.php`
- Modify: `redaction/amd/src/dashboard.js`
- Modify: `redaction/amd/build/dashboard.min.js`

- [ ] **Step 6.1 : Ajouter le paramètre `groupid` dans le service**

Localiser `execute_parameters()` dans `classes/external/generate_ai_summary.php`. Ajouter `groupid` :

```php
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'force' => new external_value(PARAM_BOOL, 'Force regeneration', VALUE_DEFAULT, false),
            'groupid' => new external_value(PARAM_INT, 'Group ID filter (0 = global)', VALUE_DEFAULT, 0),
        ]);
    }
```

Modifier `execute()` pour propager :

Avant (la signature et la création du generator) :
```php
    public static function execute(int $cmid, bool $force = false): array {
        // ...
        $generator = new \mod_redaction\dashboard\ai_summary_generator($redaction->id);
```

Après :
```php
    public static function execute(int $cmid, bool $force = false, int $groupid = 0): array {
        // ...
        $generator = new \mod_redaction\dashboard\ai_summary_generator($redaction->id, $groupid);
```

**Avant d'éditer**, lis le fichier complet pour adapter la signature à ce qui existe (préserver les autres validations / retours).

- [ ] **Step 6.2 : Mettre à jour `dashboard.js` pour passer `groupid`**

Localiser le call AJAX dans `amd/src/dashboard.js` (vers la ligne 124) :

Avant :
```js
        Ajax.call([{
            methodname: 'mod_redaction_generate_ai_summary',
            args: {
                cmid: cmid,
                force: true
            }
        }])[0].then(function(response) {
```

Après :
```js
        // Pull the current group filter from the dashboard root (set by PHP).
        var dashRoot = document.querySelector('[data-mod-redaction-dashboard]');
        var groupid = dashRoot ? parseInt(dashRoot.dataset.groupid || '0', 10) : 0;

        Ajax.call([{
            methodname: 'mod_redaction_generate_ai_summary',
            args: {
                cmid: cmid,
                force: true,
                groupid: groupid
            }
        }])[0].then(function(response) {
```

- [ ] **Step 6.3 : Ajouter le data-attribute dans le template `dashboard_teacher.mustache`**

Localiser la balise racine du dashboard dans `templates/dashboard_teacher.mustache`. Sa div d'ouverture (souvent `<div class="mod_redaction-dashboard">` ou similaire). Y ajouter `data-mod-redaction-dashboard data-groupid="{{groupid}}"`.

Si la balise actuelle est :
```mustache
<div class="mod_redaction-dashboard">
```
Modifier en :
```mustache
<div class="mod_redaction-dashboard" data-mod-redaction-dashboard data-groupid="{{groupid}}">
```

**Avant d'éditer**, lis l'en-tête du template pour confirmer le sélecteur de la balise racine. Si la racine est différente, applique l'attribut sur l'élément le plus extérieur du dashboard.

- [ ] **Step 6.4 : Synchroniser le build**

```bash
cp redaction/amd/src/dashboard.js redaction/amd/build/dashboard.min.js
```

- [ ] **Step 6.5 : Vérification**

```bash
/usr/local/Cellar/php/8.4.4/bin/php -l redaction/classes/external/generate_ai_summary.php
```

- [ ] **Step 6.6 : Commit**

```bash
git add redaction/classes/external/generate_ai_summary.php \
         redaction/amd/src/dashboard.js \
         redaction/amd/build/dashboard.min.js \
         redaction/templates/dashboard_teacher.mustache
git commit -m "feat(ai_summary): propagate groupid through Refresh AJAX path"
```

---

### Task 7 : Templates filtre groupe + onglets

**Files:**
- Create: `redaction/templates/grading_group_filter.mustache`
- Create: `redaction/templates/grading_navtabs.mustache`

- [ ] **Step 7.1 : Créer `grading_group_filter.mustache`**

Contenu du fichier `redaction/templates/grading_group_filter.mustache` :

```mustache
{{!
    @template mod_redaction/grading_group_filter

    Group filter dropdown for the grading page.

    Context variables required:
    * cmid - Course module ID
    * currenttab - Current tab name ('detail' or 'overview')
    * currentgroupid - Currently selected group ID (0 = all)
    * baseurl - URL base (without groupid) used for the form action
    * groups - array of {id, name, selected}
    * has_groups - Boolean true when at least one group is available
}}
<form method="get" action="{{baseurl}}" class="mod_redaction-group-filter mb-3">
    <input type="hidden" name="id" value="{{cmid}}">
    <input type="hidden" name="page" value="grading">
    <input type="hidden" name="tab" value="{{currenttab}}">

    <label for="mod_redaction_group_filter">{{#str}}group_filter_label, redaction{{/str}}</label>
    <select id="mod_redaction_group_filter" name="groupid" class="form-select"
            onchange="this.form.submit()">
        <option value="0" {{^currentgroupid}}selected{{/currentgroupid}}>
            {{#str}}group_filter_all, redaction{{/str}}
        </option>
        {{#groups}}
            <option value="{{id}}" {{#selected}}selected{{/selected}}>{{name}}</option>
        {{/groups}}
    </select>
</form>
```

- [ ] **Step 7.2 : Créer `grading_navtabs.mustache`**

Contenu du fichier `redaction/templates/grading_navtabs.mustache` :

```mustache
{{!
    @template mod_redaction/grading_navtabs

    Tab navigation between the detail view and the progression overview.

    Context variables required:
    * detail_url - URL for the detail tab
    * overview_url - URL for the overview tab
    * is_detail - Boolean true when the detail tab is active
    * is_overview - Boolean true when the overview tab is active
}}
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <a class="nav-link {{#is_detail}}active{{/is_detail}}" href="{{detail_url}}">
            {{#str}}tab_grading_detail, redaction{{/str}}
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{#is_overview}}active{{/is_overview}}" href="{{overview_url}}">
            {{#str}}tab_grading_overview, redaction{{/str}}
        </a>
    </li>
</ul>
```

- [ ] **Step 7.3 : Commit**

```bash
git add redaction/templates/grading_group_filter.mustache \
         redaction/templates/grading_navtabs.mustache
git commit -m "feat(grading): add group filter dropdown + nav tabs templates"
```

---

### Task 8 : Renderable `grading_overview_data`

**Files:**
- Create: `redaction/classes/output/grading_overview_data.php`

- [ ] **Step 8.1 : Créer la classe**

Contenu complet du fichier `redaction/classes/output/grading_overview_data.php` :

```php
<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Renderable + templatable data for the grading progression overview table.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\output;

defined('MOODLE_INTERNAL') || die();

use renderable;
use renderer_base;
use templatable;
use moodle_url;

class grading_overview_data implements renderable, templatable {

    /** @var int */
    protected $cmid;
    /** @var int */
    protected $redactionid;
    /** @var int */
    protected $courseid;
    /** @var int 0 = no filter */
    protected $groupid;
    /** @var int */
    protected $maxattempts;

    public function __construct(int $cmid, int $redactionid, int $courseid, int $groupid, int $maxattempts) {
        $this->cmid = $cmid;
        $this->redactionid = $redactionid;
        $this->courseid = $courseid;
        $this->groupid = $groupid;
        $this->maxattempts = $maxattempts;
    }

    public function export_for_template(renderer_base $output): array {
        global $DB;

        $redaction = $DB->get_record('redaction', ['id' => $this->redactionid], '*', MUST_EXIST);
        $isgroupmode = (bool) $redaction->group_submission;

        $headers = [];
        for ($i = 1; $i <= $this->maxattempts; $i++) {
            $headers[] = ['label' => get_string('overview_attempt_header', 'redaction', $i)];
        }

        $rows = $isgroupmode ? $this->build_group_rows() : $this->build_student_rows();

        return [
            'headers' => $headers,
            'rows' => $rows,
            'has_rows' => !empty($rows),
            'isgroupmode' => $isgroupmode,
            'cmid' => $this->cmid,
            'maxattempts' => $this->maxattempts,
        ];
    }

    /**
     * One row per student in the activity (filtered by group).
     *
     * @return array
     */
    protected function build_student_rows(): array {
        global $DB;
        require_once($GLOBALS['CFG']->dirroot . '/mod/redaction/lib.php');

        $coursecontext = \context_course::instance($this->courseid);
        $users = get_enrolled_users(
            $coursecontext,
            'mod/redaction:submit',
            $this->groupid,
            'u.id, u.firstname, u.lastname',
            'u.lastname, u.firstname'
        );

        if (empty($users)) {
            return [];
        }

        $userids = array_keys($users);
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_QM);

        // Fetch every submission's id for these users in this redaction.
        $sql = 'SELECT id, userid FROM {redaction_submission}
                 WHERE redactionid = ? AND groupid = 0 AND userid ' . $insql;
        $submissions = $DB->get_records_sql($sql, array_merge([$this->redactionid], $inparams));
        $submissionByUser = [];
        $submissionIds = [];
        foreach ($submissions as $s) {
            $submissionByUser[$s->userid] = $s->id;
            $submissionIds[] = $s->id;
        }

        // Fetch all relevant evaluations in one query.
        $evalsBySubmission = $this->load_evaluations_by_submission($submissionIds);

        $rows = [];
        foreach ($users as $user) {
            $sid = $submissionByUser[$user->id] ?? null;
            $evals = ($sid && isset($evalsBySubmission[$sid])) ? $evalsBySubmission[$sid] : [];
            $rows[] = [
                'name' => fullname($user),
                'cells' => $this->build_cells($evals, $sid),
            ];
        }
        return $rows;
    }

    /**
     * One row per group when group_submission=true.
     *
     * @return array
     */
    protected function build_group_rows(): array {
        global $DB;

        $groups = ($this->groupid > 0)
            ? [$this->groupid => $DB->get_record('groups', ['id' => $this->groupid], '*', MUST_EXIST)]
            : groups_get_all_groups($this->courseid);

        if (empty($groups)) {
            return [];
        }

        $groupids = array_keys($groups);
        [$insql, $inparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_QM);

        $sql = 'SELECT id, groupid FROM {redaction_submission}
                 WHERE redactionid = ? AND userid = 0 AND groupid ' . $insql;
        $submissions = $DB->get_records_sql($sql, array_merge([$this->redactionid], $inparams));
        $submissionByGroup = [];
        $submissionIds = [];
        foreach ($submissions as $s) {
            $submissionByGroup[$s->groupid] = $s->id;
            $submissionIds[] = $s->id;
        }

        $evalsBySubmission = $this->load_evaluations_by_submission($submissionIds);

        $rows = [];
        foreach ($groups as $group) {
            $sid = $submissionByGroup[$group->id] ?? null;
            $evals = ($sid && isset($evalsBySubmission[$sid])) ? $evalsBySubmission[$sid] : [];
            $rows[] = [
                'name' => format_string($group->name),
                'cells' => $this->build_cells($evals, $sid),
            ];
        }
        return $rows;
    }

    /**
     * Load evaluations for the given submission IDs grouped by submissionid,
     * ordered chronologically (oldest first).
     *
     * @param array $submissionIds
     * @return array map [submissionid => evaluation[]]
     */
    protected function load_evaluations_by_submission(array $submissionIds): array {
        global $DB;
        if (empty($submissionIds)) {
            return [];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($submissionIds, SQL_PARAMS_QM);
        $sql = 'SELECT id, submissionid, status, parsed_grade, criteria_json, timecreated
                  FROM {redaction_ai_evaluations}
                 WHERE submissionid ' . $insql . '
              ORDER BY submissionid, timecreated ASC, id ASC';
        $rows = $DB->get_records_sql($sql, $inparams);
        $map = [];
        foreach ($rows as $r) {
            $map[$r->submissionid][] = $r;
        }
        return $map;
    }

    /**
     * Build $maxattempts cells for one row.
     *
     * Returns an array of cell descriptors. Empty cells are filled with hasattempt=false.
     * The last non-empty cell is flagged is_latest=true and embeds criteria mini-bars.
     *
     * @param array $evals chronological evaluations
     * @param int|null $submissionId
     * @return array cells
     */
    protected function build_cells(array $evals, ?int $submissionId): array {
        $cells = [];
        $latestIndex = -1;
        for ($i = 0; $i < count($evals); $i++) {
            $latestIndex = $i;
        }

        for ($i = 0; $i < $this->maxattempts; $i++) {
            if (!isset($evals[$i])) {
                $cells[] = ['hasattempt' => false];
                continue;
            }
            $eval = $evals[$i];
            $isLatest = ($i === $latestIndex);
            $cells[] = $this->build_cell($eval, $isLatest, $submissionId);
        }
        return $cells;
    }

    /**
     * Build a single cell descriptor.
     *
     * @param object $eval
     * @param bool $isLatest
     * @param int|null $submissionId
     * @return array
     */
    protected function build_cell(object $eval, bool $isLatest, ?int $submissionId): array {
        $grade = $eval->parsed_grade !== null ? (float) $eval->parsed_grade : null;
        $level = $this->level_for_grade($grade);
        $statusicon = $this->status_icon($eval->status);

        $detailurl = '';
        if ($submissionId !== null) {
            $detailurl = (new moodle_url('/mod/redaction/view.php', [
                'id' => $this->cmid,
                'page' => 'grading',
                'tab' => 'detail',
                'itemid' => $submissionId,
            ]))->out(false);
        }

        $cell = [
            'hasattempt' => true,
            'grade' => $grade !== null ? number_format($grade, 1) : '—',
            'levelclass' => $level,
            'statusicon' => $statusicon,
            'detailurl' => $detailurl,
            'is_latest' => $isLatest,
        ];

        if ($isLatest && !empty($eval->criteria_json)) {
            $cell['criteria'] = $this->parse_criteria_minibar($eval->criteria_json);
            $cell['has_criteria'] = !empty($cell['criteria']);
        } else {
            $cell['criteria'] = [];
            $cell['has_criteria'] = false;
        }
        return $cell;
    }

    /**
     * Map a grade /20 to a level class.
     */
    protected function level_for_grade(?float $grade): string {
        if ($grade === null) {
            return 'unknown';
        }
        $percent = ($grade / 20) * 100;
        if ($percent >= 80) {
            return 'excellent';
        }
        if ($percent >= 65) {
            return 'good';
        }
        if ($percent >= 50) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Map an evaluation status to an emoji icon and an aria label.
     */
    protected function status_icon(string $status): string {
        switch ($status) {
            case 'completed': return '&#x2705;';
            case 'applied': return '&#x2713;';
            case 'pending':
            case 'processing': return '&#x23F3;';
            case 'failed': return '&#x26A0;&#xFE0F;';
            default: return '';
        }
    }

    /**
     * Parse criteria_json to mini-bar descriptors.
     */
    protected function parse_criteria_minibar(string $json): array {
        $parsed = json_decode($json, true);
        if (!is_array($parsed)) {
            return [];
        }
        $bars = [];
        foreach ($parsed as $crit) {
            $score = isset($crit['score']) ? (float) $crit['score'] : 0;
            $max = isset($crit['max']) && $crit['max'] > 0 ? (float) $crit['max'] : 5;
            $percent = max(0, min(100, ($score / $max) * 100));
            $level = $this->level_for_grade($score / $max * 20);
            $bars[] = [
                'name' => $crit['name'] ?? '',
                'score' => number_format($score, 1),
                'max' => number_format($max, 0),
                'percentage' => round($percent),
                'levelclass' => $level,
            ];
        }
        return $bars;
    }
}
```

- [ ] **Step 8.2 : Vérification**

```bash
/usr/local/Cellar/php/8.4.4/bin/php -l redaction/classes/output/grading_overview_data.php
```

- [ ] **Step 8.3 : Commit**

```bash
git add redaction/classes/output/grading_overview_data.php
git commit -m "feat(overview): grading_overview_data renderable (rows × attempts)"
```

---

### Task 9 : Template `grading_overview.mustache`

**Files:**
- Create: `redaction/templates/grading_overview.mustache`

- [ ] **Step 9.1 : Créer le template**

Contenu complet du fichier :

```mustache
{{!
    @template mod_redaction/grading_overview

    Progression overview table: rows = students/groups, columns = attempts.

    Context variables required:
    * cmid
    * isgroupmode
    * has_rows
    * headers - array of {label}
    * rows - array of {name, cells}
        cells - array of cell descriptors
}}
<div class="mod_redaction-overview-wrapper">
    {{#has_rows}}
        <table class="mod_redaction-overview-table table table-bordered table-sm" data-sort-name="">
            <thead>
                <tr>
                    <th class="mod_redaction-overview-name-col" data-sort="name">
                        {{#isgroupmode}}{{#str}}group, group{{/str}}{{/isgroupmode}}
                        {{^isgroupmode}}{{#str}}overview_student_col, redaction{{/str}}{{/isgroupmode}}
                    </th>
                    {{#headers}}
                        <th class="text-center">{{label}}</th>
                    {{/headers}}
                </tr>
            </thead>
            <tbody>
                {{#rows}}
                    <tr>
                        <td class="mod_redaction-overview-name">{{name}}</td>
                        {{#cells}}
                            {{#hasattempt}}
                                <td class="mod_redaction-overview-cell mod_redaction-overview-cell-{{levelclass}} {{#is_latest}}mod_redaction-overview-cell-latest{{/is_latest}}">
                                    <a href="{{detailurl}}" class="mod_redaction-overview-link">
                                        <span class="mod_redaction-overview-grade">{{grade}}</span>
                                        <span class="mod_redaction-overview-status">{{{statusicon}}}</span>
                                    </a>
                                    {{#is_latest}}
                                        {{#has_criteria}}
                                            <div class="mod_redaction-overview-minibars">
                                                {{#criteria}}
                                                    <div class="mod_redaction-overview-minibar mod_redaction-overview-minibar-{{levelclass}}"
                                                         title="{{name}}: {{score}}/{{max}}"
                                                         style="width: {{percentage}}%"></div>
                                                {{/criteria}}
                                            </div>
                                        {{/has_criteria}}
                                    {{/is_latest}}
                                </td>
                            {{/hasattempt}}
                            {{^hasattempt}}
                                <td class="mod_redaction-overview-cell mod_redaction-overview-cell-empty">&mdash;</td>
                            {{/hasattempt}}
                        {{/cells}}
                    </tr>
                {{/rows}}
            </tbody>
        </table>
    {{/has_rows}}
    {{^has_rows}}
        <div class="alert alert-info">{{#str}}overview_no_data, redaction{{/str}}</div>
    {{/has_rows}}
</div>
```

- [ ] **Step 9.2 : Commit**

```bash
git add redaction/templates/grading_overview.mustache
git commit -m "feat(overview): grading_overview Mustache template"
```

---

### Task 10 : Page `pages/grading_overview.php`

**Files:**
- Create: `redaction/pages/grading_overview.php`

- [ ] **Step 10.1 : Créer la page**

Contenu complet du fichier :

```php
<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Progression overview page (table view) for the grading interface.
 *
 * Expected variables in scope (set by grading.php which includes this file):
 *   $cm, $course, $redaction, $groupid, $renderer
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/redaction/lib.php');

$maxattempts = redaction_effective_max_attempts($redaction);

$overviewdata = new \mod_redaction\output\grading_overview_data(
    $cm->id,
    $redaction->id,
    $course->id,
    $groupid,
    $maxattempts
);

echo $renderer->render_from_template(
    'mod_redaction/grading_overview',
    $overviewdata->export_for_template($renderer)
);

$PAGE->requires->js_call_amd('mod_redaction/grading_overview', 'init', [[
    'cmid' => $cm->id,
]]);
```

- [ ] **Step 10.2 : Vérification**

```bash
/usr/local/Cellar/php/8.4.4/bin/php -l redaction/pages/grading_overview.php
```

- [ ] **Step 10.3 : Commit**

```bash
git add redaction/pages/grading_overview.php
git commit -m "feat(overview): pages/grading_overview.php renders the progression table"
```

---

### Task 11 : AMD module `grading_overview.js`

**Files:**
- Create: `redaction/amd/src/grading_overview.js`
- Create: `redaction/amd/build/grading_overview.min.js`

- [ ] **Step 11.1 : Créer le module source**

Contenu complet de `redaction/amd/src/grading_overview.js` :

```js
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Progression overview interactions (column sort).
 *
 * @module     mod_redaction/grading_overview
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {

    /**
     * Sort the rows of the overview table by student/group name.
     *
     * @param {HTMLTableElement} table
     */
    function sortByName(table) {
        var tbody = table.tBodies[0];
        var rows = Array.prototype.slice.call(tbody.rows);
        var asc = table.dataset.sortName !== 'asc';
        rows.sort(function(a, b) {
            var an = a.cells[0].textContent.trim().toLowerCase();
            var bn = b.cells[0].textContent.trim().toLowerCase();
            if (an < bn) {
                return asc ? -1 : 1;
            }
            if (an > bn) {
                return asc ? 1 : -1;
            }
            return 0;
        });
        rows.forEach(function(row) {
            tbody.appendChild(row);
        });
        table.dataset.sortName = asc ? 'asc' : 'desc';
    }

    return {
        init: function() {
            var table = document.querySelector('.mod_redaction-overview-table');
            if (!table) {
                return;
            }
            var nameHeader = table.querySelector('th[data-sort="name"]');
            if (nameHeader) {
                nameHeader.style.cursor = 'pointer';
                nameHeader.addEventListener('click', function() {
                    sortByName(table);
                });
            }
        },
    };
});
```

- [ ] **Step 11.2 : Synchroniser le build**

```bash
cp redaction/amd/src/grading_overview.js redaction/amd/build/grading_overview.min.js
```

- [ ] **Step 11.3 : Commit**

```bash
git add redaction/amd/src/grading_overview.js redaction/amd/build/grading_overview.min.js
git commit -m "feat(overview): JS module for column sort"
```

---

### Task 12 : Routing `grading.php` (tabs + group filter + dashboard filtré)

**Files:**
- Modify: `redaction/grading.php`

- [ ] **Step 12.1 : Lire la structure existante**

Lire `redaction/grading.php` lignes 25-160 pour comprendre la structure : déclaration `$groupmode`, build `$navitems`, calcul `$currentid`, `$navkeys`, fetch `$submission`, headers, dashboard render.

- [ ] **Step 12.2 : Ajouter `$groupid` et `$tab` après `$PAGE->set_url`**

Localiser le bloc `$PAGE->set_url(...)` (ligne ~28) et ajouter juste après :

```php
$groupid = optional_param('groupid', 0, PARAM_INT);
$tab = optional_param('tab', 'detail', PARAM_ALPHA);
if ($tab !== 'overview') {
    $tab = 'detail';
}

$availablegroups = redaction_get_grading_filter_groups($cm, $course->id);
if ($groupid > 0 && !isset($availablegroups[$groupid])) {
    $groupid = 0;
}
```

- [ ] **Step 12.3 : Filtrer `$navitems` par groupe (mode individuel uniquement)**

Localiser le foreach après `$users = get_enrolled_users(...)` (ligne ~50). Modifier l'appel pour passer `$groupid` :

Avant :
```php
    $coursecontext = context_course::instance($course->id);
    $users = get_enrolled_users($coursecontext, 'mod/redaction:submit', 0, 'u.*', 'u.lastname, u.firstname');
```

Après :
```php
    $coursecontext = context_course::instance($course->id);
    $users = get_enrolled_users($coursecontext, 'mod/redaction:submit', $groupid, 'u.*', 'u.lastname, u.firstname');
```

Et pour le mode groupe (ligne ~40), filtrer aussi :

Avant :
```php
if ($isGroupSubmission) {
    $groups = groups_get_all_groups($course->id);
    foreach ($groups as $group) {
```

Après :
```php
if ($isGroupSubmission) {
    if ($groupid > 0) {
        $groups = [$DB->get_record('groups', ['id' => $groupid], '*', MUST_EXIST)];
    } else {
        $groups = groups_get_all_groups($course->id);
    }
    foreach ($groups as $group) {
```

- [ ] **Step 12.4 : Render filter dropdown + tabs juste après le bouton « Retour à l'accueil »**

Localiser le bloc qui produit le bouton « Retour à l'accueil » (`html_writer::link($homeurl, ...)`, vers ligne ~163). Juste après ce bloc, insérer :

```php
// Group filter dropdown.
$baseurl = (new moodle_url('/mod/redaction/view.php'))->out(false);
$filterdata = [
    'cmid' => $cm->id,
    'currenttab' => $tab,
    'currentgroupid' => $groupid,
    'baseurl' => $baseurl,
    'has_groups' => !empty($availablegroups),
    'groups' => array_values(array_map(function($g) use ($groupid) {
        return [
            'id' => $g->id,
            'name' => format_string($g->name),
            'selected' => ($g->id == $groupid),
        ];
    }, $availablegroups)),
];
echo $OUTPUT->render_from_template('mod_redaction/grading_group_filter', $filterdata);

// Tabs.
$detailurl = new moodle_url('/mod/redaction/view.php', [
    'id' => $cm->id, 'page' => 'grading', 'tab' => 'detail', 'groupid' => $groupid,
]);
$overviewurl = new moodle_url('/mod/redaction/view.php', [
    'id' => $cm->id, 'page' => 'grading', 'tab' => 'overview', 'groupid' => $groupid,
]);
echo $OUTPUT->render_from_template('mod_redaction/grading_navtabs', [
    'detail_url' => $detailurl->out(false),
    'overview_url' => $overviewurl->out(false),
    'is_detail' => ($tab === 'detail'),
    'is_overview' => ($tab === 'overview'),
]);
```

- [ ] **Step 12.5 : Propager `$groupid` au dashboard**

Localiser l'appel à `redaction_render_teacher_dashboard($cm, $redaction)` (vers ligne 179). Le remplacer par :

```php
    echo redaction_render_teacher_dashboard($cm, $redaction, $groupid);
```

- [ ] **Step 12.6 : Brancher la vue tableau quand `$tab === 'overview'`**

Localiser la fin du bloc dashboard (juste après la fermeture de `if ($showDashboard) { ... }`). Avant la suite de l'écran (le bloc `// Build navigation data.` et la suite), insérer :

```php
// Branch: progression overview.
if ($tab === 'overview') {
    require_once(__DIR__ . '/pages/grading_overview.php');
    echo $OUTPUT->footer();
    exit;
}
```

- [ ] **Step 12.7 : Inclure groupid dans toutes les URLs `prevurl`/`nexturl`/`navitems[].url`**

Localiser le bloc `$navdata = [...]` (vers ligne 195). Dans les `new moodle_url(...)`, ajouter `'groupid' => $groupid` à chaque jeu de paramètres :

Avant :
```php
'prevurl' => ($previd !== null) ? (new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'grading', 'itemid' => $previd, 'dashboard' => $showDashboard]))->out(false) : '',
```

Après :
```php
'prevurl' => ($previd !== null) ? (new moodle_url('/mod/redaction/view.php', ['id' => $cm->id, 'page' => 'grading', 'itemid' => $previd, 'dashboard' => $showDashboard, 'groupid' => $groupid]))->out(false) : '',
```

Idem pour `nexturl` et pour le `foreach ($navitems as $item)` qui construit `'url'` — partout où `moodle_url` est créée pour la page grading, ajouter `'groupid' => $groupid`.

- [ ] **Step 12.8 : Vérification syntaxe**

```bash
/usr/local/Cellar/php/8.4.4/bin/php -l redaction/grading.php
```

- [ ] **Step 12.9 : Commit**

```bash
git add redaction/grading.php
git commit -m "feat(grading): tab routing + group filter + filtered dashboard"
```

---

### Task 13 : Strings EN + FR

**Files:**
- Modify: `redaction/lang/en/redaction.php`
- Modify: `redaction/lang/fr/redaction.php`

- [ ] **Step 13.1 : Ajouter les chaînes EN**

Dans `redaction/lang/en/redaction.php`, ajouter (à la fin ou dans la zone alphabétique appropriée) :

```php
$string['tab_grading_detail'] = 'Detailed view';
$string['tab_grading_overview'] = 'Progression table';
$string['group_filter_label'] = 'Filter by group';
$string['group_filter_all'] = 'All students';
$string['overview_student_col'] = 'Student';
$string['overview_attempt_header'] = 'Attempt {$a}';
$string['overview_no_attempt'] = 'No attempt';
$string['overview_pending'] = 'Pending';
$string['overview_failed'] = 'Failed';
$string['overview_no_data'] = 'No submissions to display for this group.';
```

- [ ] **Step 13.2 : Ajouter les chaînes FR**

Dans `redaction/lang/fr/redaction.php`, ajouter aux mêmes endroits :

```php
$string['tab_grading_detail'] = 'Vue détaillée';
$string['tab_grading_overview'] = 'Tableau de progression';
$string['group_filter_label'] = 'Filtrer par groupe';
$string['group_filter_all'] = 'Tous les élèves';
$string['overview_student_col'] = 'Élève';
$string['overview_attempt_header'] = 'Tentative {$a}';
$string['overview_no_attempt'] = 'Pas de tentative';
$string['overview_pending'] = 'En cours';
$string['overview_failed'] = 'Échec';
$string['overview_no_data'] = 'Aucune soumission à afficher pour ce groupe.';
```

- [ ] **Step 13.3 : Vérification**

```bash
/usr/local/Cellar/php/8.4.4/bin/php -l redaction/lang/en/redaction.php
/usr/local/Cellar/php/8.4.4/bin/php -l redaction/lang/fr/redaction.php
```

- [ ] **Step 13.4 : Commit**

```bash
git add redaction/lang/en/redaction.php redaction/lang/fr/redaction.php
git commit -m "lang: add overview/group filter strings (en + fr)"
```

---

### Task 14 : Styles CSS

**Files:**
- Modify: `redaction/styles.css`

- [ ] **Step 14.1 : Ajouter le bloc CSS**

Ajouter à la fin de `redaction/styles.css` :

```css
/* === Grading progression overview === */
.mod_redaction-overview-wrapper {
    overflow-x: auto;
    margin-top: 12px;
}
.mod_redaction-overview-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}
.mod_redaction-overview-table th,
.mod_redaction-overview-table td {
    padding: 8px;
    vertical-align: middle;
    text-align: center;
}
.mod_redaction-overview-table th[data-sort] {
    user-select: none;
}
.mod_redaction-overview-name-col,
.mod_redaction-overview-name {
    text-align: left;
    font-weight: 500;
    min-width: 180px;
}

.mod_redaction-overview-cell {
    position: relative;
    min-width: 90px;
}
.mod_redaction-overview-cell-empty {
    color: #aaa;
    background: #f7f7f7;
}
.mod_redaction-overview-cell-excellent { background: #c8e6c9; }
.mod_redaction-overview-cell-good      { background: #dcedc8; }
.mod_redaction-overview-cell-medium    { background: #fff3cd; }
.mod_redaction-overview-cell-low       { background: #ffcdd2; }

.mod_redaction-overview-cell-latest {
    box-shadow: inset 0 0 0 2px #1976d2;
}

.mod_redaction-overview-link {
    color: inherit;
    text-decoration: none;
    display: block;
}
.mod_redaction-overview-link:hover {
    text-decoration: underline;
}
.mod_redaction-overview-grade {
    font-weight: 600;
    margin-right: 4px;
}
.mod_redaction-overview-status {
    font-size: 12px;
}

.mod_redaction-overview-minibars {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin-top: 6px;
}
.mod_redaction-overview-minibar {
    height: 4px;
    border-radius: 2px;
    min-width: 8px;
}
.mod_redaction-overview-minibar-excellent { background: #4caf50; }
.mod_redaction-overview-minibar-good      { background: #8bc34a; }
.mod_redaction-overview-minibar-medium    { background: #ffc107; }
.mod_redaction-overview-minibar-low       { background: #f44336; }

/* === Group filter === */
.mod_redaction-group-filter {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.mod_redaction-group-filter label {
    margin: 0;
    font-weight: 500;
}
.mod_redaction-group-filter select {
    width: auto;
    min-width: 200px;
}
```

- [ ] **Step 14.2 : Commit**

```bash
git add redaction/styles.css
git commit -m "style(overview): add overview-table and group-filter styles"
```

---

### Task 15 : Smoke test preprod + déploiement prod

**Files:**
- Aucun fichier modifié — déploiement.

- [ ] **Step 15.1 : Déployer sur preprod**

```bash
sshpass -p 'ShA8-Fj5X-NPq@' rsync -avz --delete --exclude='.git*' --exclude='*.bak.*' \
    redaction/ \
    favi5410@favi5410.odns.fr:~/preprod.ent-occitanie.com/public/mod/redaction/

sshpass -p 'ShA8-Fj5X-NPq@' ssh favi5410@favi5410.odns.fr \
    "cd ~/preprod.ent-occitanie.com && /opt/alt/php82/usr/bin/php admin/cli/upgrade.php --non-interactive"

sshpass -p 'ShA8-Fj5X-NPq@' ssh favi5410@favi5410.odns.fr \
    "cd ~/preprod.ent-occitanie.com && /opt/alt/php82/usr/bin/php admin/cli/purge_caches.php"

sshpass -p 'ShA8-Fj5X-NPq@' ssh favi5410@favi5410.odns.fr \
    "echo '<?php opcache_reset();' > ~/preprod.ent-occitanie.com/public/opcache_reset.php && \
     curl -s https://preprod.ent-occitanie.com/opcache_reset.php > /dev/null && \
     rm ~/preprod.ent-occitanie.com/public/opcache_reset.php"
```

- [ ] **Step 15.2 : Vérification BDD preprod**

```bash
sshpass -p 'ShA8-Fj5X-NPq@' ssh favi5410@favi5410.odns.fr "cat > /tmp/p.php <<'PHPEOF'
<?php
define('CLI_SCRIPT', true);
require(\$_SERVER['HOME'].'/preprod.ent-occitanie.com/public/config.php');
global \$DB;
echo 'plugin version: ' . get_config('mod_redaction', 'version') . \"\n\";
\$dbman = \$DB->get_manager();
\$tbl = new xmldb_table('redaction_ai_summaries');
\$fld = new xmldb_field('groupid');
echo 'redaction_ai_summaries.groupid exists: ' . (\$dbman->field_exists(\$tbl, \$fld) ? 'YES' : 'NO') . \"\n\";
PHPEOF
/opt/alt/php82/usr/bin/php /tmp/p.php; rm /tmp/p.php"
```
Expected : version `2026050602`, groupid YES.

- [ ] **Step 15.3 : Scénario manuel preprod**

Compte enseignant `prof / Prof@Preprod2026` sur le cours TEST (id 2) :
1. Activer le mode entraînement sur l'activité, créer un grouping de 2 groupes A et B avec quelques élèves dans chacun.
2. Inviter 2-3 élèves à soumettre quelques tentatives dans chaque groupe.
3. Aller sur grading. Vérifier :
   - Onglets « Vue détaillée » et « Tableau de progression » visibles.
   - Dropdown « Filtrer par groupe » contient « Tous les élèves » + Groupe A + Groupe B (les 2 du grouping).
   - Sélectionner Groupe A → la liste de gauche se restreint, le dashboard du haut adapte ses chiffres au groupe A (Soumises/Notées).
   - Synthèse IA : affiche message « Aucune synthèse disponible » initialement (aucun cache pour le couple redactionid+groupA), génère via le bouton « Actualiser » (sync). Recharger : la synthèse cachée apparaît instantanément.
   - Onglet « Tableau de progression » avec Groupe A → matrice des élèves de A × tentatives, dernière colonne avec mini-bars.
   - Click sur une cellule → ramène à la vue détaillée de cette tentative.
   - Repasser à « Tous les élèves » → vue globale restaurée.

- [ ] **Step 15.4 : Backup + déploiement prod**

```bash
sshpass -p 'ShA8-Fj5X-NPq@' ssh favi5410@favi5410.odns.fr \
    "tar -czf ~/redaction-prod-backup-2.2.0-\$(date +%Y%m%d-%H%M%S).tar.gz \
       -C ~/ent-occitanie.com/moodle/mod redaction"

sshpass -p 'ShA8-Fj5X-NPq@' rsync -avz --delete --exclude='.git*' --exclude='*.bak.*' \
    redaction/ \
    favi5410@favi5410.odns.fr:~/ent-occitanie.com/moodle/mod/redaction/

sshpass -p 'ShA8-Fj5X-NPq@' ssh favi5410@favi5410.odns.fr \
    "cd ~/ent-occitanie.com/moodle && /opt/alt/php82/usr/bin/php admin/cli/upgrade.php --non-interactive"

sshpass -p 'ShA8-Fj5X-NPq@' ssh favi5410@favi5410.odns.fr \
    "cd ~/ent-occitanie.com/moodle && /opt/alt/php82/usr/bin/php admin/cli/purge_caches.php"

sshpass -p 'ShA8-Fj5X-NPq@' ssh favi5410@favi5410.odns.fr \
    "echo '<?php opcache_reset();' > ~/ent-occitanie.com/moodle/opcache_reset.php && \
     curl -s https://ent-occitanie.com/moodle/opcache_reset.php > /dev/null && \
     rm ~/ent-occitanie.com/moodle/opcache_reset.php"
```

- [ ] **Step 15.5 : Vérification prod**

```bash
sshpass -p 'ShA8-Fj5X-NPq@' ssh favi5410@favi5410.odns.fr "cat > /tmp/p.php <<'PHPEOF'
<?php
define('CLI_SCRIPT', true);
require(\$_SERVER['HOME'].'/ent-occitanie.com/moodle/config.php');
global \$DB;
echo 'plugin version: ' . get_config('mod_redaction', 'version') . \"\n\";
\$dbman = \$DB->get_manager();
\$tbl = new xmldb_table('redaction_ai_summaries');
\$fld = new xmldb_field('groupid');
echo 'redaction_ai_summaries.groupid exists: ' . (\$dbman->field_exists(\$tbl, \$fld) ? 'YES' : 'NO') . \"\n\";
echo 'Total summaries: ' . \$DB->count_records('redaction_ai_summaries') . \"\n\";
PHPEOF
/opt/alt/php82/usr/bin/php /tmp/p.php; rm /tmp/p.php"
```
Expected : version `2026050602`, groupid YES, summaries préservées.

- [ ] **Step 15.6 : Merge feature → main + tag**

```bash
git checkout main
git merge --no-ff feat/grading-overview -m "feat: progression overview + group filter v2.2.0"
git tag v2.2.0
git push origin main
git push origin v2.2.0
git push github main
git push github v2.2.0
```

---

## Self-Review effectué

**Couverture spec :**
- Section 1 (filtre groupe + tabs) → Tasks 7, 12, 13
- Section 2 (cellules table) → Tasks 8, 9, 14
- Section 3 (dashboard adapté) → Tasks 4, 5, 6
- Section 4 (architecture) → toutes les tâches

**Cohérence types :** signatures `submission_stats(int $redactionid, int $groupid = 0)` et `ai_summary_generator(int $redactionid, int $groupid = 0)` cohérentes avec lib.php → grading.php → grading_overview.php.

**Pas de placeholder** détecté.

**Lints PHP** prévus à chaque task, smoke test manuel à Task 15.
