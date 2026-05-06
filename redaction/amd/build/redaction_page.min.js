// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Student redaction page interactions.
 *
 * @module     mod_redaction/redaction_page
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'core/notification'], function(Ajax, Notification) {

    var config = {};

    /**
     * Toggle collapsible section.
     * @param {HTMLElement} header
     */
    function toggleCollapsible(header) {
        header.classList.toggle('mod_redaction-collapsed');
        var content = header.nextElementSibling;
        content.classList.toggle('mod_redaction-collapsed');
    }

    /**
     * Poll for evaluation result via the Moodle web service.
     * @param {number} submissionid
     */
    function pollEvaluationResult(submissionid) {
        var attempts = 0;
        var maxAttempts = 120; // 10 minutes at 5s intervals.
        var interval = 5000;

        var poll = setInterval(function() {
            attempts++;
            if (attempts > maxAttempts) {
                clearInterval(poll);
                location.reload();
                return;
            }

            Ajax.call([{
                methodname: 'mod_redaction_get_evaluation_status',
                args: {submissionid: parseInt(submissionid, 10)},
            }])[0]
                .then(function(data) {
                    if (data.status === 'completed' || data.status === 'applied' || data.status === 'failed') {
                        clearInterval(poll);
                        location.reload();
                    }
                    return data;
                })
                .catch(function() {
                    // Ignore polling errors, continue.
                    return null;
                });
        }, interval);
    }

    return {
        /**
         * Initialise the redaction page.
         * @param {object} params Configuration
         */
        init: function(params) {
            config = params;

            window.toggleCollapsible = toggleCollapsible;

            // If a pending evaluation exists for this submission, start polling.
            if (config.pollEvaluation && config.submissionid) {
                pollEvaluationResult(config.submissionid);
            }

            // Suppress unused variable warning.
            void Notification;
        },
    };
});
