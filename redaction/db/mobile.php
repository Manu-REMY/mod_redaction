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
 * Mobile app support descriptor.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$addons = [
    'mod_redaction' => [
        'handlers' => [
            'redactionview' => [
                'delegate' => 'CoreCourseModuleDelegate',
                'method' => 'mobile_course_view',
                'displaydata' => [
                    'icon' => $CFG->wwwroot . '/mod/redaction/pix/monologo.svg',
                    'class' => '',
                ],
                'offlinefunctions' => [
                    'mod_redaction_get_submission' => [],
                ],
                'styles' => [
                    'url' => $CFG->wwwroot . '/mod/redaction/styles.css',
                    'version' => '1.0.0',
                ],
            ],
        ],
        'lang' => [
            ['pluginname', 'redaction'],
            ['modulename', 'redaction'],
            ['consignes', 'redaction'],
            ['my_redaction', 'redaction'],
            ['status_draft', 'redaction'],
            ['status_submitted', 'redaction'],
            ['submit_redaction', 'redaction'],
            ['submit_confirm', 'redaction'],
            ['word_count', 'redaction'],
            ['char_count', 'redaction'],
            ['no_submission', 'redaction'],
            ['consignes_not_ready', 'redaction'],
            ['saving', 'redaction'],
            ['saved', 'redaction'],
            ['save_error', 'redaction'],
            ['ai_evaluation', 'redaction'],
            ['ai_grade', 'redaction'],
            ['ai_evaluation_pending', 'redaction'],
            ['ai_evaluation_complete', 'redaction'],
            ['grade', 'redaction'],
            ['feedback', 'redaction'],
            ['mobile_view_title', 'redaction'],
            ['mobile_consignes_title', 'redaction'],
            ['mobile_submission_title', 'redaction'],
            ['mobile_evaluation_title', 'redaction'],
        ],
    ],
];
