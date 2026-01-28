<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Adhoc task for processing AI evaluations.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Task to process AI evaluation asynchronously.
 */
class evaluate_submission extends \core\task\adhoc_task {

    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_evaluate_submission', 'redaction');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        $data = $this->get_custom_data();

        if (empty($data->evaluationid)) {
            mtrace('No evaluation ID provided');
            return;
        }

        require_once(__DIR__ . '/../ai_evaluator.php');

        mtrace('Processing evaluation ' . $data->evaluationid);

        $success = \mod_redaction\ai_evaluator::process_evaluation($data->evaluationid);

        if ($success) {
            mtrace('Evaluation completed successfully');
        } else {
            mtrace('Evaluation failed');
        }
    }
}
