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
 * Ad hoc task that updates the gradebook of one activity.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Updates the gradebook of one activity, queued by the upgrade to 2.4.0: the grade API is not used during an upgrade.
 */
class mettre_a_jour_carnet extends \core\task\adhoc_task {

    /**
     * Execute the task.
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/redaction/lib.php');

        $redactionid = (int) ($this->get_custom_data()->redactionid ?? 0);
        $redaction = $DB->get_record('redaction', ['id' => $redactionid]);
        if (!$redaction) {
            // The activity was deleted since: nothing left to update.
            return;
        }
        redaction_update_grades($redaction);
    }
}
