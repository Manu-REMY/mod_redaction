<?php
// Pure PHP check of the prompt of the content and of the help sentences, activity by activity (request 14).
// Usage: php test_prompt_sujet.php

define('MOODLE_INTERNAL', true);

/**
 * Stand-in for Moodle's get_string: the key between brackets, with its parameter.
 *
 * @param string $cle
 * @param string $composant
 * @param mixed $a
 * @return string
 */
function get_string($cle, $composant = '', $a = null) {
    return '[' . $cle . ($a === null ? '' : '|' . $a) . ']';
}

require(__DIR__ . '/../../classes/notes_sujets.php');
require(__DIR__ . '/../../classes/langue.php');
require(__DIR__ . '/../../classes/ai_prompt_builder.php');

use mod_redaction\ai_prompt_builder;
use mod_redaction\notes_sujets;

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

$sujet = (object) ['titre' => 'Compression', 'corrige' => 'E1 : la section. E2 : la matière. X : la couleur.'];
$rob4 = ai_prompt_builder::build_sujet_prompt($sujet, 'Ma réponse', notes_sujets::FORMAT_TEXTE, 'la manière dont un système se dirige');
$poutre = ai_prompt_builder::build_sujet_prompt($sujet, 'Ma réponse', notes_sujets::FORMAT_TEXTE,
    'pourquoi le profil de sa poutre résiste à l\'effort');
$neutre = ai_prompt_builder::build_sujet_prompt($sujet, 'Ma réponse');

test('le thème de l\'activité entre dans l\'introduction', fn() =>
    str_contains($rob4['system'], '[ai_prompt_sujet_intro|la manière dont un système se dirige]'));
test('le thème de l\'activité entre dans le barème', fn() =>
    str_contains($poutre['system'], '[ai_prompt_sujet_bareme|pourquoi le profil de sa poutre résiste à l\'effort]'));
test('une activité ne reçoit jamais le thème d\'une autre', fn() => !str_contains($poutre['system'], 'dirige'));
test('sans thème : la valeur neutre', fn() =>
    str_contains($neutre['system'], '[ai_prompt_sujet_intro|[ai_prompt_sujet_theme_defaut]]'));
test('thème fait d\'espaces : la valeur neutre', fn() =>
    str_contains(ai_prompt_builder::build_sujet_prompt($sujet, 'x', notes_sujets::FORMAT_TEXTE, "  \n")['system'],
        '[ai_prompt_sujet_theme_defaut]'));
test('le thème est une donnée de l\'enseignant : jamais dans le message de l\'élève', fn() => !str_contains($poutre['user'], 'profil'));

// Help sentences the plugin adds to the comment: those of the activity, or neutral ones.
$aides = notes_sujets::phrases_aide((object) ['sujets_aide_partiel' => 'Dis comment ça tourne.', 'sujets_aide_rien' => ' ']);
test('phrase d\'aide de l\'activité quand une partie manque', fn() => $aides['fonctionnement'] === 'Dis comment ça tourne.');
test('phrase d\'aide vide : la phrase neutre', fn() => $aides['les_deux'] === '[sujets_commentaire_rien_defaut]');
$aides = notes_sujets::phrases_aide((object) []);
test('activité sans phrases d\'aide : les phrases neutres', fn() => $aides['fonctionnement'] === '[sujets_commentaire_partiel_defaut]'
    && $aides['les_deux'] === '[sujets_commentaire_rien_defaut]');
test('les deux autres phrases du plugin restent', fn() => $aides['precision'] === '[sujets_commentaire_precision]'
    && $aides['juste'] === '[sujets_commentaire_juste]');

// Request 15: the level the threshold validates, set per activity, Fragile by default.
test('niveau : celui de l\'activité', fn() => notes_sujets::niveau((object) ['sujets_niveau' => 'S']) === '[sujets_niveau_s]');
test('niveau : très satisfaisant', fn() => notes_sujets::niveau((object) ['sujets_niveau' => 'TS']) === '[sujets_niveau_ts]');
test('niveau : début de maîtrise et presque maîtrisé', fn() => notes_sujets::niveau((object) ['sujets_niveau' => 'DM']) === '[sujets_niveau_dm]'
    && notes_sujets::niveau((object) ['sujets_niveau' => 'PM']) === '[sujets_niveau_pm]');
test('niveau INS : refusé (un seuil ne valide pas une maîtrise insuffisante)', function () {
    try {
        notes_sujets::niveau((object) ['sujets_niveau' => 'INS']);
    } catch (\InvalidArgumentException $e) {
        return true;
    }
    return false;
});
test('niveau : fragile par défaut', fn() => notes_sujets::niveau((object) []) === '[sujets_niveau_f]');
test('niveau inconnu : refusé, jamais un autre niveau à la place', function () {
    try {
        notes_sujets::niveau((object) ['sujets_niveau' => 'X']);
    } catch (\InvalidArgumentException $e) {
        return true;
    }
    return false;
});

echo $failures ? "\n{$failures} échec(s)\n" : "\nTout est vert\n";
exit($failures ? 1 : 0);
