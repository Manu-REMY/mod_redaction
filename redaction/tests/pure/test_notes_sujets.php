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
    $lu = notes_sujets::lire_reponse_ia('{"attendus": ["E1", "E2"], "note": 15.5, "commentaire": "Bien vu."}');
    return $lu['note'] === 15.5 && $lu['commentaire'] === 'Bien vu.';
});
test('réponse IA : note entière rendue en décimal', fn() =>
    notes_sujets::lire_reponse_ia('{"attendus": ["E1", "E2"], "note": 20, "commentaire": "Oui."}')['note'] === 20.0);
test('réponse IA : JSON entre clôtures accepté', function () {
    $lu = notes_sujets::lire_reponse_ia("Voici :\n```json\n{\"attendus\": [\"E1\", \"E2\"], \"note\": 0, \"commentaire\": \"Hors sujet.\"}\n```\n");
    return $lu['note'] === 0.0 && $lu['commentaire'] === 'Hors sujet.';
});
test('réponse IA : texte quelconque refusé', fn() => refuse('Très bon travail, 15/20'));
test('réponse IA : vide refusée', fn() => refuse(''));
test('réponse IA : note absente refusée', fn() => refuse('{"commentaire": "Oui."}'));
test('réponse IA : commentaire absent refusé', fn() => refuse('{"attendus": ["E1", "E2"], "note": 12}'));
test('réponse IA : commentaire vide refusé', fn() => refuse('{"attendus": ["E1", "E2"], "note": 12, "commentaire": "  "}'));
test('réponse IA : note non numérique refusée', fn() => refuse('{"attendus": ["E1", "E2"], "note": "bien", "commentaire": "Oui."}'));
test('réponse IA : note booléenne refusée', fn() => refuse('{"attendus": ["E1", "E2"], "note": true, "commentaire": "Oui."}'));
test('réponse IA : 25 refusé', fn() => refuse('{"attendus": ["E1", "E2"], "note": 25, "commentaire": "Oui."}'));
test('réponse IA : note négative refusée', fn() => refuse('{"attendus": ["E1", "E2"], "note": -1, "commentaire": "Oui."}'));
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

// Le commentaire de l'IA et la phrase écrite par le plugin.
const PHRASES = [
    'piece' => 'PHRASE-PIECE', 'fonctionnement' => 'PHRASE-FONCTIONNEMENT', 'les_deux' => 'PHRASE-LES-DEUX',
    'precision' => 'PHRASE-PRECISION', 'juste' => 'PHRASE-JUSTE',
];
/** Comment shown to the student, from the fields of the AI answer. */
function final_ia(float $note, string $commentaire, ?array $attendus, array $erreurs = [], float $seuil = 16.0): string {
    return notes_sujets::commentaire_final(
        ['note' => $note, 'commentaire' => $commentaire, 'attendus' => $attendus, 'erreurs' => $erreurs], $seuil, PHRASES);
}

test('« Si absent : » retiré à la lecture', function() {
    $lu = notes_sujets::lire_reponse_ia('{"attendus": ["E1", "E2"], "note": 12, "commentaire": "Tu as raison. Si absent : il manque ceci."}');
    return $lu['commentaire'] === 'Tu as raison. il manque ceci.';
});
test('« si absent: » retiré quelle que soit la casse', fn() =>
    notes_sujets::lire_reponse_ia('{"attendus": ["E1", "E2"], "note": 12, "commentaire": "Oui. si absent: non."}')['commentaire'] === 'Oui. non.');
test('un commentaire réduit à « Si absent : » est refusé', fn() => refuse('{"attendus": ["E1", "E2"], "note": 12, "commentaire": " Si absent : "}'));
test('attendus et erreurs lus', function() {
    $lu = notes_sujets::lire_reponse_ia('{"attendus": ["E1", "E2 : le fonctionnement"], "erreurs": ["X1"], "note": 12, "commentaire": "Oui."}');
    return $lu['attendus'] === ['E1', 'E2'] && $lu['erreurs'] === ['X1'];
});
test('attendus vides : liste vide, pas null', fn() =>
    notes_sujets::lire_reponse_ia('{"attendus": [], "note": 2, "commentaire": "Non."}')['attendus'] === []);
