// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Stub for the Test API button on the activity settings form.
 *
 * The button is wired but its handler is not implemented yet. This stub
 * exists so that the AMD loader has a valid define() target and does not
 * throw "No define call for mod_redaction/test_api", which previously
 * cascaded into other modules failing on the same page (notably the
 * autocomplete used for the standard groupings dropdown).
 *
 * @module     mod_redaction/test_api
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    return {
        init: function() {
            // Intentionally empty: Test API feature not yet implemented.
        },
    };
});
