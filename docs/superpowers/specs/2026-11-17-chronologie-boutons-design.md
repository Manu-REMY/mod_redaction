# Chronologie des boutons de l'écran élève (mode sujets) — conception

Date : 17/11/2026. Maquettes : https://claude.ai/artifact/XVF1Qg2SVCp3yxeaJC5RcR (proposition C retenue par Emmanuel).

## Intention

Les élèves ne demandent pas de retour : « Demander un retour » est un bouton à contour, « Soumettre ma version finale »
un bouton plein, donc lu comme l'action à faire. L'écran doit montrer l'ordre normal : écrire, demander un ou
plusieurs retours, puis soumettre la version finale.

## Approches envisagées

- A. Deux boutons numérotés, « Demander un retour » plein en permanence. Simple, mais pousse encore à redemander une
  fois le seuil atteint.
- B. Numérotation + le bouton plein suit l'avancement (retour tant que le seuil n'est pas atteint, puis version finale).
- **C (retenue)**. Frise des étapes + pastilles des retours, hiérarchie des boutons de B, et **version finale
  verrouillée tant qu'aucun retour n'a été reçu**. C'est un changement de règle : rendre sans retour devient impossible.

## Conception

Panneau « Mon avancement » (`templates/sujets_eleve.mustache`, `classes/output/sujets_eleve.php`, `styles.css`) :

1. **Frise à 3 étapes** : Écrire → Retour → Version finale. Étape faite : pastille claire avec ✓ ; étape en cours :
   pastille bleue pleine numérotée avec un halo ; étape à venir : pastille grise en pointillés. La distinction tient à
   la clarté et à la forme, jamais à la teinte seule.
   - Étape en cours = 1 si aucune réponse n'est écrite ; 2 si du texte existe et qu'aucun retour n'atteint le seuil ;
     3 si le dernier retour atteint le seuil. Copie rendue : les trois étapes faites.
2. **Pastilles des retours** : une par retour possible (maximum effectif), pleines pour les retours utilisés, avec
   « N utilisés sur M ». Remplace la ligne « Essais restants ».
3. **Boutons numérotés** « 2 Demander un retour (N restants) » et « 3 Soumettre ma version finale ».
   Le bouton plein est celui de l'étape en cours (règle de B) ; l'autre est à contour.
4. **Verrou** : tant qu'aucun retour n'a été reçu, le bouton final est désactivé, grisé, en pointillés, avec un
   cadenas, et la phrase « Demande d'abord au moins un retour. » remplace l'aide.
5. **Serveur** : `redaction_can_submit_final()` refuse avec la raison `no_feedback` (chaîne
   `training_error_no_feedback`, en et fr), pour que l'appel direct de `mod_redaction_submit_final` soit refusé
   explicitement, et pas seulement grisé à l'écran.

## Mes décisions sur les cas limites (à corriger si besoin)

- « Retour reçu » = au moins une correction **appliquée, non finale** sur la copie (`status = 'applied'`, `finale = 0`).
  Une correction en échec rend l'essai et ne compte pas ; une correction en cours non plus.
- Copie rouverte par le professeur : les retours reçus avant restent comptés ; le verrou ne revient pas.
- Retours impossibles (entraînement ou IA désactivés sur l'activité) : pas de verrou, sinon l'élève ne pourrait jamais
  rendre.
- **Soumission automatique à la date limite** (`task/auto_submit_deadline`) : inchangée, une copie sans retour est
  quand même rendue à la date limite.
- Écran du professeur, barre du bas sur écran étroit : inchangés.

## Hors périmètre

Mode classique (non sujets), textes des retours, nombre de retours, seuils.

## Tests

`tests/cli/verif_ecran.php` et `tests/cli/verif_finale.php` : verrou avant le premier retour, levée après un retour
appliqué, pas de levée sur un échec, refus serveur `no_feedback`, étape en cours de la frise dans chaque situation,
copie rouverte, entraînement désactivé.
