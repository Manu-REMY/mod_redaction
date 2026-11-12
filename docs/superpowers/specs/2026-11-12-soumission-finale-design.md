# Mode sujets : essais d'entraînement, soumission finale et volet « Mon avancement »

Demande 16 (12/11/2026). Décidé par Emmanuel : « idem mod_gestionprojet » (décision D-040 du registre, adoptable ailleurs).

## Intention

Aujourd'hui, en mode « sujets attribués », chaque clic sur « Soumettre » est un essai corrigé par l'IA, noté, et la
meilleure note est gardée. Il n'y a pas de version finale. Emmanuel veut le fonctionnement de mod_gestionprojet :

- l'élève **demande des retours** à l'IA pour s'améliorer (ses essais, en nombre limité) ;
- il **rend une seule fois**, par une **soumission finale** ; seule cette version est notée pour de bon, et le niveau
  se valide à ce moment-là, sans relecture du professeur (« Je ne relis rien ») ;
- ce qui n'est **pas soumis à la date limite l'est automatiquement**, puis verrouillé.

Et la mise en page de mod_gestionprojet : la rédaction à gauche, un **volet « Mon avancement » à droite**, toujours
visible, avec le retour de l'IA et ses notes, les essais restants et le bouton de soumission finale.

## Objectifs

1. Deux boutons distincts : « Demander un retour (N restants) » et « Soumettre ma version finale ».
2. Un essai = une correction complète par l'IA (fond, langue, note), affichée à l'élève, **qui ne va pas au carnet**.
3. La soumission finale = une correction complète, **la seule** qui fait la note du carnet et le niveau validé.
4. Après la soumission finale : réponses en lecture seule, note et niveau affichés, plus aucun bouton.
5. Soumission automatique à la date limite effective (date de l'activité, dérogations de groupe ou d'élève).
6. Volet à droite comme mod_gestionprojet (largeur 380 px, collant au défilement, barre en bas sur écran étroit).

## Non-objectifs

- Pas de réglage pour revenir à l'ancien fonctionnement (meilleure note gardée) : toutes les activités d'Emmanuel en
  mode sujets passent au nouveau. Choix de l'agent : un réglage de plus pour un usage que personne n'a.
- Le mode classique (sans sujet) n'est pas touché (D-047 : il n'est plus utilisé).
- Le plafond de langue (demande 3) viendra ensuite ; il s'appliquera à la note de la soumission finale comme aux essais.

## Anti-objectifs (un échec même si « ça marche »)

- Un essai qui finit au carnet de notes ou dans la vie de classe.
- Une copie soumise qui reste sans note parce que la correction finale a échoué (repli silencieux).
- Deux soumissions finales pour la même copie (double clic, deux membres du groupe en même temps).
- Une note finale différente du dernier essai alors que l'élève n'a rien changé.

## Fonctionnement détaillé

### Essais (« Demander un retour »)

- Même correction qu'aujourd'hui (un appel par sujet + un appel de langue), même affichage du retour : note sur 20,
  commentaire par sujet, langue par dimension, niveau atteint « à titre indicatif ».
- Compte dans `training_count`, limité par `training_max_attempts` (8 pour la poutre, 5 pour ROB4).
- Interdit pendant qu'une correction est en cours, et après la soumission finale.
- L'avertissement « réponds par des phrases » (demande 9) reste sur ce bouton.

### Soumission finale (« Soumettre ma version finale »)

- Possible à tout moment avant la date limite, **même sans avoir demandé de retour** et même quand les essais sont
  épuisés. Exige une réponse non vide à chaque sujet.
- Fenêtre de confirmation de Moodle : « Ta version finale sera notée et tu ne pourras plus la modifier. Envoyer ? »,
  avec l'avertissement des phrases s'il y a lieu.
- **Texte inchangé depuis le dernier essai réussi** (décidé par Emmanuel le 12/11 : « S'il n'a rien changé on garde la
  note ») : la correction de cet essai devient la correction finale, sans nouvel appel à l'IA. « Inchangé » = chaque
  réponse identique, caractère pour caractère après `notes_sujets::texte_reponse`, au texte que cet essai a corrigé, et
  le réglage de langue identique. Sinon, nouvelle correction complète.
