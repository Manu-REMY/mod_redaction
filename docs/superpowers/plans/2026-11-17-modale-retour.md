# Fenêtre du retour IA Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ouvrir l'attente et le résultat de chaque correction dans une fenêtre (cadre A), proposer la remise au seuil,
piste sous chaque réponse, bandeau « seuil atteint », volet de droite au même code couleur.

**Architecture:** Le serveur enregistre les éléments trouvés ; `sujets_eleve::export_for_template` calcule les données de la
fenêtre ; le gabarit rend deux `<dialog>` (attente, résultat) ; `amd/src/sujets.js` les ouvre (marque `sessionStorage`).

**Tech Stack:** Moodle 4.5–5.x, PHP 8.1, Mustache, AMD.

**Spec:** `docs/superpowers/specs/2026-11-17-modale-retour-design.md`

## Global Constraints

- Chaînes en `lang/en` puis `lang/fr` ; CSS préfixé `.mod_redaction.mod_redaction-sujets-eleve .mod_redaction-se-…` ; pas de style en ligne.
- Bleu + ✓ = ce qui va ; orange + ✗ = ce qui manque ; la couleur n'est jamais seule.
- Les éléments attendus ne sont jamais nommés (seulement compté). `amd/build` régénéré. Version 2.10.0 (2026111700).

## Review Focus

- Correction ancienne sans `attendus` : pas de compteur → test Task 1.
- Sujet vide d'une finale : « tu n'as pas répondu » dans « Ce qui manque » → test Task 2.
- Copie rendue : ni piste sous les réponses ni bandeau → test Task 2.
- Échec : fenêtre « essai rendu » → test Task 2.
- Version finale notée : fenêtre « note de la version finale » → test Task 2 (verif_finale).

### Task 1: Éléments trouvés enregistrés
- Modify `classes/notes_sujets.php` : `public static function nb_attendus(string $corrige): int` (repères E1, E2… distincts).
- Modify `classes/ai_evaluator.php` : chaque détail porte `attendus` (liste) et `attendus_total`.
- Test : `tests/pure/test_notes_sujets.php` (« E1 : a. E2 : b. E1 » → 2 ; sans repère → 0).

### Task 2: Données, gabarit, style, script
- `classes/output/sujets_eleve.php` : `resultat` (type retour|seuil|finale|echec, note, il manque, compteur, colonnes va / manque /
  prochain, fautes), `items[].piste`, `proposerremise`.
- `templates/sujets_eleve.mustache` + partiel `templates/sujets_resultat.mustache`, `styles.css`, `amd/src/sujets.js`, lang.
- Tests `verif_ecran.php`, `verif_finale.php`. Rendu vérifié à l'écran. Préprod.
