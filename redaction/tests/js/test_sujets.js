// Local test of amd/src/sujets.js with stubbed Moodle modules (no Moodle, no network).
//
// Usage: node tests/js/test_sujets.js [path/to/sujets.js]
// Needs the global puppeteer (npm root -g). The fixtures are the real rendering of the Mustache template.
// It proves the logic of the script against stubs; it does not prove the real AJAX calls nor Moodle's AMD loading.
'use strict';

const fs = require('fs');
const path = require('path');
const {execSync} = require('child_process');
const puppeteer = require(path.join(execSync('npm root -g').toString().trim(), 'puppeteer'));

const source = fs.readFileSync(path.resolve(process.argv[2] || path.join(__dirname, '../../amd/src/sujets.js')), 'utf8');
const fixture = (name) => fs.readFileSync(path.join(__dirname, 'fixtures', name), 'utf8');

// Everything the script needs from Moodle, plus a fake clock so that no test waits for real time.
const harness = `
const realSetTimeout = window.setTimeout.bind(window);
const settle = async () => { for (let i = 0; i < 5; i++) { await new Promise((r) => realSetTimeout(r, 0)); } };
const clock = {now: 0, id: 0, t: {}};
window.setTimeout = (f, ms) => { const id = ++clock.id; clock.t[id] = {f, at: clock.now + ms, every: 0}; return id; };
window.setInterval = (f, ms) => { const id = ++clock.id; clock.t[id] = {f, at: clock.now + ms, every: ms}; return id; };
window.clearTimeout = window.clearInterval = (id) => { delete clock.t[id]; };
window.activeTimers = () => Object.keys(clock.t).length;
window.tick = async (ms) => {
    const end = clock.now + ms;
    for (;;) {
        const due = Object.entries(clock.t).filter(([, t]) => t.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
        if (!due) { break; }
        const [id, t] = due;
        clock.now = t.at;
        if (t.every) { t.at += t.every; } else { delete clock.t[id]; }
        t.f();
        await settle();
    }
    clock.now = end;
    await settle();
};
window.settle = settle;
window.calls = [];
window.handlers = {};
window.notifications = [];
window.stringsGate = null;
const STR = ['Enregistrement...', 'Enregistré', 'Non enregistré', 'Correction en cours'];
const modules = {
    'core/ajax': {call: (reqs) => reqs.map((r) => {
        window.calls.push({methodname: r.methodname, args: r.args});
        return Promise.resolve().then(() => window.handlers[r.methodname](r.args));
    })},
    'core/notification': {exception: (e) => window.notifications.push(String(e && e.message || e)),
        addNotification: (n) => window.notifications.push(n.message)},
    'core/str': {get_strings: () => (window.stringsGate ? window.stringsGate.then(() => STR) : Promise.resolve(STR))},
};
window.define = (deps, factory) => { window.sujetsModule = factory(...deps.map((d) => modules[d])); };
window.M = {cfg: {wwwroot: 'http://sujets.test', sesskey: 'cle'}};
window.fetches = [];
window.fetch = (url, opts) => { window.fetches.push({url, opts}); return Promise.resolve({}); };
window.handlers.mod_redaction_get_sujets_etat = () => ({pending: false, reponses: []});
window.errors = [];
window.addEventListener('error', (e) => window.errors.push(e.message));
window.addEventListener('unhandledrejection', (e) => window.errors.push('rejection: ' + (e.reason && e.reason.message || e.reason)));
window.$ = (s) => document.querySelector(s);
window.type = (i, text) => { const a = document.querySelectorAll('.mod_redaction-se-reponse')[i]; a.value = text; a.dispatchEvent(new Event('input')); };
window.calls_of = (name) => window.calls.filter((c) => c.methodname === name);
window.ok = (v) => ({success: true, message: 'ok', evaluationid: 7, status: v});
`;

