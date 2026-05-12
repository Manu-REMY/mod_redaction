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
