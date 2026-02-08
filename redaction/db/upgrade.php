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

    // Add AI summaries table for teacher dashboard.
    if ($oldversion < 2026012901) {
        // Define table redaction_ai_summaries.
        $table = new xmldb_table('redaction_ai_summaries');

        // Adding fields.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('redactionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('difficulties', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('strengths', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('recommendations', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('general_observation', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('submissions_analyzed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('provider', XMLDB_TYPE_CHAR, '20', null, null, null, null);
        $table->add_field('model', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('prompt_tokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('completion_tokens', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Adding keys.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('redactionid', XMLDB_KEY_FOREIGN_UNIQUE, ['redactionid'], 'redaction', ['id']);

        // Create table if it doesn't exist.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Redaction savepoint reached.
        upgrade_mod_savepoint(true, 2026012901, 'redaction');
    }

    // Audit improvements: re-encrypt any legacy base64-encoded API keys,
    // add cache definition, and event system support.
    if ($oldversion < 2026020805) {
        // Re-encrypt any API keys that were stored with base64 fallback.
        $records = $DB->get_recordset('redaction', null, '', 'id, ai_api_key');
        foreach ($records as $record) {
            if (!empty($record->ai_api_key)) {
                // Try to detect base64-encoded keys (not encrypted with \core\encryption).
                $decoded = @base64_decode($record->ai_api_key, true);
                if ($decoded !== false && base64_encode($decoded) === $record->ai_api_key) {
                    // This looks like a base64-encoded key, re-encrypt it properly.
                    try {
                        $encrypted = \core\encryption::encrypt($decoded);
                        $DB->set_field('redaction', 'ai_api_key', $encrypted, ['id' => $record->id]);
                    } catch (\Exception $e) {
                        // Skip if encryption fails - key will need manual re-entry.
                        debugging('Failed to re-encrypt API key for redaction ' . $record->id . ': ' . $e->getMessage());
                    }
                }
            }
        }
        $records->close();

        // Redaction savepoint reached.
        upgrade_mod_savepoint(true, 2026020805, 'redaction');
    }

    return true;
}
