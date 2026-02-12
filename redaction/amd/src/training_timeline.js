// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Training timeline interactive component for the grading sidebar.
 *
 * @module     mod_redaction/training_timeline
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'], function($) {

    var config = {};
    var attempts = [];
    var selectedIndex = -1;
    var isDragging = false;

    return {

        /**
         * Initialize the training timeline.
         *
         * @param {Object} params Configuration from PHP.
         * @param {Array} params.attempts Array of attempt data objects.
         * @param {Object} params.strings Localized strings.
         */
        init: function(params) {
            config = params;
            attempts = params.attempts || [];

            var container = document.getElementById('training-timeline');
            if (!container || attempts.length === 0) {
                return;
            }

            // Activate JS mode: hides fallback, shows interactive timeline.
            container.classList.add('mod_redaction-js-active');

            // Compress markers when many attempts.
            if (attempts.length > 10) {
                container.classList.add('mod_redaction-many-attempts');
            }

            this.renderSparkline();
            this.renderTrend();
            this.bindMarkerClicks();
            this.bindDrag();
            this.bindKeyboard();

            // Auto-select the most recent attempt.
            this.selectAttempt(attempts.length - 1);
        },

        /**
         * Select and display details for a specific attempt.
         *
         * @param {number} index Zero-based index of the attempt.
         */
        selectAttempt: function(index) {
            if (index < 0 || index >= attempts.length) {
                return;
            }
            selectedIndex = index;
            var attempt = attempts[index];

            // Update active marker styling.
            var markers = document.querySelectorAll('.mod_redaction-timeline-marker[data-attempt-index]');
            markers.forEach(function(m) {
                m.classList.remove('mod_redaction-active');
            });
            var activeMarker = document.querySelector(
                '.mod_redaction-timeline-marker[data-attempt-index="' + index + '"]'
            );
            if (activeMarker) {
                activeMarker.classList.add('mod_redaction-active');
            }

            // Move cursor to the marker position.
            var cursor = document.getElementById('timeline-cursor');
            if (cursor) {
                cursor.style.left = attempt.positionpercent + '%';
            }

            // Update ARIA.
            var track = document.getElementById('timeline-track');
            if (track) {
                track.setAttribute('aria-valuenow', attempt.num);
                track.setAttribute('aria-valuetext',
                    config.strings.attempt.replace('{$a}', attempt.num) +
                    ' - ' + attempt.gradestr);
            }

            // Show and populate detail panel.
            var panel = document.getElementById('timeline-detail');
            if (!panel) {
                return;
            }
            panel.style.display = 'block';

            // Attempt number.
            var numEl = document.getElementById('detail-attempt-num');
            if (numEl) {
                numEl.textContent = config.strings.attempt.replace('{$a}', attempt.num);
            }

            // Date.
            var dateEl = document.getElementById('detail-date');
            if (dateEl) {
                dateEl.textContent = attempt.datestr;
            }

            // Grade badge.
            var gradeEl = document.getElementById('detail-grade');
            if (gradeEl) {
                gradeEl.textContent = attempt.gradestr;
                gradeEl.className = 'mod_redaction-detail-grade mod_redaction-ai-level-' + attempt.gradelevel;
            }

            // Criteria mini bars.
            var criteriaContainer = document.getElementById('detail-criteria');
            if (criteriaContainer) {
                criteriaContainer.innerHTML = '';
                if (attempt.criteria && attempt.criteria.length > 0) {
                    attempt.criteria.forEach(function(c) {
                        var row = document.createElement('div');
                        row.className = 'mod_redaction-detail-criterion-row';
                        row.innerHTML =
                            '<span class="mod_redaction-detail-criterion-name" title="' +
                                this.escapeHtml(c.name) + '">' +
                                this.escapeHtml(c.name) + '</span>' +
                            '<div class="mod_redaction-detail-criterion-bar">' +
                                '<div class="mod_redaction-detail-criterion-fill mod_redaction-' +
                                    this.escapeHtml(c.scoreclass) +
                                    '" style="width:' + c.percentage + '%"></div>' +
                            '</div>' +
                            '<span class="mod_redaction-detail-criterion-score">' +
                                c.score + '/' + c.max + '</span>';
                        criteriaContainer.appendChild(row);
                    }.bind(this));
                }
            }

            // Feedback.
            var feedbackEl = document.getElementById('detail-feedback');
            if (feedbackEl) {
                feedbackEl.textContent = attempt.shortfeedback || '';
                feedbackEl.style.display = attempt.shortfeedback ? 'block' : 'none';
            }
        },

        /**
         * Render the sparkline SVG polyline from completed grades.
         */
        renderSparkline: function() {
            var completedAttempts = attempts.filter(function(a) {
                return a.status === 'completed' && a.grade !== null;
            });

            var sparkEl = document.getElementById('timeline-sparkline');
            if (!sparkEl) {
                return;
            }

            if (completedAttempts.length < 2) {
                sparkEl.style.display = 'none';
                return;
            }

            var svgWidth = 300;
            var svgHeight = 40;
            var padding = 4;

            var points = completedAttempts.map(function(a) {
                var x = (a.positionpercent / 100) * svgWidth;
                // Grade 0 -> bottom, 20 -> top.
                var y = svgHeight - padding -
                    ((a.grade / 20) * (svgHeight - 2 * padding));
                return x.toFixed(1) + ',' + y.toFixed(1);
            });

            var polyline = sparkEl.querySelector('.mod_redaction-sparkline-line');
            if (polyline) {
                polyline.setAttribute('points', points.join(' '));
            }
        },

        /**
         * Render the trend indicator (up/down/flat arrow + summary).
         */
        renderTrend: function() {
            var completedAttempts = attempts.filter(function(a) {
                return a.status === 'completed' && a.grade !== null;
            });

            var trendContainer = document.getElementById('timeline-trend');
            if (!trendContainer) {
                return;
            }

            if (completedAttempts.length < 1) {
                trendContainer.style.display = 'none';
                return;
            }

            var arrowEl = trendContainer.querySelector('.mod_redaction-trend-arrow');
            var summaryEl = trendContainer.querySelector('.mod_redaction-trend-summary');

            var first = completedAttempts[0].grade;
            var last = completedAttempts[completedAttempts.length - 1].grade;

            if (completedAttempts.length === 1) {
                if (arrowEl) {
                    arrowEl.className = 'mod_redaction-trend-arrow mod_redaction-trend-flat';
                }
                if (summaryEl) {
                    summaryEl.textContent = last.toFixed(1) + '/20';
                }
            } else {
                var diff = last - first;
                var direction = diff > 0.5 ? 'up' : (diff < -0.5 ? 'down' : 'flat');

                if (arrowEl) {
                    arrowEl.className = 'mod_redaction-trend-arrow mod_redaction-trend-' + direction;
                }
                if (summaryEl) {
                    var sign = diff >= 0 ? '+' : '';
                    summaryEl.textContent = first.toFixed(1) + ' \u2192 ' +
                        last.toFixed(1) + ' (' + sign + diff.toFixed(1) + ')';
                }
            }
        },

        /**
         * Bind click events on timeline markers.
         */
        bindMarkerClicks: function() {
            var self = this;
            var markers = document.querySelectorAll('.mod_redaction-timeline-marker[data-attempt-index]');
            markers.forEach(function(marker) {
                marker.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var idx = parseInt(this.dataset.attemptIndex, 10);
                    self.selectAttempt(idx);
                });
            });
        },

        /**
         * Bind drag interactions on the timeline track.
         */
        bindDrag: function() {
            var self = this;
            var track = document.getElementById('timeline-track');
            var cursor = document.getElementById('timeline-cursor');
            if (!track || !cursor) {
                return;
            }

            /**
             * Get percentage position from mouse/touch event.
             * @param {Event} e
             * @returns {number}
             */
            function getPercentFromEvent(e) {
                var rect = track.getBoundingClientRect();
                var clientX = e.touches ? e.touches[0].clientX : e.clientX;
                var x = clientX - rect.left;
                return Math.max(0, Math.min(100, (x / rect.width) * 100));
            }

            /**
             * Find the nearest attempt index to a given percentage.
             * @param {number} percent
             * @returns {number}
             */
            function snapToNearest(percent) {
                var minDist = Infinity;
                var bestIndex = -1;
                attempts.forEach(function(a, i) {
                    var dist = Math.abs(a.positionpercent - percent);
                    if (dist < minDist) {
                        minDist = dist;
                        bestIndex = i;
                    }
                });
                return bestIndex;
            }

            function startDrag(e) {
                // Don't interfere with marker clicks.
                if (e.target.closest('.mod_redaction-timeline-marker[data-attempt-index]')) {
                    return;
                }
                e.preventDefault();
                isDragging = true;
                cursor.classList.add('mod_redaction-dragging');

                var pct = getPercentFromEvent(e);
                cursor.style.left = pct + '%';

                document.addEventListener('mousemove', onDrag);
                document.addEventListener('touchmove', onDrag, {passive: false});
                document.addEventListener('mouseup', endDrag);
                document.addEventListener('touchend', endDrag);
            }

            function onDrag(e) {
                if (!isDragging) {
                    return;
                }
                e.preventDefault();
                var pct = getPercentFromEvent(e);
                cursor.style.left = pct + '%';
            }

            function endDrag() {
                if (!isDragging) {
                    return;
                }
                isDragging = false;
                cursor.classList.remove('mod_redaction-dragging');

                var pct = parseFloat(cursor.style.left);
                var bestIndex = snapToNearest(pct);
                if (bestIndex >= 0) {
                    self.selectAttempt(bestIndex);
                }

                document.removeEventListener('mousemove', onDrag);
                document.removeEventListener('touchmove', onDrag);
                document.removeEventListener('mouseup', endDrag);
                document.removeEventListener('touchend', endDrag);
            }

            // Start drag on track.
            track.addEventListener('mousedown', startDrag);
            track.addEventListener('touchstart', startDrag, {passive: false});

            // Click on track background (not on a marker).
            track.addEventListener('click', function(e) {
                if (e.target.closest('.mod_redaction-timeline-marker[data-attempt-index]') || isDragging) {
                    return;
                }
                var pct = getPercentFromEvent(e);
                var bestIndex = snapToNearest(pct);
                if (bestIndex >= 0) {
                    self.selectAttempt(bestIndex);
                }
            });
        },

        /**
         * Bind keyboard navigation on the timeline track.
         */
        bindKeyboard: function() {
            var self = this;
            var track = document.getElementById('timeline-track');
            if (!track) {
                return;
            }

            track.addEventListener('keydown', function(e) {
                if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (selectedIndex < attempts.length - 1) {
                        self.selectAttempt(selectedIndex + 1);
                    }
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (selectedIndex > 0) {
                        self.selectAttempt(selectedIndex - 1);
                    }
                } else if (e.key === 'Home') {
                    e.preventDefault();
                    self.selectAttempt(0);
                } else if (e.key === 'End') {
                    e.preventDefault();
                    self.selectAttempt(attempts.length - 1);
                }
            });
        },

        /**
         * Escape HTML entities.
         * @param {string} str
         * @returns {string}
         */
        escapeHtml: function(str) {
            if (!str) {
                return '';
            }
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    };
});
