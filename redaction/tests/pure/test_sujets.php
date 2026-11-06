<?php
// Pure PHP check of the subject attribution (no Moodle needed).
// Usage: php test_sujets.php

define('MOODLE_INTERNAL', true);
require(__DIR__ . '/../../classes/sujets.php');

use mod_redaction\sujets;

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

/** Number of times each id appears in a list of pairs. */
function compter(array $ids, array $paires): array {
    $c = array_fill_keys($ids, 0);
    foreach ($paires as $p) {
        foreach ($p as $id) {
            $c[$id]++;
        }
    }
    return $c;
}

test('n sujets distincts', fn() => count(array_unique(sujets::choisir(range(1, 8), [], 2, 'mt_rand'))) === 2);

test('prend les moins attribués', function () {
    // Sujets 1 à 7 déjà donnés deux fois, le 8e jamais.
    $deja = [[1, 2], [3, 4], [5, 6], [7, 1], [2, 3], [4, 5], [6, 7]];
    for ($i = 0; $i < 100; $i++) {
        if (!in_array(8, sujets::choisir(range(1, 8), $deja, 2, 'mt_rand'))) {
            return false;
        }
    }
    return true;
});

test('évite une paire déjà donnée dans la classe', function () {
    $deja = [[1, 2], [3, 4]];
    for ($i = 0; $i < 300; $i++) {
        $p = sujets::choisir([1, 2, 3, 4], $deja, 2, 'mt_rand');
        sort($p);
        if ($p === [1, 2] || $p === [3, 4]) {
            return false;
        }
    }
    return true;
});

test('toutes les paires prises : rend quand même 2 sujets', function () {
    $deja = [];
    for ($g = 0; $g < 29; $g++) {
        $p = sujets::choisir(range(1, 8), $deja, 2, 'mt_rand');
        if (count(array_unique($p)) !== 2) {
            return false;
        }
        $deja[] = $p;
    }
    return true;
});

test('répartition sur 13 groupes : écart max de 1 entre sujets', function () {
    for ($essai = 0; $essai < 50; $essai++) {
        mt_srand($essai);
        $deja = [];
        for ($g = 0; $g < 13; $g++) {
            $deja[] = sujets::choisir(range(1, 8), $deja, 2, 'mt_rand');
        }
        $c = compter(range(1, 8), $deja);
        if (max($c) - min($c) > 1) {
            return false;
        }
    }
    return true;
});

test('moins de sujets que demandé : les rend tous', function () {
    $p = sujets::choisir([5, 6], [], 3, 'mt_rand');
    sort($p);
    return $p === [5, 6] && sujets::choisir([], [], 2, 'mt_rand') === [];
});

test('hasard injecté : résultat déterministe', fn() =>
    sujets::choisir([1, 2, 3], [], 2, fn($min, $max) => $min) === [1, 2]);

test('classe_du_groupe', fn() => sujets::classe_du_groupe('4A-GRP01') === '4A' && sujets::classe_du_groupe('Autre') === 'Autre');

echo $failures ? "\n$failures échec(s)\n" : "\nTout est vert\n";
exit($failures ? 1 : 0);
