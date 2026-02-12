// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Visual criteria editor for correction model page.
 *
 * @module     mod_redaction/criteria_editor
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/notification'], function(Notification) {

    var criteriaData = [];
    var config = {};

    /**
     * Escape HTML entities.
     * @param {string} text
     * @return {string}
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text || ''));
        return div.innerHTML;
    }

    /**
     * Sync criteria data to the hidden JSON field.
     */
    function syncToHiddenField() {
        var validCriteria = criteriaData.filter(function(c) {
            return c.name.trim() !== '';
        });
        var json = JSON.stringify(validCriteria, null, 2);
        document.getElementById('grille_criteres').value = json;

        var rawTextarea = document.getElementById('grille_criteres_raw');
        if (rawTextarea) {
            rawTextarea.value = json;
        }
    }

    /**
     * Update the total weight display.
     */
    function updateTotalWeight() {
        var total = criteriaData.reduce(function(sum, c) {
            return sum + (parseInt(c.weight) || 0);
        }, 0);
        var totalEl = document.getElementById('criteria-total');
        var textEl = document.getElementById('total-weight-text');

        if (total === 20) {
            totalEl.className = 'mod_redaction-criteria-total mod_redaction-criteria-total-ok';
            textEl.textContent = config.strings.weight_ok.replace('{$a}', total);
        } else if (total < 20) {
            totalEl.className = 'mod_redaction-criteria-total mod_redaction-criteria-total-warning';
            textEl.textContent = config.strings.weight_warning_under.replace('{$a}', total);
        } else {
            totalEl.className = 'mod_redaction-criteria-total mod_redaction-criteria-total-warning';
            textEl.textContent = config.strings.weight_warning_over.replace('{$a}', total);
        }
    }

    /**
     * Update a criterion field.
     * @param {number} index
     * @param {string} field
     * @param {*} value
     */
    function updateCriterion(index, field, value) {
        if (criteriaData[index]) {
            criteriaData[index][field] = value;
            if (field === 'weight') {
                updateTotalWeight();
            }
            syncToHiddenField();
        }
    }

    /**
     * Remove a criterion.
     * @param {number} index
     */
    function removeCriterion(index) {
        if (criteriaData.length <= 1) {
            return;
        }
        criteriaData.splice(index, 1);
        renderCriteria();
    }

    /**
     * Render criteria rows in the editor.
     */
    function renderCriteria() {
        var container = document.getElementById('criteria-list');
        container.innerHTML = '';

        criteriaData.forEach(function(criterion, index) {
            var row = document.createElement('div');
            row.className = 'mod_redaction-criterion-row';
            row.innerHTML =
                '<div>' +
                    '<input type="text" placeholder="' + escapeHtml(config.strings.criterion_name_placeholder) + '"' +
                    ' value="' + escapeHtml(criterion.name) + '"' +
                    ' data-index="' + index + '" data-field="name">' +
                '</div>' +
                '<div>' +
                    '<textarea placeholder="' + escapeHtml(config.strings.criterion_description_placeholder) + '"' +
                    ' data-index="' + index + '" data-field="description"' +
                    ' rows="1">' + escapeHtml(criterion.description) + '</textarea>' +
                '</div>' +
                '<div>' +
                    '<input type="number" min="0" max="20" step="1"' +
                    ' class="mod_redaction-criterion-weight-input"' +
                    ' value="' + criterion.weight + '"' +
                    ' data-index="' + index + '" data-field="weight">' +
                '</div>' +
                '<div>' +
                    '<button type="button" class="mod_redaction-btn-remove-criterion"' +
                    ' data-index="' + index + '"' +
                    ' title="' + escapeHtml(config.strings.remove_criterion) + '">' +
                        '&times;' +
                    '</button>' +
                '</div>';
            container.appendChild(row);
        });

        // Attach event listeners.
        container.querySelectorAll('input, textarea').forEach(function(el) {
            var idx = parseInt(el.dataset.index);
            var field = el.dataset.field;
            if (field) {
                el.addEventListener('input', function() {
                    var val = field === 'weight' ? (parseInt(el.value) || 0) : el.value;
                    updateCriterion(idx, field, val);
                });
            }
        });

        container.querySelectorAll('.mod_redaction-btn-remove-criterion').forEach(function(btn) {
            btn.addEventListener('click', function() {
                removeCriterion(parseInt(btn.dataset.index));
            });
        });

        updateTotalWeight();
        syncToHiddenField();
    }

    /**
     * Add a new criterion.
     */
    function addCriterion() {
        criteriaData.push({name: '', description: '', weight: 5});
        renderCriteria();
        // Focus the new name input.
        var rows = document.querySelectorAll('.mod_redaction-criterion-row');
        if (rows.length > 0) {
            var lastRow = rows[rows.length - 1];
            var input = lastRow.querySelector('input[type="text"]');
            if (input) {
                input.focus();
            }
        }
    }

    /**
     * Load criteria from a JSON string.
     * @param {string} jsonStr
     * @return {boolean}
     */
    function loadCriteriaFromJson(jsonStr) {
        try {
            var parsed = JSON.parse(jsonStr);
            if (Array.isArray(parsed)) {
                criteriaData = parsed.map(function(c) {
                    return {
                        name: c.name || '',
                        description: c.description || '',
                        weight: parseInt(c.weight) || 0,
                    };
                });
                renderCriteria();
                return true;
            }
        } catch (e) {
            Notification.exception({message: 'Invalid JSON: ' + e.message});
        }
        return false;
    }

    return {
        /**
         * Initialise the criteria editor.
         * @param {object} params Configuration
         */
        init: function(params) {
            config = params;

            // Parse initial criteria.
            var hiddenField = document.getElementById('grille_criteres');
            var raw = hiddenField.value.trim();

            if (raw) {
                try {
                    var parsed = JSON.parse(raw);
                    if (Array.isArray(parsed)) {
                        criteriaData = parsed.map(function(c) {
                            return {
                                name: c.name || '',
                                description: c.description || '',
                                weight: parseInt(c.weight) || 0,
                            };
                        });
                    }
                } catch (e) {
                    // Ignore parse errors for initial data.
                }
            }

            if (criteriaData.length === 0) {
                criteriaData = [{name: '', description: '', weight: 5}];
            }

            renderCriteria();

            // Expose functions for template onclick handlers.
            window.addCriterion = addCriterion;
            window.loadCriteriaFromJson = loadCriteriaFromJson;

            // AI generation handler.
            if (config.aienabled && config.hasconsignes) {
                window.generateWithAI = function() {
                    var btn = document.getElementById('btn-generate-ai');
                    var aiInstructions = document.getElementById('ai_instructions');

                    if (hiddenField.value.trim() || (aiInstructions && aiInstructions.value.trim())) {
                        if (!confirm(config.strings.ai_generate_confirm_overwrite)) {
                            return;
                        }
                    }

                    btn.disabled = true;
                    btn.classList.add('loading');

                    var formData = new FormData();
                    formData.append('id', config.cmid);
                    formData.append('sesskey', config.sesskey);

                    fetch(config.wwwroot + '/mod/redaction/ajax/generate_criteria.php', {
                        method: 'POST',
                        body: formData,
                    })
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(data) {
                        if (data.success) {
                            loadCriteriaFromJson(data.grille_criteres);
                            if (aiInstructions) {
                                aiInstructions.value = data.ai_instructions;
                            }
                            alert(config.strings.ai_generate_success);
                        } else {
                            alert(data.message || config.strings.ai_request_failed);
                        }
                    })
                    .catch(function(error) {
                        Notification.exception(error);
                        alert(config.strings.ai_request_failed);
                    })
                    .finally(function() {
                        btn.disabled = false;
                        btn.classList.remove('loading');
                    });
                };
            }
        },
    };
});
