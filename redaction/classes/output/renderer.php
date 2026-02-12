<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Renderer for mod_redaction grading page.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_redaction\output;

defined('MOODLE_INTERNAL') || die();

/**
 * Renderer class for the grading page.
 */
class renderer extends \plugin_renderer_base {

    /**
     * Render the grading navigation bar.
     *
     * @param array $data Template data
     * @return string HTML
     */
    public function render_grading_navigation(array $data): string {
        return $this->render_from_template('mod_redaction/grading_navigation', $data);
    }

    /**
     * Render the submission panel.
     *
     * @param array $data Template data
     * @return string HTML
     */
    public function render_submission_panel(array $data): string {
        return $this->render_from_template('mod_redaction/submission_panel', $data);
    }

    /**
     * Render the AI evaluation section.
     *
     * @param array $data Template data
     * @return string HTML
     */
    public function render_ai_evaluation(array $data): string {
        return $this->render_from_template('mod_redaction/ai_evaluation', $data);
    }

    /**
     * Render the grading form.
     *
     * @param array $data Template data
     * @return string HTML
     */
    public function render_grading_form(array $data): string {
        return $this->render_from_template('mod_redaction/grading_form', $data);
    }

    /**
     * Render the history modal.
     *
     * @param array $data Template data
     * @return string HTML
     */
    public function render_history_modal(array $data): string {
        return $this->render_from_template('mod_redaction/history_modal', $data);
    }

    /**
     * Render the training timeline panel.
     *
     * @param array $data Template data
     * @return string HTML
     */
    public function render_training_timeline(array $data): string {
        return $this->render_from_template('mod_redaction/training_timeline', $data);
    }

    /**
     * Render the home page.
     *
     * @param array $data Template data
     * @return string HTML
     */
    public function render_home(array $data): string {
        return $this->render_from_template('mod_redaction/home', $data);
    }

    /**
     * Render the consignes page.
     *
     * @param array $data Template data
     * @return string HTML
     */
    public function render_consignes(array $data): string {
        return $this->render_from_template('mod_redaction/consignes', $data);
    }

    /**
     * Render the correction model page.
     *
     * @param array $data Template data
     * @return string HTML
     */
    public function render_correction_model(array $data): string {
        return $this->render_from_template('mod_redaction/correction_model', $data);
    }

    /**
     * Render the student redaction page.
     *
     * @param array $data Template data
     * @return string HTML
     */
    public function render_redaction(array $data): string {
        return $this->render_from_template('mod_redaction/redaction', $data);
    }
}
