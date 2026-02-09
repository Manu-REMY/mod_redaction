<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Scheduled task to apply pending AI grades after configured delay.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Apply pending AI grades that have passed their scheduled delay.
 */
class apply_ai_grade extends \core\task\scheduled_task {

    /**
     * Get task name.
     */
    public function get_name() {
        return get_string('task_apply_ai_grade', 'redaction');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $now = time();
        mtrace('Checking for pending AI grade applications...');

        $evaluations = $DB->get_records_select(
            'redaction_ai_evaluations',
            "status = :status AND scheduled_apply_at <= :now AND scheduled_apply_at > 0",
            ['status' => 'pending_apply', 'now' => $now]
        );

        $applied = 0;
        foreach ($evaluations as $evaluation) {
            try {
                \mod_redaction\ai_evaluator::apply_evaluation($evaluation->id, 0);
                mtrace("  Applied AI grade for evaluation {$evaluation->id}");
                $applied++;
            } catch (\Exception $e) {
                mtrace("  ERROR applying evaluation {$evaluation->id}: " . $e->getMessage());
            }
        }

        mtrace("Applied {$applied} AI grades.");
    }
}