const results = [];
let failures = 0;
const check = (label, ok, detail) => {
    results.push((ok ? 'PASS  ' : 'FAIL  ') + label + (ok ? '' : '  -> ' + JSON.stringify(detail)));
    if (!ok) { failures++; }
};

(async () => {
    const browser = await puppeteer.launch({headless: 'new'});
    const consoleErrors = [];

    /** Opens a page with the fixture, the harness and the real script, without calling init. */
    const open = async (name) => {
        const page = await browser.newPage();
        page.on('pageerror', (e) => consoleErrors.push(String(e)));
        page.on('console', (m) => { if (m.type() === 'error') { consoleErrors.push(m.text()); } });
        // A real (intercepted) URL, so that a reload of the page is a request we can count.
        await page.setRequestInterception(true);
        page.navigations = 0;
        page.on('request', (req) => {
            if (req.resourceType() === 'document') {
                page.navigations++;
                req.respond({status: 200, contentType: 'text/html', body: '<!doctype html><html><body>' + fixture(name) + '</body></html>'});
            } else {
                req.respond({status: 204, body: ''});
            }
        });
        await page.goto('http://sujets.test/');
        await page.addScriptTag({content: harness});
        await page.addScriptTag({content: source});
        return page;
    };
    const init = (page) => page.evaluate(async () => {
        window.sujetsModule.init({cmid: 11, submissionid: 22});
        await window.settle();
    });

    // 1. Debounced save with the right arguments.
    let page = await open('ecran_normal.html');
    await page.evaluate(() => { window.handlers.mod_redaction_save_sujet_reponse = () => window.ok(); });
    await init(page);
    let r = await page.evaluate(async () => {
        const id = parseInt(document.querySelector('.mod_redaction-se-reponse').dataset.sujetid, 10);
        type(0, 'abc');
        await tick(1000);
        const before = calls_of('mod_redaction_save_sujet_reponse').length;
        type(0, 'abcd');
        await tick(1000);
        const mid = calls_of('mod_redaction_save_sujet_reponse').length;
        await tick(600);
        return {id, before, mid, calls: calls_of('mod_redaction_save_sujet_reponse'), state: $('#mod_redaction-se-savestate').textContent};
    });
    check('typing: no save before the debounce delay, the delay restarts on each key', r.before === 0 && r.mid === 0, r);
    check('typing: one save with cmid, integer sujetid and the latest text', r.calls.length === 1
        && JSON.stringify(r.calls[0].args) === JSON.stringify({cmid: 11, sujetid: r.id, contenu: 'abcd'}), r.calls);
    check('typing: the state says saved', r.state === 'Enregistré', r.state);
    await page.close();

    // 2. pagehide and visibilitychange flush the waiting text.
    page = await open('ecran_normal.html');
    await page.evaluate(() => { window.handlers.mod_redaction_save_sujet_reponse = () => window.ok(); });
    await init(page);
    r = await page.evaluate(async () => {
        type(1, 'quitte');
        window.dispatchEvent(new Event('pagehide'));
        await settle();
        const a = calls_of('mod_redaction_save_sujet_reponse').map((c) => c.args.contenu);
        type(0, 'cache');
        Object.defineProperty(document, 'visibilityState', {value: 'hidden', configurable: true});
        document.dispatchEvent(new Event('visibilitychange'));
        await settle();
        const b = calls_of('mod_redaction_save_sujet_reponse').map((c) => c.args.contenu);
        await tick(5000);
        return {a, b, after: calls_of('mod_redaction_save_sujet_reponse').length, fetches: window.fetches.map((f) => ({url: f.url, keepalive: f.opts.keepalive, body: JSON.parse(f.opts.body)}))};
    });
    check('pagehide: the waiting text leaves by a keepalive request, with the session key and the service',
        r.a.length === 0 && r.fetches.length === 1 && r.fetches[0].keepalive === true
        && r.fetches[0].url === 'http://sujets.test/lib/ajax/service.php?sesskey=cle&info=mod_redaction_save_sujet_reponse'
        && r.fetches[0].body[0].methodname === 'mod_redaction_save_sujet_reponse'
        && r.fetches[0].body[0].args.contenu === 'quitte' && r.fetches[0].body[0].args.cmid === 11, r);
    check('visibilitychange (hidden): the waiting text is saved at once, not twice', JSON.stringify(r.b) === '["cache"]' && r.after === 1, r);
    await page.close();

    // 3. A failed save blocks the submission (refused answer, then network error).
    for (const mode of ['refused', 'error']) {
        page = await open('ecran_normal.html');
        await page.evaluate((m) => {
            window.handlers.mod_redaction_save_sujet_reponse = () => {
                if (m === 'error') { throw new Error('reseau'); }
                return {success: false, message: 'refuse'};
            };
            window.handlers.mod_redaction_submit_sujets = () => window.ok();
        }, mode);
        await init(page);
        r = await page.evaluate(async () => {
            type(0, 'texte');
            $('#mod_redaction-se-submit').click();
            await settle();
            return {
                submits: calls_of('mod_redaction_submit_sujets').length,
                disabled: $('#mod_redaction-se-submit').disabled,
                message: $('#mod_redaction-se-message').textContent,
                state: $('#mod_redaction-se-savestate').textContent,
            };
        });
        check(`failed save (${mode}): no submit call, no attempt spent`, r.submits === 0, r);
        check(`failed save (${mode}): button usable again, "Non enregistré" shown`, !r.disabled && r.message === 'Non enregistré' && r.state === 'Non enregistré', r);
        // The next click retries the failed save, then submits once the save works.
        r = await page.evaluate(async () => {
            window.handlers.mod_redaction_save_sujet_reponse = () => window.ok();
            $('#mod_redaction-se-submit').click();
            await settle();
            return {saves: calls_of('mod_redaction_save_sujet_reponse').length, submits: calls_of('mod_redaction_submit_sujets').length};
        });
        check(`failed save (${mode}): after recovery the failed text is saved again and the submit goes through`, r.submits === 1 && r.saves >= 2, r);
        await page.close();
    }

    // 4. The server refuses an empty answer: the button stays usable, nothing polls.
    page = await open('ecran_normal.html');
    await page.evaluate(() => {
        window.handlers.mod_redaction_submit_sujets = () => ({success: false, message: 'Réponds à tous les sujets avant de soumettre.', evaluationid: 0});
    });
    await init(page);
    r = await page.evaluate(async () => {
        $('#mod_redaction-se-submit').click();
        await settle();
        const res = {submits: calls_of('mod_redaction_submit_sujets').length, disabled: $('#mod_redaction-se-submit').disabled,
            message: $('#mod_redaction-se-message').textContent, label: $('#mod_redaction-se-submit').textContent.trim()};
        await tick(20000);
        res.polls = calls_of('mod_redaction_get_evaluation_status').length;
        return res;
    });
    check('empty answer: refusal message shown, button usable, label unchanged, no polling', r.submits === 1 && !r.disabled
        && r.message.indexOf('Réponds') === 0 && r.label === 'Soumettre' && r.polls === 0, r);
    await page.close();

    // 5. Successful submit: wording, read only, polling with the right arguments, stop on applied / failed.
    for (const final of ['applied', 'failed']) {
        page = await open('ecran_normal.html');
        await page.evaluate(() => {
            window.handlers.mod_redaction_save_sujet_reponse = () => window.ok();
            window.handlers.mod_redaction_submit_sujets = () => window.ok();
            window.statusNow = 'pending';
            window.handlers.mod_redaction_get_evaluation_status = () => ({status: window.statusNow});
        });
        await init(page);
        r = await page.evaluate(async () => {
            type(0, 'a');
            type(1, 'b');
            $('#mod_redaction-se-submit').click();
            await settle();
            const res = {label: $('#mod_redaction-se-submit').textContent.trim(), disabled: $('#mod_redaction-se-submit').disabled,
                readonly: [...document.querySelectorAll('.mod_redaction-se-reponse')].every((a) => a.readOnly),
                saves: calls_of('mod_redaction_save_sujet_reponse').length, submits: calls_of('mod_redaction_submit_sujets').length,
                polls0: calls_of('mod_redaction_get_evaluation_status').length};
            await tick(5000);
            await tick(5000);
            res.pollsPending = calls_of('mod_redaction_get_evaluation_status').length;
            res.timersWhilePending = activeTimers();
            res.pollArgs = calls_of('mod_redaction_get_evaluation_status')[0].args;
            return res;
        });
        check('submit: both answers saved first, one submit, "Correction en cours", button disabled, answers read only',
            r.saves === 2 && r.submits === 1 && r.label === 'Correction en cours' && r.disabled && r.readonly, r);
        check('submit: nothing polled at once, then one poll per 5 s with cmid and submissionid, still polling while pending',
            r.polls0 === 0 && r.pollsPending === 2 && r.timersWhilePending === 2
            && JSON.stringify(r.pollArgs) === JSON.stringify({cmid: 11, submissionid: 22}), r);
        const before = page.navigations;
        r = await page.evaluate(async (f) => {
            window.statusNow = f;
            await tick(5000);
            const n = calls_of('mod_redaction_get_evaluation_status').length;
            return {n, timers: activeTimers()};
        }, final).catch((e) => ({navigated: String(e.message).includes('context') || String(e.message).includes('detached')}));
        await new Promise((res) => setTimeout(res, 300));
        check(`polling ends on "${final}": the polling timer is cleared and the page reloads once`,
            (r.timers === 1 || r.navigated === true) && page.navigations === before + 1, {r, before, after: page.navigations});
        await page.close();
    }

    // 5b. Late failures of the poll itself are ignored; the polling goes on.
    page = await open('ecran_normal.html');
    await page.evaluate(() => {
        window.handlers.mod_redaction_save_sujet_reponse = () => window.ok();
        window.handlers.mod_redaction_submit_sujets = () => window.ok();
        window.handlers.mod_redaction_get_evaluation_status = () => { throw new Error('reseau'); };
    });
    await init(page);
    r = await page.evaluate(async () => {
        type(0, 'a');
        $('#mod_redaction-se-submit').click();
        await settle();
        await tick(15000);
        return {polls: calls_of('mod_redaction_get_evaluation_status').length, timers: activeTimers(), notifications: notifications.length};
    });
    check('a failing poll is ignored: still polling, no alert', r.polls === 3 && r.timers === 2 && r.notifications === 0, r);
    await page.close();

    // 6. A correction already running when the page opens polls without any click.
    page = await open('ecran_en_cours.html');
    await page.evaluate(() => {
        window.handlers.mod_redaction_get_evaluation_status = () => ({status: 'processing'});
    });
    await init(page);
    r = await page.evaluate(async () => {
        const before = calls_of('mod_redaction_get_evaluation_status').length;
        await tick(10000);
        return {before, after: calls_of('mod_redaction_get_evaluation_status').length, label: $('#mod_redaction-se-submit').textContent.trim(),
            disabled: $('#mod_redaction-se-submit').disabled};
    });
    check('page opened during a correction: polls by itself, button disabled "Correction en cours"', r.before === 0 && r.after === 2
        && r.disabled && r.label === 'Correction en cours', r);
    await page.close();

    // 6b. Light polling: the partner's text comes in, text being typed is left alone, a started correction reloads.
    page = await open('ecran_normal.html');
    await page.evaluate(() => {
        window.handlers.mod_redaction_save_sujet_reponse = () => window.ok();
        window.remote = [];
        window.handlers.mod_redaction_get_sujets_etat = () => ({pending: false, reponses: window.remote});
    });
    await init(page);
    r = await page.evaluate(async () => {
        const areas = document.querySelectorAll('.mod_redaction-se-reponse');
        const ids = [...areas].map((a) => parseInt(a.dataset.sujetid, 10));
        window.remote = [{sujetid: ids[0], contenu: 'du partenaire'}, {sujetid: ids[1], contenu: 'ecrase ?'}];
        await tick(8700);
        type(1, 'en train de taper');
        await tick(1400);
        const first = {a: areas[0].value, b: areas[1].value, calls: calls_of('mod_redaction_get_sujets_etat').length, args: calls_of('mod_redaction_get_sujets_etat')[0] && calls_of('mod_redaction_get_sujets_etat')[0].args};
        return first;
    });
    check('light poll: the partner text fills an untouched answer, never the one being typed or waiting to be saved',
        r.calls >= 1 && r.a === 'du partenaire' && r.b === 'en train de taper' && r.args.cmid === 11, r);
    r = await page.evaluate(async () => {
        const before = calls_of('mod_redaction_get_sujets_etat').length;
        window.hidden = true;
        Object.defineProperty(document, 'visibilityState', {value: 'hidden', configurable: true});
        await tick(30000);
        return {silent: calls_of('mod_redaction_get_sujets_etat').length === before};
    });
    check('light poll: silent while the page is hidden', r.silent, r);
    await page.close();

    page = await open('ecran_normal.html');
    await page.evaluate(() => {
        window.handlers.mod_redaction_get_sujets_etat = () => ({pending: true, reponses: []});
    });
    await init(page);
    const avantReload = page.navigations;
    await page.evaluate(async () => { await tick(10000); });
    await new Promise((res) => setTimeout(res, 300));
    check('light poll: a correction started by the partner reloads the page once', page.navigations === avantReload + 1, {avantReload, apres: page.navigations});
    await page.close();

    // 7. Strings loading late: nothing happens before, never "undefined" after.
    page = await open('ecran_normal.html');
    await page.evaluate(() => {
        let open;
        window.stringsGate = new Promise((res) => { open = res; });
        window.openStrings = open;
        window.handlers.mod_redaction_save_sujet_reponse = () => window.ok();
        window.handlers.mod_redaction_submit_sujets = () => window.ok();
        window.handlers.mod_redaction_get_evaluation_status = () => ({status: 'pending'});
    });
    await init(page);
    r = await page.evaluate(async () => {
        type(0, 'tot');
        await tick(2000);
        $('#mod_redaction-se-submit').click();
        await settle();
        const early = {calls: calls.length, state: $('#mod_redaction-se-savestate').textContent, label: $('#mod_redaction-se-submit').textContent.trim()};
        window.openStrings();
        await settle();
        type(0, 'tard');
        await tick(2000);
        $('#mod_redaction-se-submit').click();
        await settle();
        const late = {state: $('#mod_redaction-se-savestate').textContent, label: $('#mod_redaction-se-submit').textContent.trim(),
            message: $('#mod_redaction-se-message').textContent, html: document.body.innerHTML.includes('undefined')};
        return {early, late};
    });
    check('late strings: before they load the screen is inert and shows no "undefined"', r.early.calls === 0 && r.early.state === '' && r.early.label === 'Soumettre', r.early);
    check('late strings: after they load, saving and submitting show real words, never "undefined"', r.late.state === 'Enregistré'
        && r.late.label === 'Correction en cours' && !r.late.html, r.late);
    await page.close();

    // 8. No JS error anywhere.
    check('no JS error or unhandled rejection in any page (' + consoleErrors.length + ')', consoleErrors.length === 0, consoleErrors);

    await browser.close();
    console.log(results.join('\n'));
    console.log(failures ? `\n${failures} failure(s)` : '\nAll checks passed');
    process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
