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

define(['jquery', 'core/ajax'], function($, Ajax) {

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
            var pendingIndicator = $('.mod_redaction-ai-pending');

            if (pendingIndicator.length > 0) {
                this.pollCount = 0;
                this.pollInterval = setInterval(function() {
                    self.pollCount++;
                    if (self.pollCount >= self.maxPollAttempts) {
                        self.stopPolling();
                        $('.mod_redaction-ai-pending .mod_redaction-spinner').hide();
                        $('.mod_redaction-ai-pending span').text('Evaluation timeout - please refresh the page.');
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

            Ajax.call([{
                methodname: 'mod_redaction_get_evaluation_status',
                args: {
                    cmid: this.cmid,
                    submissionid: this.submissionid
                },
                done: function(response) {
                    if (response.status) {
                        if (response.status === 'completed' || response.status === 'failed' || response.status === 'applied') {
                            clearInterval(self.pollInterval);
                            location.reload();
                        }
                    }
                },
                fail: function() {
                    // Silent error, keep polling.
                }
            }]);
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
