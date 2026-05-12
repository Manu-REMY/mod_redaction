# Submission Date Overrides Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add user/group deadline overrides to `mod_redaction`, modeled after `mod_assign` overrides.

**Architecture:** New `redaction_overrides` table holding per-user or per-group deadline overrides for a redaction instance. Effective deadline is resolved via a central helper `redaction_get_effective_deadline()` with precedence user > group (lowest sortorder) > instance. Dedicated admin pages (`overrides.php`, `override_edit.php`, `override_delete.php`) under the activity admin menu, gated by the new `mod/redaction:manageoverrides` capability. Backup/restore, Privacy API, and Moodle events are extended.

**Tech Stack:** PHP 8.1, Moodle 4.5+, XMLDB, moodleform, Mustache templates, PHPUnit.

**Reference spec:** `docs/superpowers/specs/2026-05-12-overrides-dates-design.md`

---

## File Structure

### New files

| Path | Responsibility |
|---|---|
| `redaction/classes/observer.php` | `user_deleted` / `group_deleted` handlers (cascade cleanup) |
| `redaction/classes/form/override_form.php` | `moodleform` for create/edit |
| `redaction/classes/output/overrides_table.php` | Renderable for listing page |
| `redaction/classes/event/user_override_created.php` | Event class |
| `redaction/classes/event/user_override_updated.php` | Event class |
| `redaction/classes/event/user_override_deleted.php` | Event class |
| `redaction/classes/event/group_override_created.php` | Event class |
| `redaction/classes/event/group_override_updated.php` | Event class |
| `redaction/classes/event/group_override_deleted.php` | Event class |
| `redaction/pages/overrides.php` | Listing of overrides (mode=user or mode=group) |
| `redaction/pages/override_edit.php` | Create/edit form handler |
| `redaction/pages/override_delete.php` | Delete confirmation |
| `redaction/templates/overrides_table.mustache` | Listing template |
| `redaction/tests/lib_overrides_test.php` | PHPUnit for helpers + effective deadline |
| `redaction/tests/task_auto_submit_overrides_test.php` | PHPUnit for cron honouring overrides |
| `redaction/tests/backup_restore_overrides_test.php` | PHPUnit for backup/restore roundtrip |

### Modified files

| Path | Change |
|---|---|
| `redaction/db/install.xml` | Add `redaction_overrides` table |
| `redaction/db/upgrade.php` | Add migration block creating the new table |
| `redaction/db/access.php` | Add `mod/redaction:manageoverrides` capability |
| `redaction/db/events.php` | Register observer handlers |
| `redaction/version.php` | Bump to `2026051200` |
| `redaction/lib.php` | Add helpers + `redaction_get_effective_deadline()` + nav extension + cleanup in `redaction_delete_instance()` |
| `redaction/classes/task/auto_submit_deadline.php` | Refactor to honour overrides |
| `redaction/classes/privacy/provider.php` | Add `redaction_overrides` to metadata + contextlist + export + delete |
| `redaction/backup/moodle2/backup_redaction_stepslib.php` | Add overrides element |
| `redaction/backup/moodle2/restore_redaction_stepslib.php` | Add `process_redaction_override` |
| `redaction/lang/en/redaction.php` | Add ~30 strings (primary language file) |
| `redaction/lang/fr/redaction.php` | Add same keys in French |
| `redaction/styles.css` | Add `.mod_redaction-overrides-*` rules |
| `redaction/view.php` (or referenced template) | Display effective deadline for current student |
| `redaction/tests/generator/lib.php` | Add `create_override` helper for tests |

---

## Task 1: Schema + version bump

**Files:**
- Modify: `redaction/db/install.xml`
- Modify: `redaction/db/upgrade.php`
- Modify: `redaction/version.php`

- [ ] **Step 1: Add the new table in `install.xml`**

Insert this `<TABLE>` block in `redaction/db/install.xml` just **after** the existing `redaction_correction` table block (around line 104, after its closing `</TABLE>`), inside the `<TABLES>` element:

