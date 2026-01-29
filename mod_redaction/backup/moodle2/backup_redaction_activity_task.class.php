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
 * Backup task for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2025 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/redaction/backup/moodle2/backup_redaction_stepslib.php');

/**
 * Provides the steps to perform one complete backup of the redaction instance.
 *
 * @package    mod_redaction
 * @copyright  2025 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_redaction_activity_task extends backup_activity_task {

    /**
     * No specific settings for this activity.
     */
    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    /**
     * Defines the backup steps.
     */
    protected function define_my_steps() {
        // Redaction only has one structure step.
        $this->add_step(new backup_redaction_activity_structure_step('redaction_structure', 'redaction.xml'));
    }

    /**
     * Encodes URLs to the activity instance's scripts.
     *
     * @param string $content some HTML text that eventually contains URLs to the activity instance scripts
     * @return string the content with the URLs encoded
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Link to the list of redactions.
        $search = '/(' . $base . '\/mod\/redaction\/index\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@REDACTIONINDEX*$2@$', $content);

        // Link to redaction view by moduleid.
        $search = '/(' . $base . '\/mod\/redaction\/view\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@REDACTIONVIEWBYID*$2@$', $content);

        // Link to grading page.
        $search = '/(' . $base . '\/mod\/redaction\/grading\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@REDACTIONGRADING*$2@$', $content);

        return $content;
    }
}