test('sans attendus : refusé, l\'essai est rendu au groupe', fn() => refuse('{"note": 18, "commentaire": "Oui."}'));
test('attendus qui ne sont pas une liste : refusé', fn() => refuse('{"attendus": "E1 E2", "note": 18, "commentaire": "Oui."}'));
test('sans erreurs : liste vide', fn() => notes_sujets::lire_reponse_ia('{"attendus": ["E1", "E2"], "note": 12, "commentaire": "Oui."}')['erreurs'] === []);
test('« n\'a pas cité » et « tu n\'as pas donné » sont retirés', function() {
    $f = final_ia(12.0, 'Bien vu. Elle n\'a pas cité la voie. Tu n\'as pas donné le rôle du rail.', ['E1', 'E2']);
    return strpos($f, 'la voie') === false && strpos($f, 'rôle du rail') === false && strpos($f, 'Bien vu') !== false;
});

test('pièce citée, fonctionnement absent : ne dit jamais que la pièce manque', function() {
    $texte = final_ia(12, 'Tu as cité les rails. Il manque la pièce qui dirige.', ['E1']);
    return $texte === 'Tu as cité les rails. PHRASE-FONCTIONNEMENT';
});
test('pièce citée, même avec « mais il manque la pièce » dans la phrase', fn() =>
    final_ia(12, 'Tu cites les rails, mais il manque la pièce qui dirige.', ['E1']) === 'Tu cites les rails. PHRASE-FONCTIONNEMENT');
test('fonctionnement présent, pièce absente', fn() => final_ia(12, 'Bien.', ['E2']) === 'Bien. PHRASE-PIECE');
test('rien de présent : une phrase qui n\'affirme pas que la pièce manque', fn() =>
    final_ia(4, 'Cette idée est fausse. Il manque la pièce.', []) === 'Cette idée est fausse. PHRASE-LES-DEUX');
test('à 16 : rien ne manque', fn() => final_ia(16, 'Bien vu. Il manque juste un détail.', ['E1', 'E2']) === 'Bien vu.');
test('à 20 : rien ne manque', fn() => final_ia(20, 'Bravo, il manque un mot.', ['E1', 'E2']) === 'Bravo.');
test('à 18 : rien ne manque, même si l\'IA a oublié un attendu', fn() => final_ia(18, 'Bien vu.', ['E1']) === 'Bien vu.');
test('seuil réglé : au-dessus, rien ne manque', fn() => final_ia(15, 'Bien vu.', ['E1'], [], 14.0) === 'Bien vu.');
test('tout présent mais une erreur : rien à ajouter', fn() =>
    final_ia(12, 'Cette idée est fausse.', ['E1', 'E2'], ['X1']) === 'Cette idée est fausse.');
test('tout présent, sans erreur, sous le seuil : demander de préciser', fn() =>
    final_ia(14, 'Bien vu.', ['E1', 'E2']) === 'Bien vu. PHRASE-PRECISION');
test('seul « il manque » de l\'IA, à 18 : phrase neutre', fn() => final_ia(18, 'Il manque un mot.', ['E1', 'E2']) === 'PHRASE-JUSTE');
test('seul « il manque » de l\'IA, à 12 : la phrase du plugin seule', fn() =>
    final_ia(12, 'Il manque la pièce.', ['E1']) === 'PHRASE-FONCTIONNEMENT');
test('une phrase sans « manque » reste entière', fn() =>
    final_ia(12, 'Tu dis que le volant dirige, et cette idée est fausse.', ['E1']) === 'Tu dis que le volant dirige, et cette idée est fausse. PHRASE-FONCTIONNEMENT');
