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

define([], function() {

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
     * Poll for training evaluation result.
     * @param {number} evaluationId
     */
    function pollTrainingResult(evaluationId) {
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

            var formData = new FormData();
            formData.append('id', config.cmid);
            formData.append('submissionid', config.submissionid);
            formData.append('sesskey', config.sesskey);

            fetch(config.wwwroot + '/mod/redaction/ajax/get_evaluation_status.php', {
                method: 'POST',
                body: formData,
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data.status === 'completed' || data.status === 'failed') {
                    clearInterval(poll);
                    location.reload();
                }
            })
            .catch(function() {
                // Ignore polling errors, continue.
            });
        }, interval);

        // Suppress unused variable warning.
        void evaluationId;
    }

    /**
     * Submit for training evaluation.
     */
    function submitTraining() {
        var btn = document.getElementById('btn-training-submit');
        var progress = document.getElementById('training-progress');

        var form = document.getElementById('redaction-form');
        var formData = new FormData(form);
        formData.set('action', 'save');

        btn.disabled = true;
        if (progress) {
            progress.style.display = 'flex';
        }

        // Step 1: Save current content.
        fetch(config.formurl, {
            method: 'POST',
            body: formData,
        }).then(function() {
            // Step 2: Submit for training evaluation.
            var trainingData = new FormData();
            trainingData.append('id', config.cmid);
            trainingData.append('sesskey', config.sesskey);

            return fetch(config.wwwroot + '/mod/redaction/ajax/training_submit.php', {
                method: 'POST',
                body: trainingData,
            });
        }).then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                pollTrainingResult(data.evaluationid);
            } else {
                alert(data.message || config.strings.ai_request_failed);
                btn.disabled = false;
                if (progress) {
                    progress.style.display = 'none';
                }
            }
        }).catch(function(error) {
            // eslint-disable-next-line no-console
            console.error('Training submit error:', error);
            alert(config.strings.ai_request_failed);
            btn.disabled = false;
            if (progress) {
                progress.style.display = 'none';
            }
        });
    }

    return {
        /**
         * Initialise the redaction page.
         * @param {object} params Configuration
         */
        init: function(params) {
            config = params;

            // Expose functions for template onclick handlers.
            window.toggleCollapsible = toggleCollapsible;

            if (config.trainingenabled) {
                window.submitTraining = submitTraining;
            }
        },
    };
});
