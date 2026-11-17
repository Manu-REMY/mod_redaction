# Fenêtre du retour IA (mode sujets) — conception

Date : 17/11/2026. Maquettes : https://claude.ai/artifact/84qUpR3cWvy4z5Fj4igXtc. Choix d'Emmanuel : cadre **A** (deux
colonnes), étape 3 (remise proposée au seuil), idées **1, 2 et 3**.

## Intention

Les élèves ne repèrent pas les conseils ni ce qui manque : le retour n'apparaît que dans le volet de droite. Le retour
doit s'ouvrir au centre de l'écran, dire clairement ce qui va et ce qui ne va pas, et proposer la remise dès que le seuil
est atteint.

## Approches

- **Retenue : fenêtre rendue par le serveur, ouverte après le rechargement.** La page se recharge déjà à la fin d'une
  correction ; le gabarit contient la fenêtre (`<dialog>` natif) remplie avec les données déjà calculées pour le volet.
  Le script l'ouvre si cet onglet attendait une correction (marque en `sessionStorage`, posée pendant l'attente).
- Écartée : construire le résultat en JS à partir d'un service enrichi. Duplique la mise en forme du volet, plus de code.
- `<dialog>` plutôt que les fenêtres Bootstrap / `core/modal` : le plugin vise Moodle 4.5 (Bootstrap 4) et 5.x
  (Bootstrap 5), dont les fenêtres diffèrent ; `<dialog>` gère seul le premier plan et le focus.

## Conception

1. **Attente** : dès la demande (ou à l'ouverture d'une page dont la correction tourne), fenêtre avec l'anneau minuté
   actuel, trois étapes vraies (copie envoyée ; l'IA corrige, « en file d'attente » tant que la tâche n'a pas démarré ;
   ton retour s'affiche) et la phrase de patience. Le serveur ne dit pas quand l'IA passe des explications à la langue :
   pas d'étape inventée. Pas de bouton de fermeture, Échap sans effet. Même chose pour la version finale.
2. **Résultat sous le seuil (cadre A)** : bandeau orange ✗ « 10,4/20 · il faut 12 », « encore 1,6 point » ; compteur
   « Éléments attendus trouvés : 2 sur 4 » (cases pleines) ; deux colonnes **✓ Ce qui va** (« juste » de chaque sujet,
   nommé) et **✗ Ce qui manque** (« à préciser ») ; encadré **→ Pour ton prochain retour** (la « piste » de chaque sujet) ;
   phrase « ta langue t'empêche… » quand elle s'applique ; fautes de langue barrées puis corrigées (idée 3). Bouton
   « Retourner à ma copie », croix, Échap.
3. **Résultat au seuil** : pastille ✓, « 13,1/20 · seuil atteint », rappel que seule la version finale compte ; détail
   (cadre A) replié ; boutons « Améliorer encore (N retours restants) » (absent à 0) et **« 3 Soumettre ma version
   finale »** plein, qui ferme la fenêtre et ouvre la confirmation actuelle.
4. **Version finale notée** : la fenêtre donne la note et le niveau validé ou non.
5. **Correction en échec** : la fenêtre dit que l'essai est rendu et invite à redemander.
6. **Idée 1** : la piste de chaque sujet reste sous sa zone de réponse, tant que la copie n'est pas rendue.
7. **Idée 2** : bandeau bleu en haut de l'écran élève quand le dernier retour atteint le seuil et que la remise est
   possible (pas pendant une correction) : « ✓ Seuil atteint (13,1/20) » + bouton de remise ; la barre du bas sur écran
   étroit reprend le bouton.

## Mes décisions (à corriger si besoin)

- Compteur d'éléments : le plugin enregistre désormais la liste des éléments trouvés (`attendus`) dans chaque correction ;
  total = repères E1, E2… du corrigé. Corrections anciennes : pas de compteur. Les éléments ne sont jamais nommés.
- Dans une activité de groupe, tout membre dont l'onglet attendait la correction voit la fenêtre.
- Sans `sessionStorage` (navigation privée bloquée) : pas de fenêtre de résultat, le volet de droite suffit.
- Le volet de droite garde le dernier retour, **avec le code couleur de la fenêtre** (demande d'Emmanuel, 17/11) : par
  sujet, ✓ Ce qui va (fond bleu clair), ✗ Ce qui manque (cadre orange), → Pour ton prochain retour (cadre bleu). Les
  libellés « Ce qui est juste / À préciser / Piste » deviennent « Ce qui va / Ce qui manque / Pour ton prochain retour »,
  partout sur l'écran élève.

## Hors périmètre

Écran du professeur, mode classique, textes produits par l'IA, seuils.

## Tests

`verif_ecran.php` : données de la fenêtre (colonnes, piste, compteur, fautes), bandeau au seuil, piste sous la réponse,
absence après remise, échec. Rendu vérifié à l'écran (bureau et téléphone).
