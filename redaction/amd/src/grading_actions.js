// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Grading page actions for redaction.
 *
 * @module     mod_redaction/grading_actions
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {

    var config = {};

    // Module-scoped flag and handler for the delegated keydown listener,
    // ensuring init() is idempotent and never stacks listeners on document.
    var keydownListenerAttached = false;
    function attemptHeaderKeydownHandler(ev) {
        if (ev.key !== 'Enter' && ev.key !== ' ' && ev.key !== 'Spacebar') {
            return;
        }
        var target = ev.target;
        if (!target || !target.classList ||
            !target.classList.contains('mod_redaction-ai-attempt-header')) {
            return;
        }
        ev.preventDefault();
        if (typeof window.toggleAttempt === 'function') {
            window.toggleAttempt(target);
        }
    }

    return {
        init: function(params) {
            config = params;

            // Expose functions globally for onclick handlers in PHP-generated HTML.
            window.unlockSubmission = this.unlockSubmission;
            window.triggerAIEvaluation = this.triggerAIEvaluation;
            window.applyAIGrade = this.applyAIGrade;
            window.toggleSection = this.toggleSection;
            window.toggleAttempt = this.toggleAttempt;
            window.showHistory = this.showHistory;

            // Keyboard support for collapsible attempt headers.
            // Idempotent: only attach the listener on the first init() call so
            // repeat invocations do not stack duplicate handlers on document.
            if (!keydownListenerAttached) {
                document.addEventListener('keydown', attemptHeaderKeydownHandler);
                keydownListenerAttached = true;
            }
        },

        unlockSubmission: function(submissionId) {
            if (!confirm(config.strings.unlock_confirm)) {
                return;
            }

            Ajax.call([{
                methodname: 'mod_redaction_submit_action',
                args: {
                    cmid: config.cmid,
                    action: 'unlock',
                    submissionid: submissionId
                },
                done: function(data) {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || config.strings.error);
                    }
                },
                fail: Notification.exception
            }]);
        },

        triggerAIEvaluation: function(submissionId) {
            var btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<span class="mod_redaction-spinner mod_redaction-spinner-inline"></span> ' +
                config.strings.evaluating;

            Ajax.call([{
                methodname: 'mod_redaction_evaluate_submission',
                args: {
                    cmid: config.cmid,
                    submissionid: submissionId
                },
                done: function(data) {
                    if (data.success) {
                        setTimeout(function() { location.reload(); }, 2000);
                    } else {
                        alert(data.message || config.strings.error);
                        btn.disabled = false;
                        btn.innerHTML = config.strings.evaluate_with_ai;
                    }
                },
                fail: function(error) {
                    Notification.exception(error);
                    btn.disabled = false;
                }
            }]);
        },

        applyAIGrade: function(evaluationId) {
            Ajax.call([{
                methodname: 'mod_redaction_apply_ai_grade',
                args: {
                    cmid: config.cmid,
                    evaluationid: evaluationId
                },
                done: function(data) {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.message || config.strings.error);
                    }
                },
                fail: Notification.exception
            }]);
        },

        toggleSection: function(toggleElement) {
            // Walk up to the parent section and toggle the "open" state class.
            // CSS shows .mod_redaction-ai-section-content only when the parent
            // carries .mod_redaction-ai-section-open.
            var section = toggleElement.closest('.mod_redaction-ai-criteria-section');
            if (section) {
                section.classList.toggle('mod_redaction-ai-section-open');
            }
        },

        toggleAttempt: function(headerElement) {
            // Walk up to the parent attempt block and toggle the open state.
            // CSS hides .mod_redaction-ai-attempt-content unless the parent block
            // carries .mod_redaction-ai-section-open.
            var block = headerElement.closest('.mod_redaction-ai-attempt-block');
            if (!block) {
                return;
            }
            var isOpen = block.classList.toggle('mod_redaction-ai-section-open');
            headerElement.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        },

        showHistory: function(submissionId) {
            var modal = document.getElementById('history-modal');
            var content = document.getElementById('history-content');

            content.innerHTML = '<div class="text-center p-4"><div class="mod_redaction-spinner"></div></div>';

            if (typeof $ !== 'undefined' && $.fn.modal) {
                $(modal).modal('show');
            } else {
                modal.style.display = 'block';
                modal.classList.add('show');
            }

            Ajax.call([{
                methodname: 'mod_redaction_get_history',
                args: {
                    cmid: config.cmid,
                    submissionid: submissionId
                },
                done: function(data) {
                    if (data.success && data.history) {
                        var html = '<div class="mod_redaction-history-list">';
                        data.history.forEach(function(version) {
                            html += '<div class="mod_redaction-history-item">' +
                                '<div class="mod_redaction-history-item-head">' +
                                '<strong>' + config.strings.version.replace('{$a}', version.version_number) + '</strong>' +
                                '<span class="mod_redaction-history-item-meta">' + version.date +
                                ' - ' + version.saved_by + '</span>' +
                                '</div>' +
                                '<div class="mod_redaction-history-item-meta">' +
                                version.word_count + ' ' + config.strings.words + ' | ' +
                                version.char_count + ' ' + config.strings.characters +
                                '</div></div>';
                        });
                        html += '</div>';
                        content.innerHTML = html;
                    } else {
                        content.innerHTML = '<p class="text-muted">' + config.strings.no_history + '</p>';
                    }
                },
                fail: function(error) {
                    content.innerHTML = '<p class="text-danger">' + config.strings.loading_error + '</p>';
                    Notification.exception(error);
                }
            }]);
        }
    };
});
