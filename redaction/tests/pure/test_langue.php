<?php
// Pure PHP check of the language assessment (no Moodle needed).
// Usage: php test_langue.php

define('MOODLE_INTERNAL', true);
require(__DIR__ . '/../../classes/langue.php');

use mod_redaction\langue;

$failures = 0;
function test(string $label, callable $fn): void {
    global $failures;
    $ok = false;
    try {
        $ok = (bool) $fn();
    } catch (\Throwable $e) {
        echo 'EXC   ' . $e->getMessage() . "\n";
    }
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

/** True when reading the raw answer throws. */
function refuse(string $brut): bool {
    try {
        langue::lire_reponse_ia($brut);
    } catch (\UnexpectedValueException $e) {
        return true;
    }
    return false;
}

/** Four levels, in the order of the dimensions. */
function paliers(string $o, string $s, string $l, string $g): array {
    return ['orthographe' => $o, 'syntaxe' => $s, 'lexique' => $l, 'organisation' => $g];
}

$ok = '{"orthographe":{"palier":"I","fautes":[{"cite":"fonctionnemant","correction":"fonctionnement","type":"lexicale","fois":1}]},'
    . '"syntaxe":{"palier":"S","remarques":[]},"lexique":{"palier":"S"},"organisation":{"palier":"TS"}}';

// Reading.
test('lecture valide', fn() => langue::lire_reponse_ia($ok)['paliers'] === paliers('I', 'S', 'S', 'TS'));
test('lecture dans un bloc json', fn() => langue::lire_reponse_ia("```json\n$ok\n```")['fautes'][0]['correction'] === 'fonctionnement');
test('palier inconnu refusé', fn() => refuse(str_replace('"TS"', '"B"', $ok)));
test('dimension absente refusée', fn() => refuse('{"orthographe":{"palier":"S"},"syntaxe":{"palier":"S"},"lexique":{"palier":"S"}}'));
test('réponse coupée refusée', fn() => refuse(substr($ok, 0, 80)));
test('pas du JSON refusé', fn() => refuse('Voici mon évaluation : bien.'));
test('points annoncés ignorés', fn() => !array_key_exists('points', langue::lire_reponse_ia(substr($ok, 0, -1) . ',"points":2}')));
test('type inconnu = null', fn() => langue::lire_reponse_ia(str_replace('"lexicale"', '"bizarre"', $ok))['fautes'][0]['type'] === null);
test('fois absent = 1', fn() => langue::lire_reponse_ia(str_replace(',"fois":1', '', $ok))['fautes'][0]['fois'] === 1);
test('fois nul = 1', fn() => langue::lire_reponse_ia(str_replace('"fois":1', '"fois":0', $ok))['fautes'][0]['fois'] === 1);
test('remarque de syntaxe lue', fn() => langue::lire_reponse_ia(str_replace('"remarques":[]',
    '"remarques":[{"cite":"le moteur et la roue tourne","type":"phrase_mal_construite"}]', $ok))['remarques'][0]['type']
    === 'phrase_mal_construite');

// Citations.
test('citation exacte', fn() => langue::figure_dans('fonctionnemant', 'Le fonctionnemant est simple.'));
test('casse et espaces', fn() => langue::figure_dans('le  Moteur tourne', "le moteur\ntourne vite"));
test('apostrophe typographique', fn() => langue::figure_dans("l'axe", 'l’axe tourne'));
test('espace insécable', fn() => langue::figure_dans('la roue', "la\u{00A0}roue"));
test('mot entier seulement', fn() => !langue::figure_dans('sa', 'le sable est fin'));
test('introuvable', fn() => !langue::figure_dans('resisté', 'il résiste bien'));
test('citation vide refusée', fn() => !langue::figure_dans('  ', 'il résiste bien'));

// Corrections.
test('correction proche', fn() => langue::correction_proche('fonctionnemant', 'fonctionnement'));
test('accent seul accepté', fn() => langue::correction_proche('a', 'à'));
test('cédille seule acceptée', fn() => langue::correction_proche('ca', 'ça'));
test('identique refusée', fn() => !langue::correction_proche('roue', 'Roue'));
test('autre mot refusé', fn() => !langue::correction_proche('truc', 'gouvernail'));
test('correction vide refusée', fn() => !langue::correction_proche('roue', ''));
test('accord sur plusieurs mots accepté', fn() => langue::correction_proche('les roue tourne', 'les roues tournent'));
test('un mot remplacé dans un groupe refusé', fn() => !langue::correction_proche('le moteur entraine la courroie', 'le moteur entraîne la poulie'));
test('un nom de pièce glissé refusé', fn() => !langue::correction_proche('les roue tourne grace au moteur', 'les roues tournent grâce au vérin'));
test('mot changé avec apostrophe refusé', fn() => !langue::correction_proche('sa permet de tourner la roue', 'ça permet de tourner l\'arbre'));
test('mot court allongé en un autre refusé', fn() => !langue::correction_proche('a', 'axe'));
test('apostrophe ajoutée acceptée', fn() => langue::correction_proche('cest', 'c\'est'));
test('participe corrigé accepté', fn() => langue::correction_proche('fait tourné', 'fait tourner'));
test('homophone dans un groupe accepté', fn() => langue::correction_proche('sa fait pivoté', 'ça fait pivoter'));

// Checking against the text.
test('faute inventée écartée et plancher S', function() {
    $v = langue::verifier(['paliers' => paliers('TI', 'I', 'S', 'S'),
        'fautes' => [['cite' => 'resisté', 'correction' => 'résisté', 'type' => 'lexicale', 'fois' => 1]],
        'remarques' => [['cite' => 'phrase inventée ici', 'type' => 'phrase_incomplete']]], 'Le robot résiste.');
    return $v['paliers']['orthographe'] === 'S' && $v['paliers']['syntaxe'] === 'S' && $v['fautes'] === [] && $v['ecartees'] === 2;
});
test('faute réelle gardée, palier gardé, doublons fusionnés', function() {
    $v = langue::verifier(['paliers' => paliers('I', 'S', 'S', 'S'),
        'fautes' => [['cite' => 'sa permet', 'correction' => 'ça permet', 'type' => 'homophone', 'fois' => 1],
                     ['cite' => 'Sa permet', 'correction' => 'ça permet', 'type' => 'homophone', 'fois' => 1]],
        'remarques' => []], 'sa permet de tourner. Sa permet aussi');
    return $v['paliers']['orthographe'] === 'I' && count($v['fautes']) === 1 && $v['fautes'][0]['fois'] === 2
        && $v['ecartees'] === 0;
});
test('correction trop éloignée écartée', function() {
    $v = langue::verifier(['paliers' => paliers('I', 'S', 'S', 'S'),
        'fautes' => [['cite' => 'truc', 'correction' => 'gouvernail', 'type' => 'lexicale', 'fois' => 1]],
        'remarques' => []], 'le truc tourne');
    return $v['fautes'] === [] && $v['ecartees'] === 1 && $v['paliers']['orthographe'] === 'S';
});
test('remarque réelle gardée, syntaxe gardée', function() {
    $v = langue::verifier(['paliers' => paliers('S', 'I', 'S', 'S'), 'fautes' => [],
        'remarques' => [['cite' => 'le moteur et la roue tourne', 'type' => 'phrase_mal_construite']]],
        'Le moteur et la roue tourne pour aller.');
    return $v['paliers']['syntaxe'] === 'I' && count($v['remarques']) === 1;
});
test('lexique et organisation sans preuve gardés', fn() => langue::verifier(['paliers' => paliers('S', 'S', 'TI', 'I'),
    'fautes' => [], 'remarques' => []], 'x')['paliers'] === paliers('S', 'S', 'TI', 'I'));

// Points.
test('points P=2 tous S', fn() => langue::points(paliers('S', 'S', 'S', 'S'), 2.0) === 1.33);
test('points P=2 I,S,S,TS', fn() => langue::points(paliers('I', 'S', 'S', 'TS'), 2.0) === 1.33);
test('points P=5 tous TS', fn() => langue::points(paliers('TS', 'TS', 'TS', 'TS'), 5.0) === 5.0);
test('points tous TI', fn() => langue::points(paliers('TI', 'TI', 'TI', 'TI'), 2.0) === 0.0);
test('points P=2 TI,I,S,TS', fn() => langue::points(paliers('TI', 'I', 'S', 'TS'), 2.0) === 1.0);

// Sentences of an answer (request 9): same cases as the script of the student screen (tests/js/test_phrases.js).
foreach (json_decode(file_get_contents(__DIR__ . '/phrases_cas.json'), true) as $cas) {
    test('phrases : ' . $cas['note'], fn() => langue::phrases($cas['texte']) === $cas['phrases']);
}

// Request 16: a rule for each mistake and a piece of advice.
test('règle de la faute gardée, conseil gardé', function () {
    $lu = langue::lire_reponse_ia('{"orthographe":{"palier":"I","fautes":[{"cite":"résiste","correction":"résistent","type":"accord","regle":"Le verbe s\'accorde."}]},'
        . '"syntaxe":{"palier":"S","remarques":[{"cite":"a b","type":"ponctuation","regle":"Une phrase finit par un point."}]},'
        . '"lexique":{"palier":"S"},"organisation":{"palier":"S"},"conseil":"Relis tes verbes."}');
    $v = langue::verifier($lu, 'les semelles résiste a b');
    return $lu['fautes'][0]['regle'] === 'Le verbe s\'accorde.' && $lu['remarques'][0]['regle'] === 'Une phrase finit par un point.'
        && $lu['conseil'] === 'Relis tes verbes.' && $v['conseil'] === 'Relis tes verbes.' && $v['fautes'][0]['regle'] === 'Le verbe s\'accorde.';
});
test('sans règle ni conseil : chaînes vides', function () {
    $lu = langue::lire_reponse_ia('{"orthographe":{"palier":"S","fautes":[{"cite":"x","correction":"y","type":"lexicale"}]},'
        . '"syntaxe":{"palier":"S"},"lexique":{"palier":"S"},"organisation":{"palier":"S"}}');
    return $lu['fautes'][0]['regle'] === '' && $lu['conseil'] === '';
});

echo $failures === 0 ? "\nOK\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
