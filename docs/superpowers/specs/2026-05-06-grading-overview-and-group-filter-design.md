# Vue tableau de progression + filtre groupe

**Date** : 2026-05-06
**Plugin** : `mod_redaction`
**Auteur** : Emmanuel REMY (via Claude)
**Version cible** : `2026050602` / release `2.2.0`

## Contexte

La page de notation actuelle (`grading.php`) impose une vue **élève par élève** : l'enseignant clique successivement dans la liste de gauche, ouvre une soumission, voit ses tentatives chronologiques, passe à la suivante. Pour une classe de 25-30 élèves chacun pouvant faire jusqu'à 5 tentatives, cette navigation séquentielle masque les progressions individuelles et empêche une vue d'ensemble.

Par ailleurs, l'enseignant ne peut pas filtrer les soumissions par groupe. Sur un cours regroupant plusieurs classes via un grouping, il voit l'intégralité du cohort sans moyen de se concentrer sur ses 4ème B uniquement.

## Objectifs

1. Permettre à l'enseignant de **filtrer la page grading par groupe** (issus du grouping de l'activité), et que le dashboard du haut s'adapte au groupe sélectionné.
2. Ajouter une **vue « Tableau de progression »** : matrice élève × tentative, pour visualiser d'un coup d'œil la progression de toute la classe (ou d'un groupe).
3. Conserver le dashboard global filtré au-dessus de chacune des deux vues.

## Décisions de design

Validées en brainstorming :

| Sujet | Décision |
|-------|----------|
| Source des groupes du dropdown | A — Groupes du grouping de l'activité, ou tous les groupes du cours en fallback |
| Cellule du tableau (tentatives 1..N-1) | B — Note + couleur niveau + statut + clic ouvre l'éval détaillée |
| Cellule de la dernière tentative | C — Idem B + mini-bar de critères |
| Navigation entre vues | A — Onglets « Vue détaillée » / « Tableau de progression » |
| Format colonnes du tableau | A — Fixes à `max_attempts` (par défaut 5), cellules vides alignées |
| Synthèse IA filtrée par groupe | B — Une synthèse cachée par couple (redactionid, groupid) |

## Modèle de données

### Évolution de schéma

`redaction_ai_summaries` :
- Ajout `groupid INT NOTNULL DEFAULT 0` (0 = synthèse globale, !=0 = synthèse pour ce groupe)
- Suppression de la clé unique existante sur `redactionid`
- Ajout d'une **clé unique composite** `(redactionid, groupid)`

### Migration `db/upgrade.php`

```php
if ($oldversion < 2026050602) {
    $table = new xmldb_table('redaction_ai_summaries');

    // Add groupid field if not present.
    $field = new xmldb_field('groupid', XMLDB_TYPE_INTEGER, '10', null,
        XMLDB_NOTNULL, null, '0', 'redactionid');
    if (!$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
    }

    // Drop the old single-column unique key on redactionid (if it exists).
    $oldkey = new xmldb_key('redactionid', XMLDB_KEY_UNIQUE, ['redactionid']);
    if ($dbman->find_key_name($table, $oldkey)) {
        $dbman->drop_key($table, $oldkey);
    }

    // Add the new composite unique key.
    $newkey = new xmldb_key('redactionid_groupid', XMLDB_KEY_UNIQUE,
        ['redactionid', 'groupid']);
    if (!$dbman->find_key_name($table, $newkey)) {
        $dbman->add_key($table, $newkey);
    }

    upgrade_mod_savepoint(true, 2026050602, 'redaction');
}
```

Les synthèses existantes (générées sans groupe) deviennent les synthèses globales (`groupid=0`) — pas de perte de donnée.

## Architecture

### Helpers `lib.php`

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
 * Returns the userids belonging to a group, restricted to those with
 * the redaction:submit capability in the course.
 *
 * @param int $courseid
 * @param int $groupid 0 means no filter (all enrolled with submit capability)
 * @return int[] array of user IDs
 */
function redaction_get_filtered_userids(int $courseid, int $groupid): array {
    $context = context_course::instance($courseid);
    $users = get_enrolled_users($context, 'mod/redaction:submit', $groupid, 'u.id');
    return array_keys($users);
}
```

### `submission_stats` étendu

Constructeur accepte un paramètre `int $groupid = 0`. Toutes les requêtes existantes ajoutent un filtre `userid IN (...)` quand `$groupid > 0` :

```php
public function __construct(int $redactionid, int $groupid = 0) {
    global $DB;
    $this->redactionid = $redactionid;
    $this->groupid = $groupid;
    $this->redaction = $DB->get_record('redaction', ['id' => $redactionid], '*', MUST_EXIST);
    $this->courseid = $this->redaction->course;
    $this->userids = $groupid > 0
        ? redaction_get_filtered_userids($this->courseid, $groupid)
        : null; // null = no filter
}
```

Les méthodes `count_by_status`, `get_grade_stats`, `get_ai_evaluation_stats` et `get_expected_submission_count` ajoutent une clause `userid IN (?)` si `$this->userids !== null` (sql_in_param Moodle helper).

Cache : clé `stats_<redactionid>_<groupid>`. Le 0 reste `stats_<redactionid>_0`.

### `ai_summary_generator` étendu

Constructeur accepte `int $groupid = 0`. Les méthodes `get_summary`, `save_summary`, `invalidate_cache` filtrent par `groupid` dans `redaction_ai_summaries`. Pour l'évaluations à analyser, `get_completed_evaluations` filtre par `userid IN (...)` quand groupid > 0.

### `token_stats`

**Non modifié.** Les coûts API sont consultés à l'échelle de l'instance, pas du groupe. Le tableau de bord affiche `token_stats` sans filtre groupe.

### Routing dans `grading.php`

```php
$groupid = optional_param('groupid', 0, PARAM_INT);
$tab = optional_param('tab', 'detail', PARAM_ALPHA);

// Validate groupid: must be 0 or in the activity's filter scope.
$availablegroups = redaction_get_grading_filter_groups($cm, $course->id);
if ($groupid > 0 && !isset($availablegroups[$groupid])) {
    $groupid = 0;
}

// Filter the existing $navitems list by group membership.
if ($groupid > 0) {
    $allowedids = redaction_get_filtered_userids($course->id, $groupid);
    $navitems = array_intersect_key($navitems, array_flip($allowedids));
}

// Group filter dropdown + tabs (always visible).
echo $renderer->render_grading_group_filter([
    'groups' => $availablegroups,
    'currentgroupid' => $groupid,
    'cmid' => $cm->id,
    'currenttab' => $tab,
]);
echo $renderer->render_grading_tabs([
    'cmid' => $cm->id,
    'groupid' => $groupid,
    'currenttab' => $tab,
]);

// Dashboard with group filter applied.
if ($showDashboard) {
    echo redaction_render_teacher_dashboard($cm, $redaction, $groupid);
}

// Branch by tab.
if ($tab === 'overview') {
    require_once(__DIR__ . '/pages/grading_overview.php');
    echo $OUTPUT->footer();
    exit;
}
// ... existing detail view continues here ...
```

### Nouvelle page `pages/grading_overview.php`

```php
defined('MOODLE_INTERNAL') || die();

// Build the matrix data: students × attempts.
$overviewdata = new \mod_redaction\output\grading_overview_data(
    $redaction->id,
    $course->id,
    $groupid,
    redaction_effective_max_attempts($redaction)
);
echo $renderer->render_from_template(
    'mod_redaction/grading_overview',
    $overviewdata->export_for_template($OUTPUT)
);

$PAGE->requires->js_call_amd('mod_redaction/grading_overview', 'init', [[
    'cmid' => $cm->id,
]]);
```

### Renderable `classes/output/grading_overview_data.php`

```php
class grading_overview_data implements \renderable, \templatable {
    public function __construct(
        protected int $redactionid,
        protected int $courseid,
        protected int $groupid,
        protected int $maxattempts
    ) {}

    public function export_for_template(\renderer_base $output): array {
        global $DB;

        // 1. Determine row identity (student vs group).
        $redaction = $DB->get_record('redaction', ['id' => $this->redactionid], '*', MUST_EXIST);
        $isgroupmode = (bool) $redaction->group_submission;

        // 2. Build rows.
        if ($isgroupmode) {
            $rows = $this->build_group_rows();
        } else {
            $rows = $this->build_student_rows();
        }

        // 3. Build column headers (Tentative 1..N).
        $headers = [];
        for ($i = 1; $i <= $this->maxattempts; $i++) {
            $headers[] = ['label' => get_string('overview_attempt_header', 'redaction', $i)];
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'has_rows' => !empty($rows),
            'isgroupmode' => $isgroupmode,
        ];
    }

    // build_student_rows : 1 row per student in $groupid (or all if 0).
    // For each, fetch all redaction_ai_evaluations of their submission ordered by timecreated.
    // Map to N cells (1 per attempt slot). Empty cell if not enough attempts.
    // Last non-empty cell gets is_latest=true and embeds criteria mini-bars.
    // ...
}
```

### Template `templates/grading_overview.mustache`

```mustache
<table class="mod_redaction-overview-table">
    <thead>
        <tr>
            <th>{{#str}}overview_student_col, redaction{{/str}}</th>
            {{#headers}}
                <th>{{label}}</th>
            {{/headers}}
        </tr>
    </thead>
    <tbody>
        {{#rows}}
            <tr>
                <td>{{name}}</td>
                {{#cells}}
                    {{#hasattempt}}
                        <td class="cell-{{levelclass}} {{#is_latest}}cell-latest{{/is_latest}}">
                            <a href="{{detailurl}}" class="cell-link">
                                <span class="cell-grade">{{grade}}</span>
                                <span class="cell-status">{{statusicon}}</span>
                            </a>
                            {{#is_latest}}
                                <div class="cell-criteria-mini">
                                    {{#criteria}}
                                        <div class="mini-bar" data-level="{{levelclass}}"
                                             title="{{name}}: {{score}}/{{max}}"
                                             style="width: {{percentage}}%"></div>
                                    {{/criteria}}
                                </div>
                            {{/is_latest}}
                        </td>
                    {{/hasattempt}}
                    {{^hasattempt}}
                        <td class="cell-empty">—</td>
                    {{/hasattempt}}
                {{/cells}}
            </tr>
        {{/rows}}
    </tbody>
</table>

{{^has_rows}}
    <div class="alert alert-info">{{#str}}overview_no_data, redaction{{/str}}</div>
{{/has_rows}}
```

### AMD module `amd/src/grading_overview.js`

Module léger : pas d'AJAX, juste les interactions UI :
- Tooltips au survol des mini-bars de critères (Bootstrap natif)
- Tri colonne (sortable basique sur lastname / dernière note) — purement client-side, pas de query
- Click sur une cellule → navigue vers `view.php?id=X&page=grading&tab=detail&itemid=Y` (hyperlien direct, pas besoin de JS)

### Strings (en + fr)

À ajouter dans `lang/en/redaction.php` et `lang/fr/redaction.php` :

| Clé | EN | FR |
|-----|----|----|
| `tab_grading_detail` | Detailed view | Vue détaillée |
| `tab_grading_overview` | Progression table | Tableau de progression |
| `group_filter_label` | Filter by group | Filtrer par groupe |
| `group_filter_all` | All students | Tous les élèves |
| `overview_student_col` | Student | Élève |
| `overview_attempt_header` | Attempt {$a} | Tentative {$a} |
| `overview_no_attempt` | No attempt | Pas de tentative |
| `overview_pending` | Pending | En cours |
| `overview_failed` | Failed | Échec |
| `overview_no_data` | No submissions to display for this group. | Aucune soumission à afficher pour ce groupe. |

## Fichiers touchés

### Créés

- `redaction/pages/grading_overview.php`
- `redaction/templates/grading_overview.mustache`
- `redaction/templates/grading_navtabs.mustache`
- `redaction/templates/grading_group_filter.mustache`
- `redaction/classes/output/grading_overview_data.php`
- `redaction/amd/src/grading_overview.js`
- `redaction/amd/build/grading_overview.min.js`

### Modifiés

- `redaction/version.php` — bump 2026050602 / 2.2.0
- `redaction/db/install.xml` — `groupid` + composite unique key on `redaction_ai_summaries`
- `redaction/db/upgrade.php` — migration step
- `redaction/lib.php` — helpers `redaction_get_grading_filter_groups`, `redaction_get_filtered_userids`. `redaction_render_teacher_dashboard` accepte un 3e param `$groupid`.
- `redaction/classes/dashboard/submission_stats.php` — `__construct(int $redactionid, int $groupid = 0)` + filtres SQL
- `redaction/classes/dashboard/ai_summary_generator.php` — `__construct(int $redactionid, int $groupid = 0)` + clé composite
- `redaction/classes/external/generate_ai_summary.php` — accepter et propager un nouveau param `groupid`
- `redaction/grading.php` — routing `$tab` + `$groupid`, dropdown filtre, onglets, dashboard filtré
- `redaction/lang/en/redaction.php` + `redaction/lang/fr/redaction.php` — nouveaux strings
- `redaction/styles.css` — styles pour `.mod_redaction-overview-table`, `.cell-latest`, `.cell-criteria-mini`, `.mini-bar`

## Migration des données existantes

- Synthèses existantes : `groupid = 0` (par défaut DB), donc deviennent les « synthèses globales » — visibles quand le filtre est sur « Tous les élèves ». Pas d'action manuelle requise.
- Stats cache : invalidé automatiquement au prochain accès (TTL 5 min).
- Aucune migration de données utilisateur.

## Risques & non-objectifs

**Risques :**
- Performance pour grands cours : si le grouping a 50+ groupes, la génération de synthèse à la demande pour chaque groupe peut coûter cher en tokens. Mitigation : la génération synchrone n'a lieu qu'à la **première visite** par groupe (cache persistant).
- Si un élève change de groupe en cours de cycle, sa synthèse de l'ancien groupe reste cachée. Mitigation : bouton « Actualiser » remet à jour à la demande.

**Non-objectifs :**
- Pas d'export du tableau (CSV/PDF) dans cette itération — peut être ajouté en suivi si besoin.
- Pas de filtre multi-groupes (un seul groupe à la fois pour la simplicité UX).
- En mode `group_submission=true`, le filtre groupe reste actif : sans filtre, 1 ligne par groupe ; avec filtre, 1 seule ligne (le groupe sélectionné).
- Pas d'édition de note dans le tableau (consultation uniquement) — l'édition reste dans la vue détaillée.

## Critères de validation manuelle (preprod)

Sur preprod (`preprod.ent-occitanie.com`), avec un cours qui a un grouping de 2 groupes (A et B) :

1. Onglet « Vue détaillée » sans filtre → liste tous les élèves des deux groupes ✓
2. Sélectionner « Groupe A » → la liste de gauche ne montre que les élèves de A ✓
3. Le dashboard en haut affiche les stats du groupe A uniquement ✓
4. La synthèse IA se régénère pour le groupe A à la première visite (et reste cachée ensuite) ✓
5. Onglet « Tableau de progression » avec groupe A → matrice avec uniquement les élèves de A ✓
6. Cellules : note colorée + statut + click qui amène à la vue détaillée de cette tentative ✓
7. Dernière colonne avec note : mini-bars de critères au survol ✓
8. Reset filtre « Tous les élèves » → comportement initial restauré ✓
