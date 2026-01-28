<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Upgrade script for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the redaction module.
 *
 * @param int $oldversion The old version of the module.
 * @return bool
 */
function xmldb_redaction_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    // Future upgrades will be added here.

    return true;
}
