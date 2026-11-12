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
 * Number of sentences of an answer, to warn a student who writes a list of words.
 *
 * Same count as langue::phrases() on the server; both are tested on tests/pure/phrases_cas.json.
 *
 * @module     mod_redaction/phrases
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    /** Words a segment needs to count as a sentence. */
    var MOTS_PAR_PHRASE = 3;

    /**
     * Number of words: letters or digits in a row.
     * @param {string} texte
     * @returns {number}
     */
    function mots(texte) {
        var trouves = texte.match(/[\p{L}\p{N}]+/gu);
        return trouves ? trouves.length : 0;
    }

    return {
        /**
         * Number of sentences: segments between end marks or line breaks with enough words, lists left out.
         * @param {string} texte Plain text of the answer.
         * @returns {number}
         */
        compter: function(texte) {
            var n = 0;
            (texte || '').split(/[.!?…]+|(?:\r\n|\r|\n)+/u).forEach(function(segment) {
                if (mots(segment) < MOTS_PAR_PHRASE) {
                    return;
                }
                var parties = segment.split(',');
                if (parties.length > 1 && Math.max.apply(null, parties.map(mots)) <= 2) {
                    return;
                }
                n++;
            });
            return n;
        },
    };
});
