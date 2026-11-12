// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Student screen of the assigned subjects mode: autosave, submit and refresh while the correction runs.
 *
 * @module     mod_redaction/sujets
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'core/notification', 'core/str', 'mod_redaction/phrases'], function(Ajax, Notification, Str, Phrases) {

    var SAVE_DELAY = 1500;
    var POLL_INTERVAL = 5000;
    var POLL_MAX = 120;
    var LIGHT_INTERVAL = 10000;
    var KEEPALIVE_URL = '/lib/ajax/service.php';

    var config = {};
    var strings = {};
    var timers = {};
    var inflight = [];
    var failed = {};
    var root = null;
    var submitting = false;

    /**
     * Show a short state next to the button.
     * @param {string} text
     */
    function setSaveState(text) {
        var el = root.querySelector('#mod_redaction-se-savestate');
        if (el) {
            el.textContent = text;
        }
    }

    /**
     * Show a message under the button (an empty text clears it).
     * @param {string} text
     */
    function setMessage(text) {
        var el = root.querySelector('#mod_redaction-se-message');
        if (el) {
            el.textContent = text;
        }
    }

    /**
     * Show the spinner next to the button, in view: the correction takes about a minute.
     */
    function showWaiting() {
        var el = root.querySelector('#mod_redaction-se-attente');
        var failed = root.querySelector('.mod_redaction-se-failed');
        if (failed) {
            failed.hidden = true;
        }
        if (el) {
            el.hidden = false;
            el.classList.add('mod_redaction-se-pending');
            if (el.scrollIntoView) {
                el.scrollIntoView({block: 'center'});
            }
        }
    }

    /**
     * Save the answer of one subject.
     * @param {HTMLTextAreaElement} area
     * @returns {Promise}
     */
    function save(area) {
        clearTimeout(timers[area.dataset.sujetid]);
        delete timers[area.dataset.sujetid];
        setSaveState(strings.saving);
        var call = Ajax.call([{
            methodname: 'mod_redaction_save_sujet_reponse',
            args: {
                cmid: config.cmid,
                sujetid: parseInt(area.dataset.sujetid, 10),
                contenu: area.value,
            },
        }])[0].then(function(result) {
            setSaveState(result.success ? strings.saved : strings.notsaved);
            if (result.success) {
                delete failed[area.dataset.sujetid];
            } else {
                failed[area.dataset.sujetid] = true;
                setMessage(result.message);
            }
            return result;
        }).catch(function(error) {
            failed[area.dataset.sujetid] = true;
            setSaveState(strings.notsaved);
            Notification.exception(error);
            return null;
        });
        inflight.push(call);
        call.then(function() {
            var index = inflight.indexOf(call);
            if (index >= 0) {
                inflight.splice(index, 1);
            }
            return null;
        });
        return call;
    }

    /**
     * Save what is waiting or failed, and wait until every save is finished.
     * @returns {Promise} Resolves to true when every answer is saved, false otherwise.
     */
    function flush() {
        root.querySelectorAll('.mod_redaction-se-reponse').forEach(function(area) {
            if (timers[area.dataset.sujetid] || failed[area.dataset.sujetid]) {
                save(area);
            }
        });
        var pending = inflight;
        inflight = [];
        return Promise.all(pending).then(function() {
            return Object.keys(failed).length === 0;
        });
    }

    /**
     * Reload when the correction is over, ask again otherwise.
     */
    function poll() {
        var count = 0;
        var timer = setInterval(function() {
            count++;
            if (count > POLL_MAX) {
                clearInterval(timer);
                window.location.reload();
                return;
            }
            Ajax.call([{
                methodname: 'mod_redaction_get_evaluation_status',
                args: {cmid: config.cmid, submissionid: config.submissionid},
            }])[0].then(function(data) {
                if (data.status === 'applied' || data.status === 'failed') {
                    clearInterval(timer);
                    window.location.reload();
                }
                return data;
            }).catch(function() {
                // A failed poll is not an error: ask again at the next tick.
                return null;
            });
        }, POLL_INTERVAL);
    }

    /**
     * Whether an answer holds typing the server may not have yet.
     * @param {HTMLTextAreaElement} area
     * @returns {boolean}
     */
    function isBusy(area) {
        var id = area.dataset.sujetid;
        return document.activeElement === area || !!timers[id] || !!failed[id];
    }

    /**
     * Take in what the partner has saved, without touching text being typed.
     * @param {Array} reponses Saved answers: sujetid and contenu
     */
    function mergeRemote(reponses) {
        reponses.forEach(function(remote) {
            var area = root.querySelector('.mod_redaction-se-reponse[data-sujetid="' + remote.sujetid + '"]');
            if (area && !area.readOnly && !isBusy(area) && area.value !== remote.contenu) {
                area.value = remote.contenu;
            }
        });
    }

    /**
     * Light polling while the page is open and no correction runs here: shows the partner's text and
     * reloads when a correction starts on the group.
     */
    function lightPoll() {
        setInterval(function() {
            if (document.visibilityState === 'hidden' || root.querySelector('.mod_redaction-se-pending')
                    || inflight.length || submitting) {
                return;
            }
            Ajax.call([{
                methodname: 'mod_redaction_get_sujets_etat',
                args: {cmid: config.cmid},
            }])[0].then(function(data) {
                if (data.pending && !submitting) {
                    // The partner submitted: show the correction in progress, once our own typing is saved.
                    flush().then(function() {
                        window.location.reload();
                        return null;
                    }).catch(Notification.exception);
                    return data;
                }
                mergeRemote(data.reponses);
                return data;
            }).catch(function() {
                // A failed poll is not an error: ask again at the next tick.
                return null;
            });
        }, LIGHT_INTERVAL);
    }

    /**
     * Send the answers still waiting when the page goes away. A normal call may be cut off by the exit,
     * so this one asks the browser to finish it (keepalive).
     */
    function flushOnExit() {
        root.querySelectorAll('.mod_redaction-se-reponse').forEach(function(area) {
            var id = area.dataset.sujetid;
            if (!timers[id] || area.readOnly) {
                return;
            }
            clearTimeout(timers[id]);
            delete timers[id];
            var url = M.cfg.wwwroot + KEEPALIVE_URL + '?sesskey=' + encodeURIComponent(M.cfg.sesskey) +
                '&info=mod_redaction_save_sujet_reponse';
            try {
                fetch(url, {
                    method: 'POST',
                    keepalive: true,
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify([{
                        index: 0,
                        methodname: 'mod_redaction_save_sujet_reponse',
                        args: {cmid: config.cmid, sujetid: parseInt(id, 10), contenu: area.value},
                    }]),
                });
            } catch (e) {
                // Nothing more can be done while the page is leaving.
                return;
            }
        });
    }

    /**
     * Submit the saved answers to the correction.
     * @param {HTMLButtonElement} button
     */
    function submit(button) {
        button.disabled = true;
        submitting = true;
        setMessage('');
        flush().then(function(saved) {
            if (!saved) {
                // Never spend an attempt on text the server does not have.
                return {success: false, notsaved: true, message: strings.notsaved};
            }
            return Ajax.call([{
                methodname: 'mod_redaction_submit_sujets',
                args: {cmid: config.cmid},
            }])[0];
        }).then(function(result) {
            if (!result.success) {
                submitting = false;
                button.disabled = false;
                setMessage(result.message);
                return;
            }
            root.querySelectorAll('.mod_redaction-se-reponse').forEach(function(area) {
                area.readOnly = true;
            });
            button.textContent = strings.inprogress;
            setMessage('');
            showWaiting();
            poll();
        }).catch(function(error) {
            submitting = false;
            button.disabled = false;
            Notification.exception(error);
        });
    }

    /**
     * Numbers of the subjects whose answer has fewer sentences than the activity expects (a list of words, a word or two).
     * @returns {number[]}
     */
    function peuDePhrases() {
        var attendues = config.phrases || 0;
        var numeros = [];
        root.querySelectorAll('.mod_redaction-se-reponse').forEach(function(area, index) {
            if (attendues > 0 && Phrases.compter(area.value) < attendues) {
                numeros.push(index + 1);
            }
        });
        return numeros;
    }

    /**
     * Submit, after a warning when an answer is not written in sentences: the student may send all the same.
     * @param {HTMLButtonElement} button
     */
    function verifierPuisSoumettre(button) {
        var numeros = peuDePhrases();
        if (!numeros.length) {
            submit(button);
            return;
        }
        Str.get_strings([
            {key: 'sujets_phrases_alerte_titre', component: 'mod_redaction'},
            {key: 'sujets_phrases_alerte', component: 'mod_redaction', param: {sujets: numeros.join(', '), phrases: config.phrases}},
            {key: 'sujets_phrases_envoyer', component: 'mod_redaction'},
        ]).then(function(texte) {
            return Notification.saveCancelPromise(texte[0], texte[1], texte[2]).then(function() {
                submit(button);
                return null;
            }).catch(function() {
                // The student chose to go back and write sentences: nothing is sent.
            });
        }).catch(Notification.exception);
    }

    /**
     * Wire the answers, the button and the page exit once the strings are known.
     */
    function bind() {
        root.querySelectorAll('.mod_redaction-se-reponse').forEach(function(area) {
            area.addEventListener('input', function() {
                clearTimeout(timers[area.dataset.sujetid]);
                timers[area.dataset.sujetid] = setTimeout(function() {
                    save(area);
                }, SAVE_DELAY);
            });
        });

        var button = root.querySelector('#mod_redaction-se-submit');
        if (button) {
            button.addEventListener('click', function() {
                verifierPuisSoumettre(button);
            });
        }

        // Keep the last seconds of typing when the page is left or hidden.
        window.addEventListener('pagehide', flushOnExit);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                flush();
            }
        });
        lightPoll();

        // A correction already running when the page opens.
        if (root.querySelector('.mod_redaction-se-pending')) {
            showWaiting();
            poll();
        }
    }

    return {
        /**
         * Initialise the screen.
         * @param {object} params cmid, submissionid and phrases (sentences expected in each answer, 0 for no warning)
         */
        init: function(params) {
            config = params;
            root = document.getElementById('mod_redaction-sujets-eleve');
            if (!root) {
                return;
            }

            Str.get_strings([
                {key: 'sujets_enregistrement', component: 'mod_redaction'},
                {key: 'sujets_enregistre', component: 'mod_redaction'},
                {key: 'sujets_non_enregistre', component: 'mod_redaction'},
                {key: 'sujets_correction_en_cours', component: 'mod_redaction'},
            ]).then(function(loaded) {
                strings = {saving: loaded[0], saved: loaded[1], notsaved: loaded[2], inprogress: loaded[3]};
                bind();
                return strings;
            }).catch(Notification.exception);
        },
    };
});
