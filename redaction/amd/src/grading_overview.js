// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Progression overview interactions: column sort + bulk-action selection.
 *
 * @module     mod_redaction/grading_overview
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    'core/ajax',
    'core/notification',
    'core/str',
    'core/modal_factory',
    'core/modal_events',
], function(Ajax, Notification, Str, ModalFactory, ModalEvents) {

    var SEL = {
        TABLE: '.mod_redaction-overview-table',
        ACTIONBAR: '.mod_redaction-overview-actionbar',
        CHECKALL: '.mod_redaction-overview-checkall',
        ROWCHECK: '.mod_redaction-overview-rowcheck',
        SELCOUNT: '.mod_redaction-overview-selcount',
        BTN_REEVAL: '.mod_redaction-overview-action-reevaluate',
        BTN_UNLOCK: '.mod_redaction-overview-action-unlock',
    };

    var state = {
        cmid: 0,
    };

    function sortByName(table) {
        var tbody = table.tBodies[0];
        var rows = Array.prototype.slice.call(tbody.rows);
        var asc = table.dataset.sortName !== 'asc';
        // Name cell index depends on whether the checkbox column is present.
        var hasCheckCol = !!table.querySelector('thead ' + SEL.CHECKALL);
        var nameIndex = hasCheckCol ? 1 : 0;
        rows.sort(function(a, b) {
            var an = a.cells[nameIndex].textContent.trim().toLowerCase();
            var bn = b.cells[nameIndex].textContent.trim().toLowerCase();
            if (an < bn) {
                return asc ? -1 : 1;
            }
            if (an > bn) {
                return asc ? 1 : -1;
            }
            return 0;
        });
        rows.forEach(function(row) {
            tbody.appendChild(row);
        });
        table.dataset.sortName = asc ? 'asc' : 'desc';
    }

    function selectedChecks() {
        return Array.prototype.slice.call(
            document.querySelectorAll(SEL.ROWCHECK + ':checked')
        );
    }

    function refreshSelectionUI() {
        var count = selectedChecks().length;
        var counter = document.querySelector(SEL.SELCOUNT);
        var reBtn = document.querySelector(SEL.BTN_REEVAL);
        var unlBtn = document.querySelector(SEL.BTN_UNLOCK);
        if (counter) {
            Str.get_string('overview_selection_count', 'mod_redaction', count)
                .then(function(s) {
                    counter.textContent = s;
                    return s;
                })
                .catch(Notification.exception);
        }
        if (reBtn) {
            reBtn.disabled = count === 0;
        }
        if (unlBtn) {
            unlBtn.disabled = count === 0;
        }
    }

    function bindCheckall() {
        var master = document.querySelector(SEL.CHECKALL);
        if (!master) {
            return;
        }
        master.addEventListener('change', function() {
            var checks = document.querySelectorAll(SEL.ROWCHECK);
            checks.forEach(function(c) {
                c.checked = master.checked;
            });
            refreshSelectionUI();
        });
    }

    function bindRowChecks() {
        var checks = document.querySelectorAll(SEL.ROWCHECK);
        checks.forEach(function(c) {
            c.addEventListener('change', refreshSelectionUI);
        });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function(c) {
            return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'})[c];
        });
    }

    function buildSummaryHtml(affected, ignored, affectedTitle, ignoredTitle) {
        var html = '';
        html += '<div class="mod_redaction-overview-confirm">';
        html += '<h6 class="mod_redaction-overview-confirm-title">' + escapeHtml(affectedTitle) + '</h6>';
        if (affected.length) {
            html += '<ul>';
            affected.forEach(function(a) {
                html += '<li>' + escapeHtml(a.name) + '</li>';
            });
            html += '</ul>';
        } else {
            html += '<p><em>&mdash;</em></p>';
        }
        if (ignored.length) {
            html += '<h6 class="mod_redaction-overview-confirm-title">' + escapeHtml(ignoredTitle) + '</h6>';
            html += '<ul>';
            ignored.forEach(function(i) {
                html += '<li>' + escapeHtml(i.name) + ' <small>(' + escapeHtml(i.reason) + ')</small></li>';
            });
            html += '</ul>';
        }
        html += '</div>';
        return html;
    }

    /**
     * Partition the selected checkboxes into "affected" / "ignored" lists for a given action.
     *
     * @param {string} action 'reevaluate' or 'unlock'
     * @returns {Promise<{affected: Array, ignored: Array}>}
     */
    function partitionForAction(action) {
        var checks = selectedChecks();
        var affected = [];
        var ignored = [];

        return Str.get_strings([
            {key: 'overview_skip_reason_nocontent', component: 'mod_redaction'},
            {key: 'overview_skip_reason_alreadyunlocked', component: 'mod_redaction'},
        ]).then(function(strs) {
            var reasonNoContent = strs[0];
            var reasonAlreadyUnlocked = strs[1];

            checks.forEach(function(c) {
                var name = c.dataset.name || '';
                var subid = parseInt(c.dataset.submissionid, 10) || 0;
                var status = parseInt(c.dataset.status, 10) || 0;
                var hascontent = c.dataset.hascontent === '1';

                if (action === 'reevaluate') {
                    if (hascontent) {
                        affected.push({name: name, submissionid: subid});
                    } else {
                        ignored.push({name: name, reason: reasonNoContent});
                    }
                } else if (action === 'unlock') {
                    if (status === 1) {
                        affected.push({name: name, submissionid: subid});
                    } else {
                        ignored.push({name: name, reason: reasonAlreadyUnlocked});
                    }
                }
            });
            return {affected: affected, ignored: ignored};
        });
    }

    /**
     * Open the confirmation modal for a given action.
     * Resolves with {confirmed: bool, parts: {affected, ignored}}.
     *
     * @param {string} action 'reevaluate' or 'unlock'
     * @returns {Promise<{confirmed: boolean, parts: {affected: Array, ignored: Array}}>}
     */
    function confirmAction(action) {
        var titleKey = action === 'reevaluate'
            ? 'overview_confirm_reevaluate_title'
            : 'overview_confirm_unlock_title';

        return Promise.all([
            partitionForAction(action),
            Str.get_strings([
                {key: titleKey, component: 'mod_redaction'},
                {key: 'overview_confirm_button', component: 'mod_redaction'},
            ]),
        ]).then(function(payload) {
            var parts = payload[0];
            var strs = payload[1];
            var title = strs[0];
            var confirmLabel = strs[1];

            return Str.get_strings([
                {key: 'overview_confirm_affected', component: 'mod_redaction', param: parts.affected.length},
                {key: 'overview_confirm_ignored', component: 'mod_redaction', param: parts.ignored.length},
            ]).then(function(headers) {
                var html = buildSummaryHtml(parts.affected, parts.ignored, headers[0], headers[1]);

                return ModalFactory.create({
                    type: ModalFactory.types.SAVE_CANCEL,
                    title: title,
                    body: html,
                }).then(function(modal) {
                    modal.setSaveButtonText(confirmLabel);
                    return new Promise(function(resolve) {
                        modal.getRoot().on(ModalEvents.save, function() {
                            resolve({confirmed: true, parts: parts});
                        });
                        modal.getRoot().on(ModalEvents.cancel, function() {
                            resolve({confirmed: false, parts: parts});
                        });
                        modal.getRoot().on(ModalEvents.hidden, function() {
                            modal.destroy();
                        });
                        modal.show();
                    });
                });
            });
        });
    }

    return {
        init: function(opts) {
            opts = opts || {};
            state.cmid = parseInt(opts.cmid, 10) || 0;

            var table = document.querySelector(SEL.TABLE);
            if (!table) {
                return;
            }

            var nameHeader = table.querySelector('th[data-sort="name"]');
            if (nameHeader) {
                nameHeader.style.cursor = 'pointer';
                nameHeader.addEventListener('click', function() {
                    sortByName(table);
                });
            }

            bindCheckall();
            bindRowChecks();
            refreshSelectionUI();
        },
    };
});