test('« mais tu ne dis pas que… » : la suite part avec l\'explication', fn() =>
    final_ia(12, 'Tu cites les chenilles, mais tu ne dis pas comment : c\'est la différence de vitesse qui compte.', ['E1'])
        === 'Tu cites les chenilles. PHRASE-FONCTIONNEMENT');
test('« Mais tu ne dis pas que… » en début de phrase : phrase retirée', fn() =>
    final_ia(12, 'Tu cites l\'articulation. Mais tu ne dis pas que la machine se plie.', ['E1']) === 'Tu cites l\'articulation. PHRASE-FONCTIONNEMENT');
test('« Tu ne mentionnes pas… » : phrase retirée', fn() =>
    final_ia(6, 'Cette idée est fausse. Tu ne mentionnes pas l\'articulation au milieu.', []) === 'Cette idée est fausse. PHRASE-LES-DEUX');
test('« Il ne cite aucun rotor » : phrase retirée', fn() =>
    final_ia(2, 'Tu parles de l\'hélicoptère. Il ne cite aucun rotor ni leur rôle.', []) === 'Tu parles de l\'hélicoptère. PHRASE-LES-DEUX');
test('« Ta réponse ne décrit pas… » : phrase retirée', fn() =>
    final_ia(2, 'Tu parles de patins. Ta réponse ne décrit pas le rôle des rotors.', []) === 'Tu parles de patins. PHRASE-LES-DEUX');
test('« mais n\'explique pas… » sans sujet : la suite part', fn() =>
    final_ia(0, 'Ton texte parle du train, mais n\'explique pas comment il se dirige.', []) === 'Ton texte parle du train. PHRASE-LES-DEUX');
test('une erreur nommée reste', fn() =>
    final_ia(4, 'Tu dis que les pédales dirigent : cette idée est fausse.', []) === 'Tu dis que les pédales dirigent : cette idée est fausse. PHRASE-LES-DEUX');
test('plafond : E2 absent, 18 ramené à 14', function() {
    $lu = notes_sujets::lire_reponse_ia('{"attendus": ["E1"], "erreurs": [], "note": 18, "commentaire": "Oui."}');
    return $lu['note'] === 14.0 && $lu['note_ia'] === 18.0;
});
test('plafond : E1 absent, 18 ramené à 14', fn() =>
    notes_sujets::lire_reponse_ia('{"attendus": ["E2"], "note": 18, "commentaire": "Oui."}')['note'] === 14.0);
test('plafond : aucun E, 18 ramené à 14', fn() =>
    notes_sujets::lire_reponse_ia('{"attendus": [], "note": 18, "commentaire": "Oui."}')['note'] === 14.0);
test('plafond : E1 et E2 présents, note gardée', fn() =>
    notes_sujets::lire_reponse_ia('{"attendus": ["E1", "E2"], "note": 18, "commentaire": "Oui."}')['note'] === 18.0);
test('plafond : E1 et E2 dans un même élément, note gardée', fn() =>
    notes_sujets::lire_reponse_ia('{"attendus": ["E1 et E2"], "note": 18, "commentaire": "Oui."}')['note'] === 18.0);
test('plafond : une note déjà basse ne change pas', fn() =>
    notes_sujets::lire_reponse_ia('{"attendus": ["E1"], "note": 12, "commentaire": "Oui."}')['note'] === 12.0);
test('plafond : E1 et E2 présents, note gardée', fn() =>
    notes_sujets::lire_reponse_ia('{"attendus": ["E1", "E2"], "note": 18, "commentaire": "Oui."}')['note'] === 18.0);
test('plafond : 18 sans E2 ne valide pas et dit ce qui manque', function() {
    $lu = notes_sujets::lire_reponse_ia('{"attendus": ["E1"], "note": 18, "commentaire": "Tu cites les pattes."}');
    return notes_sujets::commentaire_final($lu, 16.0, PHRASES) === 'Tu cites les pattes. PHRASE-FONCTIONNEMENT';
});

echo $failures ? "\n{$failures} échec(s)\n" : "\nTout est vert\n";
exit($failures ? 1 : 0);