```xml
    <!-- Table: Per-user/group deadline overrides -->
    <TABLE NAME="redaction_overrides" COMMENT="Per-user/group deadline overrides">
      <FIELDS>
        <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
        <FIELD NAME="redactionid" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="false"/>
        <FIELD NAME="groupid" TYPE="int" LENGTH="10" NOTNULL="false" SEQUENCE="false" COMMENT="Mutually exclusive with userid"/>
        <FIELD NAME="userid" TYPE="int" LENGTH="10" NOTNULL="false" SEQUENCE="false" COMMENT="Mutually exclusive with groupid"/>
        <FIELD NAME="deadline_date" TYPE="int" LENGTH="10" NOTNULL="false" SEQUENCE="false" COMMENT="Override deadline timestamp; NULL = no override"/>
        <FIELD NAME="sortorder" TYPE="int" LENGTH="10" NOTNULL="false" SEQUENCE="false" COMMENT="Precedence between group overrides (lowest wins)"/>
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

- [ ] **Step 2: Add migration block in `upgrade.php`**

Append this block in `redaction/db/upgrade.php` just **before** the final `return true;` line:

```php
    if ($oldversion < 2026051200) {
        $table = new xmldb_table('redaction_overrides');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('redactionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('deadline_date', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('redactionid', XMLDB_KEY_FOREIGN, ['redactionid'], 'redaction', ['id']);
        $table->add_key('groupid', XMLDB_KEY_FOREIGN, ['groupid'], 'groups', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('redactionid_userid', XMLDB_INDEX_NOTUNIQUE, ['redactionid', 'userid']);
        $table->add_index('redactionid_groupid', XMLDB_INDEX_NOTUNIQUE, ['redactionid', 'groupid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2026051200, 'redaction');
    }
```

- [ ] **Step 3: Bump version**

Edit `redaction/version.php` line 28:

```php
$plugin->version = 2026051200;  // YYYYMMDDXX format
```

- [ ] **Step 4: Sanity-check the XML**

Run:
```bash
php -r 'simplexml_load_file("/Volumes/DONNEES/Claude code/mod_redaction/redaction/db/install.xml") || exit(1);' && echo OK
```
Expected: `OK`

- [ ] **Step 5: Commit**

```bash
cd "/Volumes/DONNEES/Claude code/mod_redaction"
git add redaction/db/install.xml redaction/db/upgrade.php redaction/version.php
git commit -m "feat(overrides): add redaction_overrides table + migration"
```

---

## Task 2: Capability + language strings

**Files:**
- Modify: `redaction/db/access.php`
- Modify: `redaction/lang/en/redaction.php`
- Modify: `redaction/lang/fr/redaction.php`

- [ ] **Step 1: Add capability**

Append this entry inside the `$capabilities` array in `redaction/db/access.php`, just before the closing `];`:

```php
    // Manage submission date overrides (users and groups).
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

- [ ] **Step 2: Append English strings**

Append at the end of `redaction/lang/en/redaction.php` (before the closing `?>` if present, otherwise at EOF). All strings used by the feature live here:

```php

// Overrides feature.
$string['redaction:manageoverrides'] = 'Manage submission date overrides';

$string['overrides'] = 'Overrides';
$string['useroverrides'] = 'User overrides';
$string['groupoverrides'] = 'Group overrides';
$string['overrides_for'] = 'Overrides for {$a}';
$string['overrides_none'] = 'No overrides have been added yet.';

$string['addoverride'] = 'Add override';
$string['adduseroverride'] = 'Add user override';
$string['addgroupoverride'] = 'Add group override';
$string['editoverride'] = 'Edit override';
$string['deleteoverride'] = 'Delete override';
$string['duplicateoverride'] = 'Duplicate override';

$string['overrideuser'] = 'Override user';
$string['overridegroup'] = 'Override group';
$string['overridedeadline'] = 'Override deadline';
$string['overridedeadline_help'] = 'Deadline that replaces the instance deadline for the targeted user or group. Leave unchecked to remove the override (use the form deletion instead).';
$string['overridesortorder'] = 'Override priority';
$string['overridesortorder_help'] = 'When a user belongs to several groups with conflicting overrides, the override with the lowest priority value applies.';

$string['override_user_in_group_mode_warning'] = 'This activity is configured for group submissions. User overrides will be ignored at enforcement time; create a group override instead.';
$string['override_no_deadline'] = 'You must set an override deadline.';
$string['override_duplicate_user'] = 'An override already exists for this user.';
$string['override_confirm_delete_user'] = 'Are you sure you want to delete the override for user {$a}?';
$string['override_confirm_delete_group'] = 'Are you sure you want to delete the override for group {$a}?';

$string['overrides_table_target'] = 'Target';
$string['overrides_table_deadline'] = 'Override deadline';
$string['overrides_table_original'] = 'Original deadline';
$string['overrides_table_actions'] = 'Actions';
$string['overrides_table_sortorder'] = 'Priority';

$string['override_created'] = 'Override created.';
$string['override_updated'] = 'Override updated.';
$string['override_deleted'] = 'Override deleted.';

$string['event_user_override_created'] = 'User override created';
$string['event_user_override_updated'] = 'User override updated';
$string['event_user_override_deleted'] = 'User override deleted';
$string['event_group_override_created'] = 'Group override created';
$string['event_group_override_updated'] = 'Group override updated';
$string['event_group_override_deleted'] = 'Group override deleted';

$string['privacy:metadata:redaction_overrides'] = 'Information about per-user submission deadline overrides.';
$string['privacy:metadata:redaction_overrides:userid'] = 'The user the override applies to.';
$string['privacy:metadata:redaction_overrides:deadline_date'] = 'The overridden deadline timestamp.';
$string['privacy:metadata:redaction_overrides:timecreated'] = 'The time the override was created.';
$string['privacy:metadata:redaction_overrides:timemodified'] = 'The time the override was last modified.';
```

- [ ] **Step 3: Append French translations**

Append at the end of `redaction/lang/fr/redaction.php`:

```php

// Dérogations (overrides).
$string['redaction:manageoverrides'] = 'Gérer les dérogations de dates de soumission';

$string['overrides'] = 'Dérogations';
$string['useroverrides'] = 'Dérogations utilisateur';
$string['groupoverrides'] = 'Dérogations de groupe';
$string['overrides_for'] = 'Dérogations pour {$a}';
$string['overrides_none'] = 'Aucune dérogation n\'a été créée.';

$string['addoverride'] = 'Ajouter une dérogation';
$string['adduseroverride'] = 'Ajouter une dérogation utilisateur';
$string['addgroupoverride'] = 'Ajouter une dérogation de groupe';
$string['editoverride'] = 'Modifier la dérogation';
$string['deleteoverride'] = 'Supprimer la dérogation';
$string['duplicateoverride'] = 'Dupliquer la dérogation';

$string['overrideuser'] = 'Utilisateur concerné';
$string['overridegroup'] = 'Groupe concerné';
$string['overridedeadline'] = 'Deadline dérogatoire';
$string['overridedeadline_help'] = 'Deadline qui remplace celle de l\'instance pour l\'utilisateur ou le groupe ciblé. Décochez pour ne pas définir de deadline ; pour supprimer une dérogation existante, utilisez l\'action « Supprimer ».';
$string['overridesortorder'] = 'Priorité de la dérogation';
$string['overridesortorder_help'] = 'Si un élève appartient à plusieurs groupes ayant des dérogations conflictuelles, celle de plus petite priorité est retenue.';

$string['override_user_in_group_mode_warning'] = 'Cette activité est configurée en mode soumission par groupe. Les dérogations utilisateur seront ignorées lors de l\'application ; créez plutôt une dérogation de groupe.';
$string['override_no_deadline'] = 'Vous devez définir une deadline dérogatoire.';
$string['override_duplicate_user'] = 'Une dérogation existe déjà pour cet utilisateur.';
$string['override_confirm_delete_user'] = 'Confirmez-vous la suppression de la dérogation pour l\'utilisateur {$a} ?';
$string['override_confirm_delete_group'] = 'Confirmez-vous la suppression de la dérogation pour le groupe {$a} ?';

$string['overrides_table_target'] = 'Cible';
$string['overrides_table_deadline'] = 'Deadline dérogatoire';
$string['overrides_table_original'] = 'Deadline d\'origine';
$string['overrides_table_actions'] = 'Actions';
$string['overrides_table_sortorder'] = 'Priorité';

$string['override_created'] = 'Dérogation créée.';
$string['override_updated'] = 'Dérogation mise à jour.';
$string['override_deleted'] = 'Dérogation supprimée.';

$string['event_user_override_created'] = 'Dérogation utilisateur créée';
$string['event_user_override_updated'] = 'Dérogation utilisateur modifiée';
$string['event_user_override_deleted'] = 'Dérogation utilisateur supprimée';
$string['event_group_override_created'] = 'Dérogation groupe créée';
$string['event_group_override_updated'] = 'Dérogation groupe modifiée';
$string['event_group_override_deleted'] = 'Dérogation groupe supprimée';

$string['privacy:metadata:redaction_overrides'] = 'Informations sur les dérogations individuelles de deadline.';
$string['privacy:metadata:redaction_overrides:userid'] = 'L\'utilisateur concerné par la dérogation.';
$string['privacy:metadata:redaction_overrides:deadline_date'] = 'Timestamp de la deadline dérogatoire.';
$string['privacy:metadata:redaction_overrides:timecreated'] = 'Date de création de la dérogation.';
$string['privacy:metadata:redaction_overrides:timemodified'] = 'Date de dernière modification de la dérogation.';
```

- [ ] **Step 4: Lint PHP**

```bash
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/db/access.php"
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/lang/en/redaction.php"
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/lang/fr/redaction.php"
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 5: Commit**

```bash
git add redaction/db/access.php redaction/lang/en/redaction.php redaction/lang/fr/redaction.php
git commit -m "feat(overrides): add manageoverrides capability + i18n strings"
```

---

## Task 3: Test generator helper

**Files:**
- Modify: `redaction/tests/generator/lib.php`

This adds a helper to create overrides from PHPUnit tests. Subsequent test tasks rely on it.

- [ ] **Step 1: Open the file and inspect existing helpers**

Open `redaction/tests/generator/lib.php`. Find the class `mod_redaction_generator` (extends `testing_module_generator`). It already has methods like `create_instance()`. We add a new method.

- [ ] **Step 2: Add the `create_override` method**

Append this method inside the class body, before the closing `}`:

```php
    /**
     * Create an override record for a redaction.
     *
     * @param array|stdClass $record Required keys: redactionid, deadline_date. Plus either userid or groupid.
     * @return stdClass The inserted record with its id.
     */
    public function create_override($record) {
        global $DB;

        $record = (object) (array) $record;
        $record->userid = $record->userid ?? null;
        $record->groupid = $record->groupid ?? null;
        $record->sortorder = $record->sortorder ?? 0;
        $record->timecreated = $record->timecreated ?? time();
        $record->timemodified = $record->timemodified ?? time();

        if (empty($record->redactionid)) {
            throw new \coding_exception('create_override: redactionid is required');
        }
        if (empty($record->userid) && empty($record->groupid)) {
            throw new \coding_exception('create_override: userid or groupid is required');
        }

        $record->id = $DB->insert_record('redaction_overrides', $record);
        return $record;
    }
```

- [ ] **Step 3: Lint**

```bash
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/tests/generator/lib.php"
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add redaction/tests/generator/lib.php
git commit -m "test(overrides): add create_override generator helper"
```

---

## Task 4: Helper functions in `lib.php` (TDD)

**Files:**
- Create: `redaction/tests/lib_overrides_test.php`
- Modify: `redaction/lib.php`

This task introduces three small helpers and `redaction_get_effective_deadline()`. We write the tests first.

- [ ] **Step 1: Write the failing PHPUnit test**

Create `redaction/tests/lib_overrides_test.php` with this exact content:

```php
<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/redaction/lib.php');

/**
 * Tests for override helpers and effective deadline resolution.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::redaction_get_user_override
 * @covers     ::redaction_get_group_override
 * @covers     ::redaction_get_group_overrides_for_user
 * @covers     ::redaction_get_effective_deadline
 */
final class lib_overrides_test extends \advanced_testcase {

    /** @var \stdClass */
    private $course;
    /** @var \stdClass */
    private $redaction;
    /** @var \stdClass */
    private $correction;
    /** @var \stdClass */
    private $student;
    /** @var \stdClass */
    private $group;
    /** @var \mod_redaction_generator */
    private $gen;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        global $DB;

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $this->group->id,
            'userid' => $this->student->id,
        ]);

        $this->gen = $this->getDataGenerator()->get_plugin_generator('mod_redaction');
        $this->redaction = $this->gen->create_instance(['course' => $this->course->id]);

        // Set instance deadline to a known value (T = 1000).
        $this->correction = $DB->get_record('redaction_correction', ['redactionid' => $this->redaction->id]);
        if (!$this->correction) {
            redaction_create_correction($this->redaction->id);
            $this->correction = $DB->get_record('redaction_correction', ['redactionid' => $this->redaction->id]);
        }
        $DB->set_field('redaction_correction', 'deadline_date', 1000, ['id' => $this->correction->id]);
    }

    public function test_get_user_override_returns_null_when_absent(): void {
        $result = redaction_get_user_override($this->redaction->id, $this->student->id);
        $this->assertNull($result);
    }

    public function test_get_user_override_returns_record_when_present(): void {
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
            'deadline_date' => 2000,
        ]);
        $result = redaction_get_user_override($this->redaction->id, $this->student->id);
        $this->assertNotNull($result);
        $this->assertEquals(2000, (int) $result->deadline_date);
    }

    public function test_get_group_override_returns_record(): void {
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $this->group->id,
            'deadline_date' => 3000,
            'sortorder' => 5,
        ]);
        $result = redaction_get_group_override($this->redaction->id, $this->group->id);
        $this->assertNotNull($result);
        $this->assertEquals(3000, (int) $result->deadline_date);
    }

    public function test_group_overrides_for_user_sorted_by_priority(): void {
        $g2 = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $g2->id,
            'userid' => $this->student->id,
        ]);

        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $this->group->id,
            'deadline_date' => 3000,
            'sortorder' => 10,
        ]);
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $g2->id,
            'deadline_date' => 4000,
            'sortorder' => 1,
        ]);

        $rows = redaction_get_group_overrides_for_user($this->redaction->id, $this->student->id);
        $this->assertCount(2, $rows);
        $first = reset($rows);
        $this->assertEquals(1, (int) $first->sortorder);
        $this->assertEquals(4000, (int) $first->deadline_date);
    }

    public function test_effective_deadline_returns_instance_when_no_override(): void {
        $effective = redaction_get_effective_deadline($this->redaction, $this->student->id);
        $this->assertEquals(1000, $effective);
    }

    public function test_effective_deadline_user_override_wins(): void {
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
            'deadline_date' => 2000,
        ]);
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $this->group->id,
            'deadline_date' => 9999,
            'sortorder' => 1,
        ]);
        $effective = redaction_get_effective_deadline($this->redaction, $this->student->id);
        $this->assertEquals(2000, $effective);
    }

    public function test_effective_deadline_group_override_when_no_user_override(): void {
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $this->group->id,
            'deadline_date' => 5000,
            'sortorder' => 1,
        ]);
        $effective = redaction_get_effective_deadline($this->redaction, $this->student->id);
        $this->assertEquals(5000, $effective);
    }

    public function test_effective_deadline_group_lowest_sortorder_wins(): void {
        $g2 = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $g2->id,
            'userid' => $this->student->id,
        ]);

        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $this->group->id,
            'deadline_date' => 5000,
            'sortorder' => 10,
        ]);
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $g2->id,
            'deadline_date' => 6000,
            'sortorder' => 1,
        ]);

        $effective = redaction_get_effective_deadline($this->redaction, $this->student->id);
        $this->assertEquals(6000, $effective);
    }

    public function test_effective_deadline_null_override_is_ignored(): void {
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
            'deadline_date' => null,
        ]);
        $effective = redaction_get_effective_deadline($this->redaction, $this->student->id);
        $this->assertEquals(1000, $effective);
    }

    public function test_effective_deadline_for_group_draft(): void {
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $this->group->id,
            'deadline_date' => 7000,
            'sortorder' => 1,
        ]);
        $effective = redaction_get_effective_deadline($this->redaction, 0, $this->group->id);
        $this->assertEquals(7000, $effective);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd /path/to/moodle && vendor/bin/phpunit mod/redaction/tests/lib_overrides_test.php
