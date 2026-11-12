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
 * Shared checks of the student services of the assigned subjects mode.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/redaction/lib.php');

use core_external\external_api;

/**
 * Base of the student services of the assigned subjects mode.
 */
abstract class sujets_base extends external_api {

    /**
     * Resolves and checks everything a student service needs.
     *
     * The group is the one of the current user: a student never names it, so cannot act on another group.
     * When the activity is individual the submission is the student's own, with no group (groupid 0).
     *
     * @param int $cmid Course module ID.
     * @return array [$cm, $redaction, $context, $groupid, $submission]
     * @throws \moodle_exception When the mode is off or, in a group activity, the user has no group.
     */
    protected static function contexte(int $cmid): array {
        global $DB, $USER;

        $cm = get_coursemodule_from_id('redaction', $cmid, 0, false, MUST_EXIST);
        $redaction = $DB->get_record('redaction', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/redaction:submit', $context);

        if (empty($redaction->sujets_enabled)) {
            throw new \moodle_exception('sujets_disabled', 'mod_redaction');
        }

        if (\mod_redaction\sujets::individuel($redaction)) {
            $groupid = 0;
        } else {
            $groupid = (int) redaction_get_user_group($cm, $USER->id);
            if ($groupid === 0) {
                throw new \moodle_exception('group_required', 'mod_redaction');
            }
        }

        $submission = redaction_get_or_create_submission($redaction, $groupid, $USER->id);

        return [$cm, $redaction, $context, $groupid, $submission];
    }
}