- Effet immédiat : copie verrouillée (`status = 1`, `timesubmitted`), correction finale mise en file (ou reprise de
  l'essai, ci-dessus). En groupe, la
  copie du groupe est verrouillée pour tous ses membres ; un verrou (comme aujourd'hui `redaction_submit_<id>`) empêche
  deux soumissions finales.
- La note de cette correction devient la note de la copie et va au carnet ; le niveau validé suit (`sujets_niveau`).
- La note posée à la main par le professeur garde la priorité, comme aujourd'hui.

### Correction finale qui échoue

- Elle n'est jamais perdue : une tâche planifiée la relance toutes les 10 minutes, **par une nouvelle ligne de
  correction à chaque fois** (l'historique des refus reste visible pour la jauge), jusqu'à ce qu'elle réussisse.
  L'élève voit « Ta version finale est en cours de correction ». Le professeur voit une alerte sur l'écran de notation
  dès le 3e échec. *Choix de l'agent.*
- Plafond : au plus 12 relances (2 heures). Au-delà, plus de relance automatique : la copie reste « soumise, sans note »
  avec une alerte et un bouton « Relancer la correction » pour le professeur. Une erreur qui ne se corrige pas seule
  (corrigé vide, sujet supprimé) ne se relance pas : alerte directe. *Choix de l'agent.*
- Les refus d'Albert (429) restent écrits dans `error_message` avec « 429 » ou « limit per minute » : la jauge de la vie
  de classe les compte (moodle-db, D-048).

### Date limite

- Date effective = celle de l'activité ou d'une dérogation (`redaction_get_effective_deadline`, déjà existante).
- La tâche `auto_submit_deadline` traite aussi le mode sujets : copie en brouillon après la date → soumission finale
  automatique, avec les réponses enregistrées. *Choix de l'agent :*
  - copie **à moitié remplie** : corrigée telle quelle ; un sujet sans réponse vaut 0 sur sa part (pas d'appel à l'IA
    pour lui) ; la langue est jugée sur ce qui est écrit ;
  - copie **sans aucune réponse** : verrouillée, sans correction et sans note (niveau non validé).
- Après la date : plus d'essai ni de soumission finale (D-040 : copie verrouillée).
- Sans date limite, rien n'est soumis automatiquement. En production le 12/11, aucune activité en mode sujets n'a de
  date ni de dérogation : la mise à jour ne soumet rien d'un coup.

### Professeur

- Écran de notation : colonne « État » : brouillon / soumise le … / correction finale en cours / notée. Les essais
  restent listés dans le détail, marqués « essai » ; la correction finale est marquée « finale ».
- Action « Rouvrir la copie » : repasse en brouillon, garde l'historique et le compteur d'essais ; la note finale sort
  du carnet jusqu'à la prochaine soumission finale. Si la date limite est passée, l'action demande une nouvelle date
  et crée une dérogation pour l'élève ou le groupe (sinon la tâche resoumettrait la copie dans les 5 minutes).
  *Choix de l'agent.*

### Volet « Mon avancement » (à droite)

De haut en bas, comme mod_gestionprojet :

1. **État** : « ✎ Brouillon » / « … Correction en cours » / « ✓ Soumise le … ».
2. **Note** : dernière note d'essai « à titre indicatif » tant que la copie n'est pas soumise ; note finale ensuite.
   Bandeau du niveau (« Niveau « presque maîtrisé » validé / pas encore validé », seuil rappelé).
3. **Dernier retour de l'IA** : commentaire par sujet et sa note, puis le bloc de langue (paliers, fautes citées).
4. **Essais restants** et bouton « Demander un retour (N restants) ».
5. Bouton **« Soumettre ma version finale »**.
6. Date limite, si elle existe : « À rendre avant le … ».

À gauche : la consigne, puis les sujets et leurs zones de réponse, sans changement.
Écran étroit : volet sous la rédaction, et une barre en bas avec l'état et les deux boutons.
Couleurs : bleu = validé, orange = pas encore validé, toujours avec ✓ / ✗ et le mot (daltonisme).

## Données

- `redaction_ai_evaluations.finale` (int 1, défaut 0) : 1 pour la correction de la soumission finale.
- `redaction_ai_evaluations.empreinte` (char 40) : empreinte (sha1) des réponses corrigées, sujet par sujet, au moment
  de la correction ; sert à reconnaître un texte inchangé à la soumission finale.
- Note de la copie (`redaction_get_user_grades_sujets`, `notes_sujets::meilleure`) : **la correction finale réussie**,
  ou la note du professeur ; plus jamais la meilleure des essais.
- La note finale **ne dépend pas du réglage de langue actuel** : changer les points de langue après coup ne l'efface
  pas (le filtre `langue_points` ne vaut que pour l'affichage des essais). Le plafond (demande 3) ne s'appliquera
  qu'aux soumissions finales faites après son réglage. *Choix de l'agent.*
- Mise à jour : les corrections existantes deviennent des essais (`finale = 0`). En production le 12/11, ROB4 et la
  poutre n'ont aucune correction : aucune note perdue. Les copies déjà notées ailleurs (préprod) perdent leur note
  jusqu'à leur soumission finale ; la tâche `mettre_a_jour_carnet` met le carnet à jour.

## Conséquences hors du plugin (à écrire dans a-traiter)

- **Vie de classe** (agent des activités), **bloquant** : `reussites.php` (`reussites_redaction_moodle`) lit
  directement `redaction_ai_evaluations.parsed_grade`, pas le carnet. Sans changement, un essai validerait le niveau.
  Il doit ne lire que les lignes `finale = 1` (plus la note du professeur). Ordre de mise en production : le plugin
  (colonne `finale`) et la vie de classe le même jour, la vie de classe d'abord prête.
- **Pilotage** (agent des activités) : écrire la date limite de chaque activité en mode sujets par classe, sous forme de
  dérogation de groupe (`redaction_overrides`), comme pour `gestionprojet_deadline_group`.
- **mod_gestionprojet** : mêmes libellés de boutons et d'états (demande 12).

## Tests

- Purs : la note de la copie ne prend que la finale (essais ignorés, même meilleurs) ; note du professeur prioritaire.
- CLI préprod : essai sans carnet ; finale au carnet ; double soumission finale refusée ; échec puis relance ; date
  limite → soumission automatique ; copie vide à la date limite → verrouillée sans note ; mode groupe et mode élève.
- Script de l'écran élève (puppeteer) : deux boutons, confirmation, volet après soumission.
