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
 * External functions and service definitions.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_redaction_generate_ai_summary' => [
        'classname' => 'mod_redaction\external\generate_ai_summary',
        'methodname' => 'execute',
        'description' => 'Generate or refresh AI summary for teacher dashboard',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/redaction:grade',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_get_submission' => [
        'classname' => 'mod_redaction\external\get_submission',
        'methodname' => 'execute',
        'description' => 'Get submission data for mobile app',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/redaction:view',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_submit_work' => [
        'classname' => 'mod_redaction\external\submit_work',
        'methodname' => 'execute',
        'description' => 'Submit or save work from mobile app',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/redaction:submit',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_get_evaluation_status' => [
        'classname' => 'mod_redaction\external\get_evaluation_status',
        'methodname' => 'execute',
        'description' => 'Get AI evaluation status',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/redaction:view',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_autosave' => [
        'classname' => 'mod_redaction\external\autosave',
        'methodname' => 'execute',
        'description' => 'Autosave content on consignes, redaction, and correction pages',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/redaction:view',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_evaluate_submission' => [
        'classname' => 'mod_redaction\external\evaluate_submission',
        'methodname' => 'execute',
        'description' => 'Trigger AI evaluation for a submission',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/redaction:grade',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_submit_action' => [
        'classname' => 'mod_redaction\external\submit_action',
        'methodname' => 'execute',
        'description' => 'Submit or unlock a submission',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/redaction:submit',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_apply_ai_grade' => [
        'classname' => 'mod_redaction\external\apply_ai_grade',
        'methodname' => 'execute',
        'description' => 'Apply AI evaluation grade to a submission',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/redaction:grade',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_generate_criteria' => [
        'classname' => 'mod_redaction\external\generate_criteria',
        'methodname' => 'execute',
        'description' => 'Generate evaluation criteria using AI',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/redaction:editconsignes',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_bulk_evaluate' => [
        'classname' => 'mod_redaction\external\bulk_evaluate',
        'methodname' => 'execute',
        'description' => 'Queue multiple submissions for AI evaluation',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/redaction:grade',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_bulk_unlock' => [
        'classname' => 'mod_redaction\external\bulk_unlock',
        'methodname' => 'execute',
        'description' => 'Bulk unlock submissions',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/redaction:grade',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_bulk_apply_grade' => [
        'classname' => 'mod_redaction\external\bulk_apply_grade',
        'methodname' => 'execute',
        'description' => 'Apply multiple AI grades to submissions',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/redaction:grade',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_get_history' => [
        'classname' => 'mod_redaction\external\get_history',
        'methodname' => 'execute',
        'description' => 'Get version history for a submission',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/redaction:viewhistory',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_redaction_check_similarity' => [
        'classname' => 'mod_redaction\external\check_similarity',
        'methodname' => 'execute',
        'description' => 'Check submission similarity',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/redaction:grade',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
