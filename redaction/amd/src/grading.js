// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Grading functionality for redaction.
 *
 * @module     mod_redaction/grading
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {

    var grading = {
        cmid: 0,
        submissionid: 0,
        pollInterval: null,
        maxPollAttempts: 120,
        pollCount: 0,

        /**
         * Initialize grading module.
         *
         * @param {Object} config Configuration object
         */
        init: function(config) {
            this.cmid = config.cmid;
            this.submissionid = config.submissionid || 0;

            // Start polling for AI evaluation status if there's a pending evaluation
            this.checkPendingEvaluation();
        },

        /**
         * Check if there's a pending AI evaluation and start polling.
         */
        checkPendingEvaluation: function() {
            var self = this;
            var pendingIndicator = $('.ai-pending');

            if (pendingIndicator.length > 0) {
                this.pollCount = 0;
                this.pollInterval = setInterval(function() {
                    self.pollCount++;
                    if (self.pollCount >= self.maxPollAttempts) {
                        self.stopPolling();
                        $('.ai-pending .spinner').hide();
                        $('.ai-pending span').text('Evaluation timeout - please refresh the page.');
                        return;
                    }
                    self.pollEvaluationStatus();
                }, 5000);
            }
        },

        /**
         * Poll for AI evaluation status.
         */
        pollEvaluationStatus: function() {
            var self = this;

            $.ajax({
                url: M.cfg.wwwroot + '/mod/redaction/ajax/get_evaluation_status.php',
                method: 'GET',
                data: {
                    id: this.cmid,
                    submissionid: this.submissionid,
                    sesskey: M.cfg.sesskey
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.status) {
                        if (response.status === 'completed' || response.status === 'failed' || response.status === 'applied') {
                            // Stop polling and reload page
                            clearInterval(self.pollInterval);
                            location.reload();
                        }
                    }
                },
                error: function() {
                    // Silent error, keep polling
                }
            });
        },

        /**
         * Stop polling.
         */
        stopPolling: function() {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
        }
    };

    return grading;
});
