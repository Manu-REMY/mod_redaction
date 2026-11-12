// Local test of amd/src/phrases.js on the same cases as the server (no Moodle, no browser).
//
// Usage: node tests/js/test_phrases.js [path/to/phrases.js]
'use strict';

const fs = require('fs');
const path = require('path');

let phrases;
global.define = (deps, factory) => {
    phrases = factory();
};
require(path.resolve(process.argv[2] || path.join(__dirname, '../../amd/src/phrases.js')));

const cas = JSON.parse(fs.readFileSync(path.join(__dirname, '../pure/phrases_cas.json'), 'utf8'));
let failures = 0;
for (const c of cas) {
    const ok = phrases.compter(c.texte) === c.phrases;
    console.log((ok ? 'PASS' : 'FAIL') + '  phrases : ' + c.note + (ok ? '' : ' (obtenu ' + phrases.compter(c.texte) + ')'));
    failures += ok ? 0 : 1;
}
console.log(failures ? `\n${failures} échec(s)` : '\nTout est vert');
process.exit(failures ? 1 : 0);
