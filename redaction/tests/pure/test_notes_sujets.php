<?php
// Pure PHP check of the grades of the assigned subjects mode (no Moodle needed).
// Usage: php test_notes_sujets.php

define('MOODLE_INTERNAL', true);
require(__DIR__ . '/../../classes/notes_sujets.php');

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

/** True when reading the raw answer throws. */
function refuse(string $brut): bool {
    try {
        notes_sujets::lire_reponse_ia($brut);
    } catch (\UnexpectedValueException $e) {
        return true;
    }
    return false;
}

test('moyenne', fn() => notes_sujets::note_soumission([14.0, 18.0]) === 16.0);
test('moyenne arrondie à deux décimales', fn() => notes_sujets::note_soumission([10.0, 10.0, 11.0]) === 10.33);
test('une note manque : pas de note', fn() => notes_sujets::note_soumission([14.0, null]) === null);
test('aucune note : pas de note', fn() => notes_sujets::note_soumission([]) === null);

test('la meilleure ne baisse pas', fn() => notes_sujets::meilleure([12, 17, 9], null) === 17.0);
test('la meilleure lit les valeurs de la base', fn() => notes_sujets::meilleure(['12.00000', '9.50000'], null) === 12.0);
test('la meilleure ignore une note absente', fn() => notes_sujets::meilleure([null, 11.0], null) === 11.0);
test('aucune évaluation : pas de note', fn() => notes_sujets::meilleure([], null) === null);
test('le professeur prime, même plus bas', fn() => notes_sujets::meilleure([17], 10.0) === 10.0);
test('le professeur prime, même plus haut', fn() => notes_sujets::meilleure([17], 19.0) === 19.0);
test('le professeur note sans évaluation', fn() => notes_sujets::meilleure([], 12.0) === 12.0);
test('un zéro du professeur reste une note', fn() => notes_sujets::meilleure([17], 0.0) === 0.0);

test('membres au moment de la soumission', function () {
    // A et B sur l'éval 1 (15) ; C entre, éval 2 (17) avec A et C.
    $notes = notes_sujets::pour_membres([1 => 15.0, 2 => 17.0], [1 => [101, 102], 2 => [101, 103]], []);
    ksort($notes);
    return $notes === [101 => 17.0, 102 => 15.0, 103 => 17.0];
});
test('une note plus basse ne fait pas baisser un membre', function () {
    $notes = notes_sujets::pour_membres([1 => '17.00000', 2 => '9.00000'], [1 => [101], 2 => [101]], []);
    return $notes === [101 => 17.0];
});
test('le professeur prime pour chaque membre désigné', function () {
    $notes = notes_sujets::pour_membres([1 => 17.0], [1 => [101, 102]], [101 => 10.0, 102 => 10.0, 103 => 10.0]);
    ksort($notes);
    return $notes === [101 => 10.0, 102 => 10.0, 103 => 10.0];
});
test('une évaluation sans membre ne crédite personne', fn() => notes_sujets::pour_membres([1 => 15.0], [], []) === []);

test('réponse IA : JSON simple', function () {
    $lu = notes_sujets::lire_reponse_ia('{"note": 15.5, "commentaire": "Bien vu."}');
    return $lu === ['note' => 15.5, 'commentaire' => 'Bien vu.'];
});
test('réponse IA : note entière rendue en décimal', fn() =>
    notes_sujets::lire_reponse_ia('{"note": 20, "commentaire": "Oui."}')['note'] === 20.0);
test('réponse IA : JSON entre clôtures accepté', function () {
    $lu = notes_sujets::lire_reponse_ia("Voici :\n```json\n{\"note\": 0, \"commentaire\": \"Hors sujet.\"}\n```\n");
    return $lu === ['note' => 0.0, 'commentaire' => 'Hors sujet.'];
});
test('réponse IA : texte quelconque refusé', fn() => refuse('Très bon travail, 15/20'));
test('réponse IA : vide refusée', fn() => refuse(''));
test('réponse IA : note absente refusée', fn() => refuse('{"commentaire": "Oui."}'));
test('réponse IA : commentaire absent refusé', fn() => refuse('{"note": 12}'));
test('réponse IA : commentaire vide refusé', fn() => refuse('{"note": 12, "commentaire": "  "}'));
test('réponse IA : note non numérique refusée', fn() => refuse('{"note": "bien", "commentaire": "Oui."}'));
test('réponse IA : note booléenne refusée', fn() => refuse('{"note": true, "commentaire": "Oui."}'));
test('réponse IA : 25 refusé', fn() => refuse('{"note": 25, "commentaire": "Oui."}'));
test('réponse IA : note négative refusée', fn() => refuse('{"note": -1, "commentaire": "Oui."}'));
test('réponse IA : liste JSON refusée', fn() => refuse('[1, 2]'));

const HTML = 1;
const BRUT = notes_sujets::FORMAT_TEXTE;
test('texte HTML : balises retirées', fn() =>
    notes_sujets::texte_reponse('<p>Le rotor <strong>arrière</strong></p><p>tourne &amp; pivote</p>', HTML) === "Le rotor arrière\ntourne & pivote");
test('texte HTML : paragraphe vide', fn() => notes_sujets::texte_reponse('<p>&nbsp;</p><p><br></p> ', HTML) === '');
test('texte HTML : un signe inférieur échappé reste', fn() =>
    notes_sujets::texte_reponse('<p>rayon &lt;5 cm donc serré</p>', HTML) === 'rayon <5 cm donc serré');
test('texte brut : un signe inférieur ne coupe rien', fn() =>
    notes_sujets::texte_reponse('rayon <5 cm donc serré', BRUT) === 'rayon <5 cm donc serré');
test('texte brut : rien n\'est décodé ni retiré', fn() =>
    notes_sujets::texte_reponse('  a &lt; b <b>gras</b> ', BRUT) === 'a &lt; b <b>gras</b>');
test('texte brut : blancs seulement, vide', fn() => notes_sujets::texte_reponse(" \n\u{00A0} ", BRUT) === '');
test('texte : null accepté', fn() => notes_sujets::texte_reponse(null, BRUT) === '' && notes_sujets::texte_reponse(null, HTML) === '');

echo $failures ? "\n{$failures} échec(s)\n" : "\nTout est vert\n";
exit($failures ? 1 : 0);
