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
 * Dashboard module for teacher dashboard functionality.
 *
 * @module     mod_redaction/dashboard
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification', 'core/str', 'core/chartjs'],
    function($, Ajax, Notification, Str, Chart) {

    let cmid = null;
    let gradeChart = null;

    /**
     * Initialize the grade distribution chart.
     *
     * @param {Object} distribution Grade distribution data
     */
    const initGradeChart = function(distribution) {
        const canvas = document.getElementById('gradeDistributionChart');
        if (!canvas) {
            return;
        }

        // Destroy existing chart if it exists.
        if (gradeChart) {
            gradeChart.destroy();
            gradeChart = null;
        }

        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return;
        }

        const labels = Object.keys(distribution);
        const data = Object.values(distribution);

        // Define colors based on grade ranges.
        const backgroundColors = [
            'rgba(220, 53, 69, 0.7)',   // 0-4: Red (Insuffisant)
            'rgba(255, 193, 7, 0.7)',   // 5-8: Yellow (Mediocre)
            'rgba(23, 162, 184, 0.7)',  // 9-12: Cyan (Passable)
            'rgba(40, 167, 69, 0.7)',   // 13-16: Green (Bien)
            'rgba(0, 123, 255, 0.7)'    // 17-20: Blue (Excellent)
        ];

        const borderColors = [
            'rgb(220, 53, 69)',
            'rgb(255, 193, 7)',
            'rgb(23, 162, 184)',
            'rgb(40, 167, 69)',
            'rgb(0, 123, 255)'
        ];

        gradeChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: M.util.get_string('dashboard_students', 'mod_redaction') || 'Students',
                    data: data,
                    backgroundColor: backgroundColors,
                    borderColor: borderColors,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 200,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: true,
                        text: M.util.get_string('dashboard_grade_distribution', 'mod_redaction') || 'Grade Distribution'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    };

    /**
     * Refresh the AI summary.
     *
     * @param {HTMLElement} button The refresh button element
     */
    const refreshSummary = function(button) {
        const $button = $(button);
        const $icon = $button.find('i');
        const originalHtml = $button.html();

        // Disable button and show loading state.
        $button.prop('disabled', true);
        $icon.addClass('fa-spin');

        // Call the web service.
        Ajax.call([{
            methodname: 'mod_redaction_generate_ai_summary',
            args: {
                cmid: cmid,
                force: true
            }
        }])[0].then(function(response) {
            if (response.success) {
                // Reload the page to show updated summary.
                window.location.reload();
            } else {
                Notification.addNotification({
                    message: response.message,
                    type: 'warning'
                });
                // Restore button state.
                $button.prop('disabled', false);
                $button.html(originalHtml);
            }
        }).catch(function(error) {
            Notification.exception(error);
            // Restore button state.
            $button.prop('disabled', false);
            $button.html(originalHtml);
        });
    };

    /**
     * Show a toast notification.
     *
     * @param {string} message Message to display
     * @param {string} type Notification type (success, warning, error, info)
     */
    const showToast = function(message, type) {
        Notification.addNotification({
            message: message,
            type: type || 'info'
        });
    };

    /**
     * Initialize the dashboard module.
     *
     * @param {number} courseModuleId Course module ID
     * @param {Object} gradeDistribution Grade distribution data
     */
    const init = function(courseModuleId, gradeDistribution) {
        cmid = courseModuleId;

        // Initialize grade distribution chart.
        if (gradeDistribution) {
            initGradeChart(gradeDistribution);
        }

        // Bind refresh button click handler.
        $(document).on('click', '#refreshSummaryBtn', function(e) {
            e.preventDefault();
            refreshSummary(this);
        });
    };

    return {
        init: init,
        refreshSummary: refreshSummary,
        showToast: showToast
    };
});
