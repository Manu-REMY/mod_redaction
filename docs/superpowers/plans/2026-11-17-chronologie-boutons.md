# Chronologie des boutons (proposition C) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sur l'écran élève du mode sujets, montrer l'ordre écrire → retour → version finale, et refuser la version finale tant qu'aucun retour n'a été reçu.

**Architecture:** Une règle serveur (`redaction_can_submit_final()`, raison `no_feedback`) et des données de gabarit
(`classes/output/sujets_eleve.php`) qui calculent l'étape en cours, les pastilles et le verrou ; le gabarit et `styles.css`
les affichent. Aucun changement JS : la page se recharge déjà après chaque correction.

**Tech Stack:** Moodle 5.x, PHP 8.1, Mustache, CSS.

**Spec:** `docs/superpowers/specs/2026-11-17-chronologie-boutons-design.md`

## Global Constraints

- Aucun texte en dur : chaînes en `lang/en` (maître) puis `lang/fr`.
- Sélecteurs CSS `.mod_redaction.mod_redaction-sujets-eleve .mod_redaction-se-xxx`, pas de style en ligne.
- Daltonisme : étapes distinguées par clarté et forme (✓, chiffre, pointillés, cadenas), jamais par la teinte seule.
- Version 2.9.0 (2026111400).

## Review Focus

- Copie rouverte par le professeur (status 0, finale déjà corrigée) : le verrou ne revient pas → test dans Task 1.
- Entraînement ou IA désactivés : pas de verrou → test dans Task 1.
- Retour en échec seulement : verrou maintenu → test dans Task 1.
- Le service `submit_final` refuse avec le message `training_error_no_feedback` → test dans Task 1.
- Soumission automatique à la date limite inchangée (n'appelle pas `redaction_can_submit_final`) → vérifié par `verif_finale.php` section 7 existante.

---

### Task 1: Règle serveur `no_feedback`

**Files:**
- Modify: `redaction/lib.php` (`redaction_can_submit_final`, nouvelle `redaction_retour_recu`)
- Modify: `redaction/lang/en/redaction.php`, `redaction/lang/fr/redaction.php`
- Test: `redaction/tests/cli/verif_finale.php`

**Interfaces:**
- Produces: `redaction_retour_recu(stdClass $submission): bool` (une évaluation `status = 'applied'`, `finale = 0`) ;
  `redaction_retours_possibles(stdClass $redaction): bool` (`training_enabled` et `ai_enabled`).

- [ ] Tests : section 6 de `verif_finale.php` : finale refusée `no_feedback` avant tout retour ; refusée encore après un retour `failed` ;
  acceptée après un retour `applied` ; activité sans entraînement : acceptée sans retour ; copie rouverte avec une finale
  `applied` et un essai `applied` : acceptée.
- [ ] Les lancer en préprod : FAIL.
- [ ] Implémenter ; chaîne `training_error_no_feedback` (en : « Ask for at least one feedback before handing in your final version. »).
- [ ] Tests : PASS. Commit.

### Task 2: Frise, pastilles, boutons numérotés, verrou

**Files:**
- Modify: `redaction/classes/output/sujets_eleve.php`, `redaction/templates/sujets_eleve.mustache`, `redaction/styles.css`, lang en/fr
- Test: `redaction/tests/cli/verif_ecran.php`

**Interfaces:**
- Consumes: `redaction_retour_recu`, `redaction_retours_possibles`.
- Produces (gabarit) : `etapes` (3 × {numero, libelle, fait, encours}), `pastilles` (max × {utilise}), `utilises`, `retourplein`,
  `finaleplein`, `verrou`.

- [ ] Tests : sans texte → étape 1, verrou, bouton retour plein ; texte sans retour → étape 2 ; retour sous le seuil → étape 2,
  verrou levé ; retour au seuil → étape 3, bouton final plein ; « N utilisés sur M » ; cadenas et phrase du verrou dans le HTML.
- [ ] FAIL, implémenter, PASS. Version 2.9.0. Commit. Déploiement préprod.
