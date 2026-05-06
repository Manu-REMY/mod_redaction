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