```
(Use the Moodle PHPUnit setup. On the dev box: `php admin/tool/phpunit/cli/init.php` once if needed.)
Expected: tests fail with `Error: Call to undefined function redaction_get_user_override()` (or similar).

- [ ] **Step 3: Implement the helpers in `lib.php`**

Append these four functions at the end of `redaction/lib.php` (before any closing tag, or at EOF):

```php

/**
 * Return the user-specific override record for a redaction, if any.
 *
 * @param int $redactionid
 * @param int $userid
 * @return stdClass|null
 */
function redaction_get_user_override($redactionid, $userid) {
    global $DB;
    if (empty($userid)) {
        return null;
    }
    $record = $DB->get_record('redaction_overrides', [
        'redactionid' => $redactionid,
        'userid' => $userid,
    ]);
    return $record ?: null;
}

/**
 * Return the group-specific override for a given group, if any.
 *
 * @param int $redactionid
 * @param int $groupid
 * @return stdClass|null
 */
function redaction_get_group_override($redactionid, $groupid) {
    global $DB;
    if (empty($groupid)) {
        return null;
    }
    $record = $DB->get_record('redaction_overrides', [
        'redactionid' => $redactionid,
        'groupid' => $groupid,
    ]);
    return $record ?: null;
}

/**
 * Return all group overrides applicable to a user, ordered by sortorder asc.
 *
 * @param int $redactionid
 * @param int $userid
 * @return array<int,stdClass> Records keyed by id; lowest sortorder first.
 */
function redaction_get_group_overrides_for_user($redactionid, $userid) {
    global $DB;
    if (empty($userid)) {
        return [];
    }
    $sql = "SELECT o.*
              FROM {redaction_overrides} o
              JOIN {groups_members} gm ON gm.groupid = o.groupid AND gm.userid = :userid
             WHERE o.redactionid = :redactionid
               AND o.groupid IS NOT NULL
          ORDER BY COALESCE(o.sortorder, 0) ASC, o.id ASC";
    return $DB->get_records_sql($sql, [
        'userid' => $userid,
        'redactionid' => $redactionid,
    ]);
}

/**
 * Return the effective deadline for a user/group draft on a redaction instance.
 *
 * Precedence:
 *   1. User override (deadline_date NOT NULL) — short-circuit.
 *   2. Lowest-sortorder group override the user is member of.
 *   3. Instance deadline from redaction_correction.
 *
 * For a group draft (userid = 0, groupid > 0): step 1 is skipped.
 *
 * @param stdClass $redaction
 * @param int $userid 0 for group drafts
 * @param int $groupid 0 for individual drafts
 * @return int|null Timestamp; null if no deadline applies
 */
