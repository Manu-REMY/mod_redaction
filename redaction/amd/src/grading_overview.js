// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Progression overview interactions (column sort).
 *
 * @module     mod_redaction/grading_overview
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {

    /**
     * Sort the rows of the overview table by student/group name.
     *
     * @param {HTMLTableElement} table
     */
    function sortByName(table) {
        var tbody = table.tBodies[0];
        var rows = Array.prototype.slice.call(tbody.rows);
        var asc = table.dataset.sortName !== 'asc';
        rows.sort(function(a, b) {
            var an = a.cells[0].textContent.trim().toLowerCase();
            var bn = b.cells[0].textContent.trim().toLowerCase();
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

    return {
        init: function() {
            var table = document.querySelector('.mod_redaction-overview-table');
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
        },
    };
});
