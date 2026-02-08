// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Autosave functionality for redaction.
 *
 * @module     mod_redaction/autosave
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification'], function ($, Ajax, Notification) {

    var autosave = {
        cmid: 0,
        page: '',
        groupid: 0,
        interval: 30000,
        debounceDelay: 2000,
        debounceTimer: null,
        timer: null,
        formSelector: null,
        statusElement: null,
        isDirty: false,
        strings: {},

        /**
         * Initialize autosave.
         *
         * @param {Object} config Configuration object
         */
        init: function (config) {
            this.cmid = config.cmid;
            this.page = config.page || '';
            this.groupid = config.groupid || 0;
            this.interval = config.interval || 30000;
            this.formSelector = config.formSelector || 'form';
            this.strings = config.strings || {};

            // Create status indicator
            this.createStatusIndicator();

            // Monitor form changes
            this.monitorChanges();

            // Start autosave timer
            this.startTimer();

            // Save on page unload
            this.setupBeforeUnload();
        },

        /**
         * Create visual status indicator.
         */
        createStatusIndicator: function () {
            $('#autosave-status').remove();

            var statusHtml = '<div id="autosave-status" style="' +
                'position: fixed; top: 60px; right: 20px; z-index: 9999; ' +
                'padding: 10px 20px; border-radius: 8px; ' +
                'background: #f8f9fa; border: 2px solid #dee2e6; ' +
                'box-shadow: 0 4px 12px rgba(0,0,0,0.1); ' +
                'display: none; transition: all 0.3s; font-family: -apple-system, system-ui, BlinkMacSystemFont, sans-serif;">' +
                '<span id="autosave-icon" style="margin-right: 8px;">💾</span> ' +
                '<span id="autosave-text">' + (this.strings.saving || 'Saving...') + '</span>' +
                '</div>';

            $('body').append(statusHtml);
            this.statusElement = $('#autosave-status');
        },

        /**
         * Monitor form changes.
         */
        monitorChanges: function () {
            var self = this;

            $(document).on('change input', this.formSelector + ' input, ' +
                this.formSelector + ' textarea, ' +
                this.formSelector + ' select', function () {
                    self.isDirty = true;

                    clearTimeout(self.debounceTimer);
                    self.debounceTimer = setTimeout(function () {
                        self.save();
                    }, self.debounceDelay);
                });
        },

        /**
         * Start autosave timer.
         */
        startTimer: function () {
            var self = this;

            this.timer = setInterval(function () {
                if (self.isDirty) {
                    self.save();
                }
            }, this.interval);
        },

        /**
         * Stop autosave timer.
         */
        stopTimer: function () {
            if (this.timer) {
                clearInterval(this.timer);
                this.timer = null;
            }
        },

        /**
         * Show status message.
         *
         * @param {String} type Status type (saving, saved, error)
         */
        showStatus: function (type) {
            var icon = '💾';
            var text = this.strings.saving || 'Saving...';
            var bgColor = '#f8f9fa';
            var borderColor = '#dee2e6';
            var textColor = '#212529';

            switch (type) {
                case 'saving':
                    icon = '⏳';
                    text = this.strings.saving || 'Saving...';
                    bgColor = '#fff3cd';
                    borderColor = '#ffeeba';
                    textColor = '#856404';
                    break;
                case 'saved':
                    icon = '✓';
                    text = this.strings.saved || 'Saved';
                    bgColor = '#d4edda';
                    borderColor = '#c3e6cb';
                    textColor = '#155724';
                    break;
                case 'error':
                    icon = '⚠️';
                    text = this.strings.error || 'Save error';
                    bgColor = '#f8d7da';
                    borderColor = '#f5c6cb';
                    textColor = '#721c24';
                    break;
            }

            $('#autosave-icon').text(icon);
            $('#autosave-text').text(text);
            this.statusElement.css({
                'background': bgColor,
                'border-color': borderColor,
                'color': textColor,
                'display': 'flex',
                'align-items': 'center'
            });

            if (type === 'saved') {
                setTimeout(function () {
                    $('#autosave-status').fadeOut();
                }, 3000);
            }
        },

        /**
         * Save form data.
         */
        save: function () {
            var self = this;

            if (!this.page) {
                return;
            }

            clearTimeout(this.debounceTimer);

            this.showStatus('saving');

            // Collect form data
            var formData = {};
            $(this.formSelector).find('input, textarea, select').each(function () {
                var $field = $(this);
                var name = $field.attr('name');
                var type = $field.attr('type');

                if (!name || name === 'sesskey') {
                    return;
                }

                if (type === 'checkbox') {
                    formData[name] = $field.is(':checked') ? 1 : 0;
                } else if (type === 'radio') {
                    if ($field.is(':checked')) {
                        formData[name] = $field.val();
                    }
                } else {
                    formData[name] = $field.val();
                }
            });

            // Build request data
            var requestData = {
                cmid: this.cmid,
                page: this.page,
                groupid: this.groupid,
                data: JSON.stringify(formData),
                sesskey: M.cfg.sesskey
            };

            // Make AJAX request
            $.ajax({
                url: M.cfg.wwwroot + '/mod/redaction/ajax/autosave.php',
                method: 'POST',
                data: requestData,
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        self.isDirty = false;
                        self.showStatus('saved');
                    } else {
                        self.showStatus('error');
                        if (response.message) {
                            console.error('Autosave error:', response.message);
                        }
                    }
                },
                error: function (xhr, status, error) {
                    self.showStatus('error');
                    console.error('Autosave connection error:', status, error);
                }
            });
        },

        /**
         * Setup before unload warning.
         */
        setupBeforeUnload: function () {
            var self = this;

            $(window).on('beforeunload', function () {
                if (self.isDirty) {
                    self.save();
                    return self.strings.unsaved || 'You have unsaved changes.';
                }
            });
        }
    };

    return autosave;
});
