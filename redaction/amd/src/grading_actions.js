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

define(['jquery'], function($) {

    var config = {};

    return {
        init: function(params) {
            config = params;

            // Expose functions globally for onclick handlers in PHP-generated HTML.
            window.unlockSubmission = this.unlockSubmission;
            window.triggerAIEvaluation = this.triggerAIEvaluation;
            window.applyAIGrade = this.applyAIGrade;
            window.toggleSection = this.toggleSection;
            window.showHistory = this.showHistory;
            window.bulkEvaluate = this.bulkEvaluate;
            window.bulkApplyGrade = this.bulkApplyGrade;
        },

        unlockSubmission: function(submissionId) {
            if (!confirm(config.strings.unlock_confirm)) {
                return;
            }

            var formData = new FormData();
            formData.append('sesskey', config.sesskey);
            formData.append('id', config.cmid);
            formData.append('action', 'unlock');
            formData.append('submissionid', submissionId);

            fetch(config.wwwroot + '/mod/redaction/ajax/submit.php', {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Error');
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alert(config.strings.connection_error);
            });
        },

        triggerAIEvaluation: function(submissionId) {
            var btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner" style="display:inline-block;width:16px;height:16px;margin-right:5px;"></span> ' +
                config.strings.evaluating;

            var formData = new FormData();
            formData.append('sesskey', config.sesskey);
            formData.append('id', config.cmid);
            formData.append('action', 'evaluate');
            formData.append('submissionid', submissionId);

            fetch(config.wwwroot + '/mod/redaction/ajax/evaluate.php', {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    alert(data.message || 'Error');
                    btn.disabled = false;
                    btn.innerHTML = '🚀 ' + config.strings.evaluate_with_ai;
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alert(config.strings.connection_error);
                btn.disabled = false;
            });
        },

        applyAIGrade: function(evaluationId) {
            var formData = new FormData();
            formData.append('sesskey', config.sesskey);
            formData.append('id', config.cmid);
            formData.append('evaluationid', evaluationId);

            fetch(config.wwwroot + '/mod/redaction/ajax/apply_ai_grade.php', {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.message || 'Error');
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alert(config.strings.connection_error);
            });
        },

        toggleSection: function(toggleElement) {
            toggleElement.classList.toggle('collapsed');
            var content = toggleElement.nextElementSibling;
            content.classList.toggle('collapsed');
        },

        bulkEvaluate: function() {
            // Get all submission IDs from the page.
            var submissionIds = [];
            document.querySelectorAll('[data-submissionid]').forEach(function(el) {
                submissionIds.push(parseInt(el.dataset.submissionid));
            });

            // Fallback: collect from the current page context if no data attributes.
            if (submissionIds.length === 0) {
                // Use AJAX to get all submission IDs for this activity.
                var formData = new FormData();
                formData.append('sesskey', config.sesskey);
                formData.append('id', config.cmid);
                formData.append('action', 'get_all_submissions');

                // For now, collect from select options.
                var selector = document.querySelector('.item-selector');
                if (selector) {
                    Array.from(selector.options).forEach(function(opt) {
                        var url = opt.value;
                        var match = url.match(/itemid=(\d+)/);
                        if (match) {
                            submissionIds.push(parseInt(match[1]));
                        }
                    });
                }
            }

            if (submissionIds.length === 0) {
                alert('No submissions found.');
                return;
            }

            var btn = event ? event.target : null;
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner" style="display:inline-block;width:16px;height:16px;margin-right:5px;"></span> ' +
                    (config.strings.bulk_evaluating || 'Evaluating all...');
            }

            var formData = new FormData();
            formData.append('sesskey', config.sesskey);
            formData.append('id', config.cmid);
            formData.append('submissionids', JSON.stringify(submissionIds));

            fetch(config.wwwroot + '/mod/redaction/ajax/bulk_evaluate.php', {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    var msg = (config.strings.bulk_evaluate_success || '{queued} queued, {skipped} skipped')
                        .replace('{queued}', data.queued)
                        .replace('{skipped}', data.skipped);
                    alert(msg);
                    location.reload();
                } else {
                    alert(data.message || 'Error');
                }
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '🤖 ' + (config.strings.bulk_evaluate || 'Evaluate all');
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alert(config.strings.connection_error);
                if (btn) {
                    btn.disabled = false;
                }
            });
        },

        bulkApplyGrade: function() {
            // Get all completed evaluation IDs.
            var evaluationIds = [];
            document.querySelectorAll('[data-evaluationid]').forEach(function(el) {
                evaluationIds.push(parseInt(el.dataset.evaluationid));
            });

            if (evaluationIds.length === 0) {
                alert(config.strings.no_evaluations || 'No evaluations to apply.');
                return;
            }

            if (!confirm(config.strings.bulk_apply_confirm || 'Apply all AI grades?')) {
                return;
            }

            var btn = event ? event.target : null;
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner" style="display:inline-block;width:16px;height:16px;margin-right:5px;"></span> ' +
                    (config.strings.bulk_applying || 'Applying...');
            }

            var formData = new FormData();
            formData.append('sesskey', config.sesskey);
            formData.append('id', config.cmid);
            formData.append('evaluationids', JSON.stringify(evaluationIds));

            fetch(config.wwwroot + '/mod/redaction/ajax/bulk_apply_grade.php', {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    var msg = (config.strings.bulk_apply_success || '{applied} applied, {skipped} skipped')
                        .replace('{applied}', data.applied)
                        .replace('{skipped}', data.skipped);
                    alert(msg);
                    location.reload();
                } else {
                    alert(data.message || 'Error');
                }
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '✅ ' + (config.strings.bulk_apply || 'Apply all grades');
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                alert(config.strings.connection_error);
                if (btn) {
                    btn.disabled = false;
                }
            });
        },

        showHistory: function(submissionId) {
            var modal = document.getElementById('history-modal');
            var content = document.getElementById('history-content');

            content.innerHTML = '<div class="text-center p-4"><div class="spinner"></div></div>';

            if (typeof $ !== 'undefined' && $.fn.modal) {
                $(modal).modal('show');
            } else {
                modal.style.display = 'block';
                modal.classList.add('show');
            }

            fetch(config.wwwroot + '/mod/redaction/ajax/get_history.php?sesskey=' + config.sesskey +
                '&id=' + config.cmid + '&submissionid=' + submissionId)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success && data.history) {
                    var html = '<div class="history-list">';
                    data.history.forEach(function(version) {
                        html += '<div class="history-item" style="padding: 15px; border-bottom: 1px solid #eee;">' +
                            '<div style="display: flex; justify-content: space-between; margin-bottom: 10px;">' +
                            '<strong>Version ' + version.version_number + '</strong>' +
                            '<span style="color: #666; font-size: 13px;">' + version.date + ' - ' + version.saved_by + '</span>' +
                            '</div>' +
                            '<div style="font-size: 13px; color: #666;">' +
                            version.word_count + ' ' + config.strings.words + ' | ' +
                            version.char_count + ' ' + config.strings.characters +
                            '</div></div>';
                    });
                    html += '</div>';
                    content.innerHTML = html;
                } else {
                    content.innerHTML = '<p class="text-muted">' + config.strings.no_history + '</p>';
                }
            })
            .catch(function(error) {
                console.error('Error:', error);
                content.innerHTML = '<p class="text-danger">' + config.strings.loading_error + '</p>';
            });
        }
    };
});