function redaction_get_effective_deadline($redaction, $userid, $groupid = 0) {
    global $DB;

    if (!empty($userid)) {
        $user = redaction_get_user_override($redaction->id, $userid);
        if ($user && !empty($user->deadline_date)) {
            return (int) $user->deadline_date;
        }

        $groupoverrides = redaction_get_group_overrides_for_user($redaction->id, $userid);
        foreach ($groupoverrides as $row) {
            if (!empty($row->deadline_date)) {
                return (int) $row->deadline_date;
            }
        }
    } else if (!empty($groupid)) {
        $g = redaction_get_group_override($redaction->id, $groupid);
        if ($g && !empty($g->deadline_date)) {
            return (int) $g->deadline_date;
        }
    }

    $correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
    if ($correction && !empty($correction->deadline_date)) {
        return (int) $correction->deadline_date;
    }
    return null;
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
vendor/bin/phpunit mod/redaction/tests/lib_overrides_test.php
```
Expected: all 9 tests pass.

- [ ] **Step 5: Commit**

```bash
git add redaction/lib.php redaction/tests/lib_overrides_test.php
git commit -m "feat(overrides): add deadline override helpers + tests"
```

---

## Task 5: Integrate enforcement into `redaction_can_submit_attempt`

**Files:**
- Modify: `redaction/lib.php` (around line 763)

- [ ] **Step 1: Add a regression test**

Append this test to `redaction/tests/lib_overrides_test.php` inside the class (before the closing `}`):

```php
    public function test_can_submit_blocked_when_effective_deadline_passed(): void {
        global $DB;
        // Enable training so submit attempt is even considered.
        $DB->set_field('redaction', 'training_enabled', 1, ['id' => $this->redaction->id]);
        $DB->set_field('redaction', 'ai_enabled', 1, ['id' => $this->redaction->id]);

        // Make instance deadline far in the future, but user override in the past.
        $DB->set_field('redaction_correction', 'deadline_date', time() + 86400, ['id' => $this->correction->id]);
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
            'deadline_date' => time() - 3600,
        ]);

        $submission = (object) [
            'id' => 0,
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
            'groupid' => 0,
            'status' => 0,
            'training_count' => 0,
        ];
        $redaction = $DB->get_record('redaction', ['id' => $this->redaction->id]);
        $correction = $DB->get_record('redaction_correction', ['redactionid' => $this->redaction->id]);

        $result = redaction_can_submit_attempt($redaction, $submission, $correction);
        $this->assertFalse($result['allowed']);
        $this->assertEquals('deadline_passed', $result['reason']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
vendor/bin/phpunit mod/redaction/tests/lib_overrides_test.php --filter test_can_submit_blocked_when_effective_deadline_passed
```
Expected: FAIL — the current implementation reads `$correction->deadline_date` directly, ignoring overrides.

- [ ] **Step 3: Patch `redaction_can_submit_attempt`**

In `redaction/lib.php`, find this block (around lines 762–765):

```php
    if ($correction && !empty($correction->deadline_date) && time() > $correction->deadline_date) {
        return ['allowed' => false, 'reason' => 'deadline_passed'];
    }
```

Replace it with:

```php
    $effectivedeadline = redaction_get_effective_deadline(
        $redaction,
        (int) ($submission->userid ?? 0),
        (int) ($submission->groupid ?? 0)
    );
    if (!empty($effectivedeadline) && time() > $effectivedeadline) {
        return ['allowed' => false, 'reason' => 'deadline_passed'];
    }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
vendor/bin/phpunit mod/redaction/tests/lib_overrides_test.php
```
Expected: all tests pass (including the new one).

- [ ] **Step 5: Commit**

```bash
git add redaction/lib.php redaction/tests/lib_overrides_test.php
git commit -m "feat(overrides): apply effective deadline in can_submit_attempt"
```

---

## Task 6: Refactor `auto_submit_deadline` cron

**Files:**
- Create: `redaction/tests/task_auto_submit_overrides_test.php`
- Modify: `redaction/classes/task/auto_submit_deadline.php`

- [ ] **Step 1: Write the failing test**

Create `redaction/tests/task_auto_submit_overrides_test.php`:

```php
<?php
namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/redaction/lib.php');

/**
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_redaction\task\auto_submit_deadline
 */
final class task_auto_submit_overrides_test extends \advanced_testcase {

    public function test_cron_auto_submits_draft_when_user_override_passed(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_redaction');
        $redaction = $gen->create_instance(['course' => $course->id]);

        // Instance deadline in the future.
        redaction_create_correction($redaction->id);
        $DB->set_field('redaction_correction', 'deadline_date', time() + 86400,
            ['redactionid' => $redaction->id]);

        // User override in the past.
        $gen->create_override([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'deadline_date' => time() - 3600,
        ]);

        // Draft submission for that user.
        $draftid = $DB->insert_record('redaction_submission', (object) [
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'groupid' => 0,
            'titre' => 'x',
            'contenu' => '<p>Some content</p>',
            'contenuformat' => 1,
            'status' => 0,
            'training_count' => 0,
            'timesubmitted' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $task = new \mod_redaction\task\auto_submit_deadline();
        ob_start();
        $task->execute();
        ob_end_clean();

        $row = $DB->get_record('redaction_submission', ['id' => $draftid]);
        $this->assertEquals(1, (int) $row->status, 'Draft should be auto-submitted');
    }

    public function test_cron_skips_draft_when_no_effective_deadline_yet(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_redaction');
        $redaction = $gen->create_instance(['course' => $course->id]);

        // Instance deadline in the past.
        redaction_create_correction($redaction->id);
        $DB->set_field('redaction_correction', 'deadline_date', time() - 3600,
            ['redactionid' => $redaction->id]);

        // User override in the future.
        $gen->create_override([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'deadline_date' => time() + 86400,
        ]);

        $draftid = $DB->insert_record('redaction_submission', (object) [
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'groupid' => 0,
            'titre' => 'x',
            'contenu' => '<p>Some content</p>',
            'contenuformat' => 1,
            'status' => 0,
            'training_count' => 0,
            'timesubmitted' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $task = new \mod_redaction\task\auto_submit_deadline();
        ob_start();
        $task->execute();
        ob_end_clean();

        $row = $DB->get_record('redaction_submission', ['id' => $draftid]);
        $this->assertEquals(0, (int) $row->status, 'Draft must remain a draft');
    }
}
```

- [ ] **Step 2: Run it to confirm failures**

```bash
vendor/bin/phpunit mod/redaction/tests/task_auto_submit_overrides_test.php
```
Expected: tests fail (current cron filters by instance deadline only).

- [ ] **Step 3: Refactor the cron**

Open `redaction/classes/task/auto_submit_deadline.php` and replace the `execute()` method (lines 46–138) with this version. The body that handles a single draft is kept; only the discovery query and per-draft deadline check changes.

```php
    /**
     * Execute the task.
     */
    public function execute() {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/redaction/lib.php');

        $now = time();
        mtrace('Starting auto-submit deadline task...');

        // Walk all open drafts; per-draft we compute the effective deadline (taking overrides into account).
        $sql = "SELECT s.id AS submissionid, s.userid, s.groupid, s.contenu,
                       r.id AS redactionid, r.name, r.course, r.group_submission, r.ai_enabled
                  FROM {redaction_submission} s
                  JOIN {redaction} r ON r.id = s.redactionid
                 WHERE s.status = 0";
        $drafts = $DB->get_recordset_sql($sql);

        $totalprocessed = 0;
        $totalerrors = 0;
        $totalevaluations = 0;
        $redactionsseen = [];

        foreach ($drafts as $draft) {
            $redaction = (object) [
                'id' => (int) $draft->redactionid,
                'name' => $draft->name,
                'course' => (int) $draft->course,
                'group_submission' => (int) $draft->group_submission,
                'ai_enabled' => (int) $draft->ai_enabled,
            ];

            $effective = redaction_get_effective_deadline(
                $redaction,
                (int) $draft->userid,
                (int) $draft->groupid
            );

            if (empty($effective) || $effective > $now) {
                continue;
            }

            // Skip empty drafts entirely: avoids ghost submissions in dashboard.
            if (empty(trim(strip_tags($draft->contenu ?? '')))) {
                $identifier = $draft->groupid ? "group {$draft->groupid}" : "user {$draft->userid}";
                mtrace("  Skipped empty draft for {$identifier} on redaction {$redaction->id}");
                continue;
            }

            try {
                $update = (object) [
                    'id' => (int) $draft->submissionid,
                    'status' => 1,
                    'timesubmitted' => $effective,
                    'timemodified' => $now,
                ];
                $DB->update_record('redaction_submission', $update);
                $identifier = $draft->groupid ? "group {$draft->groupid}" : "user {$draft->userid}";
                mtrace("  Auto-submitted {$identifier} on redaction {$redaction->id} (deadline " . date('Y-m-d H:i:s', $effective) . ")");

                if ($redaction->ai_enabled) {
                    $submission = $DB->get_record('redaction_submission', ['id' => (int) $draft->submissionid]);
                    $this->trigger_ai_evaluation($submission);
                    $totalevaluations++;
                }

                $totalprocessed++;
                $redactionsseen[$redaction->id] = $redaction;
            } catch (\Exception $e) {
                mtrace("  ERROR on draft {$draft->submissionid}: " . $e->getMessage());
                $totalerrors++;
            }
        }
        $drafts->close();

        // Catch-up evaluations for any submitted drafts that lack one.
        foreach ($redactionsseen as $r) {
            if (!empty($r->ai_enabled)) {
                $totalevaluations += $this->queue_missing_evaluations($r);
            }
        }

        mtrace("Auto-submit deadline task completed. Processed: {$totalprocessed}, AI evaluations queued: {$totalevaluations}, Errors: {$totalerrors}");
    }
```

- [ ] **Step 4: Run the tests**

```bash
vendor/bin/phpunit mod/redaction/tests/task_auto_submit_overrides_test.php
```
Expected: both pass.

```bash
vendor/bin/phpunit mod/redaction/tests/lib_overrides_test.php
```
Expected: still green.

- [ ] **Step 5: Commit**

```bash
git add redaction/classes/task/auto_submit_deadline.php redaction/tests/task_auto_submit_overrides_test.php
git commit -m "refactor(overrides): cron honours per-user/group effective deadline"
```

---

## Task 7: Show effective deadline to the student

**Files:**
- Modify: `redaction/view.php` (or a `pages/redaction.php` if the deadline is rendered there)

The current view passes `$correction->deadline_date` to the redaction page template. We replace that with the effective deadline for the current user.

- [ ] **Step 1: Find where the deadline is exposed to the template**

```bash
grep -n "deadline_date" /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/view.php /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/pages/*.php
```
Note the file path and the exact line that assigns `deadline_date` (or `$data->deadline_date`) for the student view.

- [ ] **Step 2: Replace direct read with the effective deadline**

For each `pages/*.php` file where the student view (not the grading/admin views) reads `$correction->deadline_date`, change the assignment so it uses `redaction_get_effective_deadline()`. Concretely, where you find:

```php
$data->deadline = $correction->deadline_date;
```

…replace with:

```php
$data->deadline = redaction_get_effective_deadline(
    $redaction,
    (int) $USER->id,
    (int) ($usergroup ?? 0)
);
```

If the variable name differs, keep the destination property name but use the helper on the right-hand side. Apply the same transformation in `view.php` if applicable. Do NOT touch teacher/admin views that show the instance-level deadline.

- [ ] **Step 3: Manual smoke check (deferred to Task 22)**

This will be exercised end-to-end in the final UI walkthrough. No automated test is added here because the substitution is mechanical.

- [ ] **Step 4: Lint**

```bash
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/view.php"
for f in /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/pages/*.php; do php -l "$f" || exit 1; done
```
Expected: all files pass linting.

- [ ] **Step 5: Commit**

```bash
git add redaction/view.php redaction/pages
git commit -m "feat(overrides): show effective deadline to students"
```

---

## Task 8: Override form class

**Files:**
- Create: `redaction/classes/form/override_form.php`

- [ ] **Step 1: Create the form**

```php
<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_redaction\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Form to create/edit a redaction date override.
 *
 * Custom data keys:
 *   - mode: 'user' or 'group'
 *   - cmid: course module id
 *   - redactionid: instance id
 *   - context: \context_module
 *   - existing: stdClass|null (the existing override when editing)
 *   - userlist: array<int,string> userid => fullname (mode=user)
 *   - grouplist: array<int,string> groupid => name (mode=group)
 *   - groupmodewarning: bool (mode=user and group_submission=1)
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class override_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $custom = $this->_customdata;
        $mode = $custom['mode'];

        $mform->addElement('hidden', 'cmid', $custom['cmid']);
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'mode', $mode);
        $mform->setType('mode', PARAM_ALPHA);
        $mform->addElement('hidden', 'overrideid', !empty($custom['existing']->id) ? $custom['existing']->id : 0);
        $mform->setType('overrideid', PARAM_INT);

        if ($mode === 'user') {
            if (!empty($custom['groupmodewarning'])) {
                $mform->addElement('static', 'groupmodewarn', '',
                    \html_writer::tag('div',
                        get_string('override_user_in_group_mode_warning', 'mod_redaction'),
                        ['class' => 'mod_redaction-overrides-warning alert alert-warning']
                    )
                );
            }
            $mform->addElement('select', 'userid', get_string('overrideuser', 'mod_redaction'), $custom['userlist']);
            $mform->setType('userid', PARAM_INT);
            $mform->addRule('userid', null, 'required', null, 'client');
        } else {
            $mform->addElement('select', 'groupid', get_string('overridegroup', 'mod_redaction'), $custom['grouplist']);
            $mform->setType('groupid', PARAM_INT);
            $mform->addRule('groupid', null, 'required', null, 'client');

            $mform->addElement('text', 'sortorder', get_string('overridesortorder', 'mod_redaction'), ['size' => 4]);
            $mform->setType('sortorder', PARAM_INT);
            $mform->setDefault('sortorder', 0);
            $mform->addHelpButton('sortorder', 'overridesortorder', 'mod_redaction');
        }

        $mform->addElement('date_time_selector', 'deadline_date',
            get_string('overridedeadline', 'mod_redaction'), ['optional' => true]);
        $mform->addHelpButton('deadline_date', 'overridedeadline', 'mod_redaction');

        $this->add_action_buttons(true,
            !empty($custom['existing']->id)
                ? get_string('savechanges')
                : get_string('addoverride', 'mod_redaction'));

        if (!empty($custom['existing'])) {
            $this->set_data((array) $custom['existing']);
        }
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        if (empty($data['deadline_date'])) {
            $errors['deadline_date'] = get_string('override_no_deadline', 'mod_redaction');
        }

        if ($data['mode'] === 'user' && empty($data['overrideid'])) {
            $exists = $DB->record_exists('redaction_overrides', [
                'redactionid' => (int) $this->_customdata['redactionid'],
                'userid' => (int) $data['userid'],
            ]);
            if ($exists) {
                $errors['userid'] = get_string('override_duplicate_user', 'mod_redaction');
            }
        }

        return $errors;
    }
}
```

- [ ] **Step 2: Lint**

```bash
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/classes/form/override_form.php"
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add redaction/classes/form/override_form.php
git commit -m "feat(overrides): add override moodleform"
```

---

## Task 9: Listing renderable + Mustache template

**Files:**
- Create: `redaction/classes/output/overrides_table.php`
- Create: `redaction/templates/overrides_table.mustache`

- [ ] **Step 1: Renderable class**

```php
<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_redaction\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Renderable for the overrides listing.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overrides_table implements \renderable, \templatable {

    /** @var string 'user' or 'group' */
    protected $mode;
    /** @var int Course module id */
    protected $cmid;
    /** @var array<int,stdClass> Override records joined with target label */
    protected $rows;
    /** @var int|null Instance deadline timestamp */
    protected $instancedeadline;

    public function __construct(string $mode, int $cmid, array $rows, ?int $instancedeadline) {
        $this->mode = $mode;
        $this->cmid = $cmid;
        $this->rows = $rows;
        $this->instancedeadline = $instancedeadline;
    }

    public function export_for_template(\renderer_base $output) {
        $data = (object) [
            'mode' => $this->mode,
            'cmid' => $this->cmid,
            'is_user_mode' => $this->mode === 'user',
            'is_group_mode' => $this->mode === 'group',
            'has_rows' => !empty($this->rows),
            'rows' => [],
            'instance_deadline' => $this->instancedeadline
                ? userdate($this->instancedeadline)
                : get_string('none'),
            'add_url' => (new \moodle_url('/mod/redaction/pages/override_edit.php', [
                'id' => $this->cmid,
                'mode' => $this->mode,
            ]))->out(false),
        ];

        foreach ($this->rows as $row) {
            $data->rows[] = (object) [
                'id' => (int) $row->id,
                'target' => $row->_target_label ?? '',
                'deadline' => !empty($row->deadline_date) ? userdate($row->deadline_date) : '',
                'sortorder' => (int) ($row->sortorder ?? 0),
                'edit_url' => (new \moodle_url('/mod/redaction/pages/override_edit.php', [
                    'id' => $this->cmid,
                    'overrideid' => (int) $row->id,
                ]))->out(false),
                'delete_url' => (new \moodle_url('/mod/redaction/pages/override_delete.php', [
                    'id' => $this->cmid,
                    'overrideid' => (int) $row->id,
                    'sesskey' => sesskey(),
                ]))->out(false),
            ];
        }

        return $data;
    }
}
```

- [ ] **Step 2: Mustache template**

Create `redaction/templates/overrides_table.mustache`:

```mustache
{{!
    Overrides listing template.
    Context vars:
        mode (string), cmid (int), is_user_mode (bool), is_group_mode (bool),
        has_rows (bool), rows (array), instance_deadline (string), add_url (string)
}}
<div class="mod_redaction-overrides">
    <p class="mod_redaction-overrides-instance">
        {{#str}}overrides_table_original, mod_redaction{{/str}} : <strong>{{instance_deadline}}</strong>
    </p>

    <p>
        <a class="btn btn-primary mod_redaction-overrides-add" href="{{add_url}}">
            {{#is_user_mode}}{{#str}}adduseroverride, mod_redaction{{/str}}{{/is_user_mode}}
            {{#is_group_mode}}{{#str}}addgroupoverride, mod_redaction{{/str}}{{/is_group_mode}}
        </a>
    </p>

    {{^has_rows}}
        <div class="mod_redaction-overrides-empty alert alert-info">
            {{#str}}overrides_none, mod_redaction{{/str}}
        </div>
    {{/has_rows}}

    {{#has_rows}}
        <table class="mod_redaction-overrides-table generaltable">
            <thead>
                <tr>
                    <th scope="col">{{#str}}overrides_table_target, mod_redaction{{/str}}</th>
                    <th scope="col">{{#str}}overrides_table_deadline, mod_redaction{{/str}}</th>
                    {{#is_group_mode}}
                        <th scope="col">{{#str}}overrides_table_sortorder, mod_redaction{{/str}}</th>
                    {{/is_group_mode}}
                    <th scope="col">{{#str}}overrides_table_actions, mod_redaction{{/str}}</th>
                </tr>
            </thead>
            <tbody>
                {{#rows}}
                    <tr>
                        <td>{{target}}</td>
                        <td>{{deadline}}</td>
                        {{#../is_group_mode}}
                            <td>{{sortorder}}</td>
                        {{/../is_group_mode}}
                        <td class="mod_redaction-overrides-actions">
                            <a href="{{edit_url}}">{{#str}}edit{{/str}}</a>
                            &nbsp;|&nbsp;
                            <a href="{{delete_url}}">{{#str}}delete{{/str}}</a>
                        </td>
                    </tr>
                {{/rows}}
            </tbody>
        </table>
    {{/has_rows}}
</div>
```

- [ ] **Step 3: Lint PHP**

```bash
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/classes/output/overrides_table.php"
```
Expected: clean.

- [ ] **Step 4: Commit**

```bash
git add redaction/classes/output/overrides_table.php redaction/templates/overrides_table.mustache
git commit -m "feat(overrides): add listing renderable + Mustache template"
```

---

## Task 10: Listing page `pages/overrides.php`

**Files:**
- Create: `redaction/pages/overrides.php`

This page is loaded as a standalone entry from the activity navigation, NOT from `view.php?page=…` (different access pattern from the other admin pages). It bootstraps Moodle on its own.

- [ ] **Step 1: Create the file**

```php
<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

$id = required_param('id', PARAM_INT); // Course module id.
$mode = optional_param('mode', 'user', PARAM_ALPHA);

if (!in_array($mode, ['user', 'group'], true)) {
    $mode = 'user';
}

$cm = get_coursemodule_from_id('redaction', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/redaction:manageoverrides', $context);

$PAGE->set_url('/mod/redaction/pages/overrides.php', ['id' => $cm->id, 'mode' => $mode]);
$PAGE->set_title(format_string($redaction->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$correction = $DB->get_record('redaction_correction', ['redactionid' => $redaction->id]);
$instancedeadline = $correction && !empty($correction->deadline_date)
    ? (int) $correction->deadline_date : null;

// Load rows.
if ($mode === 'user') {
    $sql = "SELECT o.*, u.firstname, u.lastname
              FROM {redaction_overrides} o
              JOIN {user} u ON u.id = o.userid
             WHERE o.redactionid = :rid AND o.userid IS NOT NULL
          ORDER BY u.lastname, u.firstname";
    $rows = $DB->get_records_sql($sql, ['rid' => $redaction->id]);
    foreach ($rows as $row) {
        $row->_target_label = fullname((object) [
            'firstname' => $row->firstname,
            'lastname' => $row->lastname,
        ]);
    }
} else {
    $sql = "SELECT o.*, g.name AS groupname
              FROM {redaction_overrides} o
              JOIN {groups} g ON g.id = o.groupid
             WHERE o.redactionid = :rid AND o.groupid IS NOT NULL
          ORDER BY COALESCE(o.sortorder, 0), g.name";
    $rows = $DB->get_records_sql($sql, ['rid' => $redaction->id]);
    foreach ($rows as $row) {
        $row->_target_label = format_string($row->groupname);
    }
}

$renderable = new \mod_redaction\output\overrides_table($mode, $cm->id, $rows, $instancedeadline);
$heading = get_string($mode === 'user' ? 'useroverrides' : 'groupoverrides', 'mod_redaction');

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
echo $OUTPUT->render_from_template('mod_redaction/overrides_table', $renderable->export_for_template($OUTPUT));
echo $OUTPUT->footer();
```

- [ ] **Step 2: Lint**

```bash
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/pages/overrides.php"
```
Expected: clean.

- [ ] **Step 3: Commit**

```bash
git add redaction/pages/overrides.php
git commit -m "feat(overrides): add listing page"
```

---

## Task 11: Edit page `pages/override_edit.php`

**Files:**
- Create: `redaction/pages/override_edit.php`

- [ ] **Step 1: Create the file**

```php
<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use mod_redaction\form\override_form;

$id = required_param('id', PARAM_INT); // cmid.
$overrideid = optional_param('overrideid', 0, PARAM_INT);
$mode = optional_param('mode', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('redaction', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/redaction:manageoverrides', $context);

$existing = null;
if ($overrideid) {
    $existing = $DB->get_record('redaction_overrides', ['id' => $overrideid, 'redactionid' => $redaction->id], '*', MUST_EXIST);
    $mode = $existing->userid ? 'user' : 'group';
}
if (!in_array($mode, ['user', 'group'], true)) {
    throw new \moodle_exception('invalidparameter', 'debug', '', 'mode');
}

$PAGE->set_url('/mod/redaction/pages/override_edit.php', ['id' => $cm->id, 'overrideid' => $overrideid, 'mode' => $mode]);
$PAGE->set_title(format_string($redaction->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Build custom data lists.
$userlist = [];
$grouplist = [];
if ($mode === 'user') {
    $users = get_enrolled_users($context, 'mod/redaction:submit', 0, 'u.id, u.firstname, u.lastname',
        'u.lastname, u.firstname');
    foreach ($users as $u) {
        $userlist[$u->id] = fullname($u);
    }
} else {
    foreach (groups_get_all_groups($course->id) as $g) {
        $grouplist[$g->id] = format_string($g->name);
    }
}

$mform = new override_form(null, [
    'mode' => $mode,
    'cmid' => $cm->id,
    'redactionid' => (int) $redaction->id,
    'context' => $context,
    'existing' => $existing,
    'userlist' => $userlist,
    'grouplist' => $grouplist,
    'groupmodewarning' => ($mode === 'user' && (int) $redaction->group_submission === 1),
]);

$listurl = new \moodle_url('/mod/redaction/pages/overrides.php', ['id' => $cm->id, 'mode' => $mode]);

if ($mform->is_cancelled()) {
    redirect($listurl);
}

if ($data = $mform->get_data()) {
    $now = time();
    $record = (object) [
        'redactionid' => (int) $redaction->id,
        'deadline_date' => !empty($data->deadline_date) ? (int) $data->deadline_date : null,
        'timemodified' => $now,
    ];

    if ($mode === 'user') {
        $record->userid = (int) $data->userid;
        $record->groupid = null;
    } else {
        $record->groupid = (int) $data->groupid;
        $record->userid = null;
        $record->sortorder = (int) ($data->sortorder ?? 0);
    }

    if ($existing) {
        $record->id = (int) $existing->id;
        $DB->update_record('redaction_overrides', $record);
        $eventclass = $mode === 'user'
            ? \mod_redaction\event\user_override_updated::class
            : \mod_redaction\event\group_override_updated::class;
        $message = get_string('override_updated', 'mod_redaction');
    } else {
        $record->timecreated = $now;
        $record->id = $DB->insert_record('redaction_overrides', $record);
        $eventclass = $mode === 'user'
            ? \mod_redaction\event\user_override_created::class
            : \mod_redaction\event\group_override_created::class;
        $message = get_string('override_created', 'mod_redaction');
    }

    $event = $eventclass::create([
        'objectid' => $record->id,
        'context' => $context,
        'other' => [
            'redactionid' => (int) $redaction->id,
            'userid' => $record->userid,
            'groupid' => $record->groupid,
        ],
    ]);
    $event->trigger();

    redirect($listurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($existing ? 'editoverride' : ($mode === 'user' ? 'adduseroverride' : 'addgroupoverride'), 'mod_redaction'));
$mform->display();
echo $OUTPUT->footer();
```

- [ ] **Step 2: Lint**

```bash
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/pages/override_edit.php"
```

The lint will succeed even though the event classes do not yet exist — they will be created in Task 13. To verify the static references resolve at runtime, postpone manual testing until Task 13 lands.

- [ ] **Step 3: Commit**

```bash
git add redaction/pages/override_edit.php
git commit -m "feat(overrides): add create/edit page"
```

---

## Task 12: Delete page `pages/override_delete.php`

**Files:**
- Create: `redaction/pages/override_delete.php`

- [ ] **Step 1: Create the file**

```php
<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

$id = required_param('id', PARAM_INT); // cmid.
$overrideid = required_param('overrideid', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

$cm = get_coursemodule_from_id('redaction', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/redaction:manageoverrides', $context);
require_sesskey();

$override = $DB->get_record('redaction_overrides',
    ['id' => $overrideid, 'redactionid' => $redaction->id], '*', MUST_EXIST);
$mode = $override->userid ? 'user' : 'group';
$listurl = new \moodle_url('/mod/redaction/pages/overrides.php', ['id' => $cm->id, 'mode' => $mode]);

if ($confirm) {
    require_sesskey();
    $DB->delete_records('redaction_overrides', ['id' => $override->id]);

    $eventclass = $mode === 'user'
        ? \mod_redaction\event\user_override_deleted::class
        : \mod_redaction\event\group_override_deleted::class;
    $event = $eventclass::create([
        'objectid' => (int) $override->id,
        'context' => $context,
        'other' => [
            'redactionid' => (int) $redaction->id,
            'userid' => $override->userid,
            'groupid' => $override->groupid,
        ],
    ]);
    $event->trigger();

    redirect($listurl, get_string('override_deleted', 'mod_redaction'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->set_url('/mod/redaction/pages/override_delete.php',
    ['id' => $cm->id, 'overrideid' => $overrideid, 'sesskey' => sesskey()]);
$PAGE->set_title(format_string($redaction->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

if ($mode === 'user') {
    $target = $DB->get_record('user', ['id' => $override->userid], 'id, firstname, lastname');
    $label = fullname($target);
    $msg = get_string('override_confirm_delete_user', 'mod_redaction', $label);
} else {
    $target = $DB->get_record('groups', ['id' => $override->groupid], 'id, name');
    $label = format_string($target->name);
    $msg = get_string('override_confirm_delete_group', 'mod_redaction', $label);
}

$confirmurl = new \moodle_url('/mod/redaction/pages/override_delete.php', [
    'id' => $cm->id,
    'overrideid' => $overrideid,
    'sesskey' => sesskey(),
    'confirm' => 1,
]);

echo $OUTPUT->header();
echo $OUTPUT->confirm($msg, $confirmurl, $listurl);
echo $OUTPUT->footer();
```

- [ ] **Step 2: Lint**

```bash
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/pages/override_delete.php"
```

- [ ] **Step 3: Commit**

```bash
git add redaction/pages/override_delete.php
git commit -m "feat(overrides): add delete confirmation page"
```

---

## Task 13: Event classes (6 files)

**Files:**
- Create: `redaction/classes/event/user_override_created.php`
- Create: `redaction/classes/event/user_override_updated.php`
- Create: `redaction/classes/event/user_override_deleted.php`
- Create: `redaction/classes/event/group_override_created.php`
- Create: `redaction/classes/event/group_override_updated.php`
- Create: `redaction/classes/event/group_override_deleted.php`

All six classes share the same shape. We give the full content of one, then list the deltas.

- [ ] **Step 1: Create `user_override_created.php`**

```php
<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_redaction\event;

defined('MOODLE_INTERNAL') || die();

/**
 * User override created event.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_override_created extends \core\event\base {

    protected function init() {
        $this->data['objecttable'] = 'redaction_overrides';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    public static function get_name() {
        return get_string('event_user_override_created', 'mod_redaction');
    }

    public function get_description() {
        return "The user with id '$this->userid' created a user override with id '$this->objectid' " .
            "for the redaction with course module id '$this->contextinstanceid'.";
    }

    public function get_url() {
        return new \moodle_url('/mod/redaction/pages/overrides.php', [
            'id' => $this->contextinstanceid,
            'mode' => 'user',
        ]);
    }

    public static function get_objectid_mapping() {
        return ['db' => 'redaction_overrides', 'restore' => 'redaction_overrides'];
    }
}
```

- [ ] **Step 2: Create the five sibling classes**

Each sibling differs only by class name, `crud`, `get_name()` string key, and `get_description()` verb (created/updated/deleted) and the `mode` query param in `get_url()`. Repeat the template above with these substitutions:

| File | Class name | `crud` | get_name string | verb |
|---|---|---|---|---|
| `user_override_updated.php` | `user_override_updated` | `'u'` | `event_user_override_updated` | `updated` |
| `user_override_deleted.php` | `user_override_deleted` | `'d'` | `event_user_override_deleted` | `deleted` |
| `group_override_created.php` | `group_override_created` | `'c'` | `event_group_override_created` | `created` (group override; URL mode=group) |
| `group_override_updated.php` | `group_override_updated` | `'u'` | `event_group_override_updated` | `updated` (group override) |
| `group_override_deleted.php` | `group_override_deleted` | `'d'` | `event_group_override_deleted` | `deleted` (group override) |

For the group_* variants, also change the `get_url()` to use `'mode' => 'group'` and the description to read "group override" instead of "user override".

- [ ] **Step 3: Lint all six**

```bash
for f in /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/classes/event/{user,group}_override_{created,updated,deleted}.php; do
  php -l "$f" || exit 1
done
```
Expected: all clean.

- [ ] **Step 4: Commit**

```bash
git add redaction/classes/event/{user,group}_override_{created,updated,deleted}.php
git commit -m "feat(overrides): add CRUD event classes"
```

---

## Task 14: Settings navigation integration

**Files:**
- Modify: `redaction/lib.php`

- [ ] **Step 1: Add the navigation hook at the end of `lib.php`**

Append at EOF of `redaction/lib.php`:

```php

/**
 * Add overrides entries to the module admin menu.
 *
 * @param settings_navigation $settings
 * @param navigation_node $redactionnode
 */
function redaction_extend_settings_navigation(settings_navigation $settings, navigation_node $redactionnode) {
    global $PAGE;

    $cm = $PAGE->cm;
    if (!$cm) {
        return;
    }
    $context = context_module::instance($cm->id);

    if (!has_capability('mod/redaction:manageoverrides', $context)) {
        return;
    }

    $useruri = new moodle_url('/mod/redaction/pages/overrides.php',
        ['id' => $cm->id, 'mode' => 'user']);
    $redactionnode->add(
        get_string('useroverrides', 'mod_redaction'),
        $useruri,
        navigation_node::TYPE_SETTING,
        null,
        'mod_redaction_overrides_user'
    );

    $course = $PAGE->course;
    if ($course && groups_get_all_groups($course->id)) {
        $groupuri = new moodle_url('/mod/redaction/pages/overrides.php',
            ['id' => $cm->id, 'mode' => 'group']);
        $redactionnode->add(
            get_string('groupoverrides', 'mod_redaction'),
            $groupuri,
            navigation_node::TYPE_SETTING,
            null,
            'mod_redaction_overrides_group'
        );
    }
}
```

- [ ] **Step 2: Lint**

```bash
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/lib.php"
```

- [ ] **Step 3: Commit**

```bash
git add redaction/lib.php
git commit -m "feat(overrides): expose user/group overrides in module admin nav"
```

---

## Task 15: CSS

**Files:**
- Modify: `redaction/styles.css`

- [ ] **Step 1: Append CSS rules**

Append at EOF of `redaction/styles.css`:

```css
/* Overrides feature */
.mod_redaction-overrides {
    margin: 1rem 0;
}
.mod_redaction-overrides-instance {
    font-size: 0.95em;
    color: var(--gray, #666);
    margin-bottom: 1rem;
}
.mod_redaction-overrides-add {
    margin-bottom: 1rem;
}
.mod_redaction-overrides-empty {
    margin: 1rem 0;
}
.mod_redaction-overrides-table {
    width: 100%;
    margin-top: 0.5rem;
}
.mod_redaction-overrides-actions a {
    margin-right: 0.5rem;
}
.mod_redaction-overrides-warning {
    margin-bottom: 1rem;
}
```

- [ ] **Step 2: Commit**

```bash
git add redaction/styles.css
git commit -m "style(overrides): add scoped CSS for overrides UI"
```

---

## Task 16: Observer + events.php registration

**Files:**
- Create: `redaction/classes/observer.php`
- Modify: `redaction/db/events.php`

- [ ] **Step 1: Inspect existing `db/events.php`**

```bash
cat "/Volumes/DONNEES/Claude code/mod_redaction/redaction/db/events.php"
```
Note whether `$observers` is defined and its current entries.

- [ ] **Step 2: Create the observer class**

```php
<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer: cleans up orphan override rows.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;
        $DB->delete_records('redaction_overrides', ['userid' => (int) $event->objectid]);
    }

    public static function group_deleted(\core\event\group_deleted $event): void {
        global $DB;
        $DB->delete_records('redaction_overrides', ['groupid' => (int) $event->objectid]);
    }
}
```

- [ ] **Step 3: Register observers in `db/events.php`**

Open `redaction/db/events.php`. If `$observers` is already declared, append the two entries inside the array. If not, create it. The full file content should be:

```php
<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$observers = [
    // ... existing entries kept as-is ...
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

(Keep any pre-existing observer entries — only add the two new ones to the array.)

- [ ] **Step 4: Bump version so observers register**

Edit `redaction/version.php` and bump the patch component to force a Moodle re-registration:

```php
$plugin->version = 2026051201;
```

- [ ] **Step 5: Lint**

```bash
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/classes/observer.php"
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/db/events.php"
```

- [ ] **Step 6: Add an observer test**

Append this test to `redaction/tests/lib_overrides_test.php`, inside the class:

```php
    public function test_user_deleted_observer_cleans_overrides(): void {
        global $DB;
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'userid' => $this->student->id,
            'deadline_date' => 2000,
        ]);
        $this->assertTrue($DB->record_exists('redaction_overrides',
            ['userid' => $this->student->id]));

        delete_user($this->student);

        $this->assertFalse($DB->record_exists('redaction_overrides',
            ['userid' => $this->student->id]));
    }

    public function test_group_deleted_observer_cleans_overrides(): void {
        global $DB;
        $this->gen->create_override([
            'redactionid' => $this->redaction->id,
            'groupid' => $this->group->id,
            'deadline_date' => 2000,
        ]);
        $this->assertTrue($DB->record_exists('redaction_overrides',
            ['groupid' => $this->group->id]));

        groups_delete_group($this->group);

        $this->assertFalse($DB->record_exists('redaction_overrides',
            ['groupid' => $this->group->id]));
    }
```

Run them:

```bash
vendor/bin/phpunit mod/redaction/tests/lib_overrides_test.php
```
Expected: green.

- [ ] **Step 7: Commit**

```bash
git add redaction/classes/observer.php redaction/db/events.php redaction/version.php redaction/tests/lib_overrides_test.php
git commit -m "feat(overrides): clean up overrides on user/group deletion + tests"
```

---

## Task 17: Cleanup hook in `redaction_delete_instance`

**Files:**
- Modify: `redaction/lib.php`

- [ ] **Step 1: Edit the function**

Open `redaction/lib.php`, find the `redaction_delete_instance($id)` function (around line 137) and the cluster of `$DB->delete_records(...)` calls (lines 145–150). Add one line in that cluster:

```php
    $DB->delete_records('redaction_overrides', ['redactionid' => $id]);
```
Place it next to the other `delete_records` calls — order is not important.

- [ ] **Step 2: Lint**

```bash
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/lib.php"
```

- [ ] **Step 3: Commit**

```bash
git add redaction/lib.php
git commit -m "fix(overrides): cascade delete overrides when instance is removed"
```

---

## Task 18: Backup support

**Files:**
- Modify: `redaction/backup/moodle2/backup_redaction_stepslib.php`

- [ ] **Step 1: Add the overrides nested element**

Open `redaction/backup/moodle2/backup_redaction_stepslib.php`. In `define_structure()`, just before the `// Build the tree.` comment (around line 140), add:

```php
        $overrides = new backup_nested_element('overrides');
        $override = new backup_nested_element('override', ['id'], [
            'groupid',
            'userid',
            'deadline_date',
            'sortorder',
            'timecreated',
            'timemodified',
        ]);
```

In the tree-building section (after `$redaction->add_child(...)` calls), add:

```php
        $redaction->add_child($overrides);
        $overrides->add_child($override);
```

In the sources section (after the existing `$correction->set_source_table(...)` line, around line 153), add:

```php
        $override->set_source_table('redaction_overrides', ['redactionid' => backup::VAR_PARENTID]);
```

In the ID annotations section (around line 162), add:

```php
        $override->annotate_ids('user', 'userid');
        $override->annotate_ids('group', 'groupid');
```

- [ ] **Step 2: Lint**

```bash
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/backup/moodle2/backup_redaction_stepslib.php"
```

- [ ] **Step 3: Commit**

```bash
git add redaction/backup/moodle2/backup_redaction_stepslib.php
git commit -m "feat(overrides): include overrides in activity backup"
```

---

## Task 19: Restore support + test

**Files:**
- Modify: `redaction/backup/moodle2/restore_redaction_stepslib.php`
- Create: `redaction/tests/backup_restore_overrides_test.php`

- [ ] **Step 1: Register the new restore path**

In `restore_redaction_stepslib.php`, inside `define_structure()`, add this line in the always-present paths block (just after the `redaction_correction` path, around line 47):

```php
        $paths[] = new restore_path_element('redaction_override', '/activity/redaction/overrides/override');
```

- [ ] **Step 2: Add the processing method**

Append this method inside the class body (just before `protected function after_execute()`):

```php
    /**
     * Process a redaction override element.
     *
     * @param array $data
     */
    protected function process_redaction_override($data) {
        global $DB;

        $data = (object) $data;
        $data->redactionid = $this->get_new_parentid('redaction');

        if (!empty($data->userid)) {
            $data->userid = $this->get_mappingid('user', $data->userid);
            if (!$data->userid) {
                return;
            }
        }
        if (!empty($data->groupid)) {
            $data->groupid = $this->get_mappingid('group', $data->groupid);
            if (!$data->groupid) {
                return;
            }
        }

        $data->deadline_date = !empty($data->deadline_date)
            ? $this->apply_date_offset($data->deadline_date) : null;
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        unset($data->id);
        $DB->insert_record('redaction_overrides', $data);
    }
```

- [ ] **Step 3: Write the roundtrip test**

Create `redaction/tests/backup_restore_overrides_test.php`:

```php
<?php
namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \restore_redaction_activity_structure_step
 */
final class backup_restore_overrides_test extends \advanced_testcase {

    public function test_user_override_is_preserved_across_backup_restore(): void {
        $this->resetAfterTest();
        global $DB, $USER, $CFG;
        $CFG->backup_release = '4.5';
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_redaction');
        $redaction = $gen->create_instance(['course' => $course->id]);

        $gen->create_override([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'deadline_date' => 1234567890,
        ]);

        // Backup.
        $bc = new \backup_controller(\backup::TYPE_1ACTIVITY,
            $redaction->cmid, \backup::FORMAT_MOODLE, \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL, $USER->id);
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $bc->destroy();

        // Restore to a new course.
        $newcourse = $this->getDataGenerator()->create_course();
        $student2 = $this->getDataGenerator()->create_and_enrol($newcourse, 'student');
        // The mapping uses the user id we backed up; for the test we map old student id to student2.
        $tempdir = make_request_directory();
        $file->extract_to_pathname(\get_file_packer('application/vnd.moodle.backup'), $tempdir);
        $rc = new \restore_controller(basename($tempdir), $newcourse->id, \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL, $USER->id, \backup::TARGET_NEW_COURSE);
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $count = $DB->count_records('redaction_overrides');
        $this->assertGreaterThanOrEqual(2, $count, 'Both original and restored overrides should exist');
    }
}
```

- [ ] **Step 4: Run tests**

```bash
vendor/bin/phpunit mod/redaction/tests/backup_restore_overrides_test.php
```
Expected: pass.

- [ ] **Step 5: Lint + commit**

```bash
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/backup/moodle2/restore_redaction_stepslib.php"
git add redaction/backup/moodle2/restore_redaction_stepslib.php redaction/tests/backup_restore_overrides_test.php
git commit -m "feat(overrides): restore overrides with id remapping + roundtrip test"
```

---

## Task 20: Privacy API extension

**Files:**
- Modify: `redaction/classes/privacy/provider.php`

The current provider exposes `redaction_submission`, `redaction_history`, and `redaction_ai_evaluations`. We add `redaction_overrides`.

- [ ] **Step 1: Add metadata declaration**

In `get_metadata(collection $collection)`, after the `redaction_ai_evaluations` block (around line 109), add:

```php
        // The redaction_overrides table stores per-user deadline overrides.
        $collection->add_database_table(
            'redaction_overrides',
            [
                'userid' => 'privacy:metadata:redaction_overrides:userid',
                'deadline_date' => 'privacy:metadata:redaction_overrides:deadline_date',
                'timecreated' => 'privacy:metadata:redaction_overrides:timecreated',
                'timemodified' => 'privacy:metadata:redaction_overrides:timemodified',
            ],
            'privacy:metadata:redaction_overrides'
        );
```

- [ ] **Step 2: Extend `get_contexts_for_userid`**

Append this SQL block at the end of the method (before `return $contextlist;`):

```php
        $sql = "SELECT c.id
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
            INNER JOIN {modules} m ON m.id = cm.module AND m.name = :modname
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_overrides} ro ON ro.redactionid = r.id
                 WHERE ro.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'redaction',
            'userid' => $userid,
        ]);
```

- [ ] **Step 3: Extend `get_users_in_context`**

Append at the end of the method:

```php
        $sql = "SELECT ro.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {redaction} r ON r.id = cm.instance
                  JOIN {redaction_overrides} ro ON ro.redactionid = r.id
                 WHERE cm.id = :instanceid AND ro.userid IS NOT NULL";
        $userlist->add_from_sql('userid', $sql, $params);
```

- [ ] **Step 4: Extend `export_user_data`**

Append at the end of the method, in the same style as the existing AI evaluations export:

```php
        $sql = "SELECT cm.id AS cmid,
                       ro.deadline_date,
                       ro.timecreated,
                       ro.timemodified
                  FROM {context} c
            INNER JOIN {course_modules} cm ON cm.id = c.instanceid
            INNER JOIN {redaction} r ON r.id = cm.instance
            INNER JOIN {redaction_overrides} ro ON ro.redactionid = r.id
                 WHERE c.id {$contextsql}
                   AND ro.userid = :userid
              ORDER BY cm.id";
        $params = ['userid' => $user->id] + $contextparams;
        $overrides = $DB->get_recordset_sql($sql, $params);

        $bycontext = [];
        foreach ($overrides as $o) {
            $bycontext[$o->cmid][] = [
                'deadline' => $o->deadline_date ? transform::datetime($o->deadline_date) : null,
                'timecreated' => transform::datetime($o->timecreated),
                'timemodified' => transform::datetime($o->timemodified),
            ];
        }
        $overrides->close();

        foreach ($bycontext as $cmid => $data) {
            $context = \context_module::instance($cmid);
            writer::with_context($context)->export_data(
                [get_string('overrides', 'mod_redaction')],
                (object) ['overrides' => $data]
            );
        }
```

- [ ] **Step 5: Extend `delete_data_for_all_users_in_context`**

After the existing `delete_records('redaction_submission'...)` line (around line 410), add:

```php
        $DB->delete_records('redaction_overrides', ['redactionid' => $redactionid]);
```

- [ ] **Step 6: Extend `delete_data_for_user`**

Inside the `foreach ($contextlist->get_contexts() as $context)` loop, after the existing per-context deletions, add:

```php
            $DB->delete_records('redaction_overrides', [
                'redactionid' => $redactionid,
                'userid' => $userid,
            ]);
```

- [ ] **Step 7: Extend `delete_data_for_users`**

After the existing `delete_records_select('redaction_submission', ...)` near the end of the method, add:

```php
        $DB->delete_records_select(
            'redaction_overrides',
            "redactionid = :redactionid AND userid {$usersql}",
            ['redactionid' => $redactionid] + $userparams
        );
```

- [ ] **Step 8: Add a privacy test**

Create `redaction/tests/privacy_overrides_test.php`:

```php
<?php
namespace mod_redaction;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use mod_redaction\privacy\provider;

/**
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_redaction\privacy\provider
 */
final class privacy_overrides_test extends \core_privacy\tests\provider_testcase {

    public function test_get_contexts_includes_override_context(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_redaction');
        $redaction = $gen->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('redaction', $redaction->id);
        $context = \context_module::instance($cm->id);

        $gen->create_override([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'deadline_date' => time() + 3600,
        ]);

        $list = provider::get_contexts_for_userid($student->id);
        $this->assertContains((int) $context->id, $list->get_contextids());
    }

    public function test_delete_data_for_user_removes_overrides(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_redaction');
        $redaction = $gen->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('redaction', $redaction->id);
        $context = \context_module::instance($cm->id);

        $gen->create_override([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'deadline_date' => time() + 3600,
        ]);

        $contextlist = new approved_contextlist($student, 'mod_redaction', [$context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertFalse($DB->record_exists('redaction_overrides',
            ['userid' => $student->id, 'redactionid' => $redaction->id]));
    }

    public function test_delete_data_for_all_users_removes_overrides(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_redaction');
        $redaction = $gen->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('redaction', $redaction->id);
        $context = \context_module::instance($cm->id);

        $gen->create_override([
            'redactionid' => $redaction->id,
            'userid' => $student->id,
            'deadline_date' => time() + 3600,
        ]);

        provider::delete_data_for_all_users_in_context($context);

        $this->assertFalse($DB->record_exists('redaction_overrides',
            ['redactionid' => $redaction->id]));
    }
}
```

Run it:

```bash
vendor/bin/phpunit mod/redaction/tests/privacy_overrides_test.php
```
Expected: all three tests green.

- [ ] **Step 9: Lint**

```bash
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/classes/privacy/provider.php"
php -l "/Volumes/DONNEES/Claude code/mod_redaction/redaction/tests/privacy_overrides_test.php"
```

- [ ] **Step 10: Commit**

```bash
git add redaction/classes/privacy/provider.php redaction/tests/privacy_overrides_test.php
git commit -m "feat(overrides): extend Privacy API to overrides table + tests"
```

---

## Task 21: Add `view.php` deadline display (regression-safe pass)

This task verifies Task 7 was applied correctly by adding a focused PHPUnit test that asserts the public-facing helper is used in the place(s) we identified. If Task 7 was already complete, this task is a no-op verification.

- [ ] **Step 1: Manually re-grep for direct uses**

```bash
grep -rn "correction->deadline_date\|->deadline_date" /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/view.php /Volumes/DONNEES/Claude\ code/mod_redaction/redaction/pages/ | grep -v test
```
For each line, decide:
- Teacher/admin page (correction_model, grading_overview, dashboard) → leave the raw instance deadline.
- Student page (the one rendering `redaction.mustache` for the student) → must use `redaction_get_effective_deadline()`.

If any student-facing line still uses `$correction->deadline_date`, replace it per Task 7 Step 2.

- [ ] **Step 2: Commit (if changes were made)**

```bash
git add redaction/view.php redaction/pages
git commit -m "fix(overrides): ensure all student-facing deadlines use effective deadline" --allow-empty
```
(The `--allow-empty` lets you mark the verification pass even if no further change was needed.)

---

## Task 22: Final verification

- [ ] **Step 1: Run the full PHPUnit suite for mod_redaction**

```bash
vendor/bin/phpunit --testsuite mod_redaction_testsuite
```
(If no testsuite name is registered, fall back to `vendor/bin/phpunit mod/redaction/tests/`.)
Expected: all tests green.

- [ ] **Step 2: Run the Moodle code checker (CONTRIB-10280 prerequisites)**

```bash
# Adjust path to your local Moodle install
php admin/tool/phpcs/cli/run_codechecker.php --severity=1 --moodle=mod/redaction
```
Expected: no critical violations on new files. Address PSR-12/Moodle style nits inline.

- [ ] **Step 3: Manual UI walkthrough on preprod**

Deploy to preprod per the procedure in `~/.claude/projects/.../reference_preprod_deploy_procedure.md`. Then, signed in as an editing teacher on a redaction instance:

1. Confirm two new entries appear in the activity admin menu: « Dérogations utilisateur » et « Dérogations de groupe ».
2. Create a user override with a deadline 1 minute in the past. Sign in as the targeted student and confirm submission is now blocked with the existing `deadline_passed` message.
3. Delete the override; verify the student can submit again.
4. Create a group override with sortorder=1; create a second one on a different group with sortorder=10 (both containing the same student). Verify the precedence: the lowest sortorder wins.
5. Create a user override AND a group override for the same student. Verify the user override wins.
6. Trigger the cron task manually:
   ```bash
   php admin/cli/scheduled_task.php --execute='\mod_redaction\task\auto_submit_deadline'
   ```
   Verify a draft past its user-override deadline is auto-submitted.
7. Run a backup, restore into a new course (with the same student enrolled), and verify overrides survive.

- [ ] **Step 4: Update the pre-publication audit doc**

In `docs/superpowers/audit/2026-05-06-pre-publication-audit.md` (or the latest audit file), add a note that the overrides feature is now shipped. No other backlog item is impacted.

- [ ] **Step 5: Final commit**

```bash
git add docs/
git commit -m "docs(overrides): mark feature as shipped in pre-publication audit" --allow-empty
```

---

## Self-review checklist (run before declaring the plan done)

- [ ] All capability checks use `mod/redaction:manageoverrides`
- [ ] No hard-coded user-facing strings in PHP/JS/Mustache — every string flows through `get_string()` or `{{#str}}…{{/str}}`
- [ ] All new CSS uses `.mod_redaction-overrides-*` (single-class preffixed) — no descendant selectors, no inline styles in PHP
- [ ] `version.php` final value matches the latest `upgrade_mod_savepoint` value
- [ ] Every page calls `require_login`, `require_capability`, and `require_sesskey()` on POST
- [ ] Events emitted on every CRUD operation in the pages
- [ ] Backup/restore path matches: backup writes `/activity/redaction/overrides/override`, restore reads the same path
- [ ] Privacy API: metadata, contexts, userlist, export, delete-context, delete-user, delete-users all extended
- [ ] `redaction_get_effective_deadline()` is the single source of truth — used in `redaction_can_submit_attempt`, in the cron task, and in the student view
- [ ] No `submission_date` overrides anywhere (out of scope per spec)
