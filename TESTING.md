# TESTING.md - Configuration des Tests

## Accès Plateforme de Préproduction

### Navigateur (Kapture/Chrome MCP)
```
URL Moodle     : https://preprod.ent-occitanie.com/
```

### Comptes de Test
| Rôle | Identifiant | Mot de passe | Notes |
|------|-------------|--------------|-------|
| Enseignant | prof | <password — voir TESTING.local.md> | Droits de création d'activité |
| Élève 1 | 3a1 | <password — voir TESTING.local.md> | Groupe A |
| Élève 2 | 3a2 | <password — voir TESTING.local.md> | Groupe A |
| Élève 3 | 3b1 | <password — voir TESTING.local.md> | Groupe B (pour test multi-groupe) |

### Accès SSH/CLI
```
Hôte           : favi5410.odns.fr
Utilisateur    : favi5410
Méthode auth   : <password — voir TESTING.local.md>
Chemin Moodle  : /preprod.ent-occitanie.com
```

### Cours de Test
```
Nom du cours   : TEST
ID du cours    : 2
Groupes        : 3A, 3B
```

---

## Checklist de Qualification par Phase

### Phase 1 : Configuration Flexible des Phases
- [ ] L'enseignant peut choisir "Piloté/Élève/Désactivé" pour chaque phase
- [ ] Les élèves voient en lecture seule les phases pilotées
- [ ] Les élèves peuvent éditer les phases "élève"
- [ ] Les phases désactivées sont masquées
- [ ] La migration des données existantes fonctionne

### Phase 2 : Stockage Clé API
- [ ] L'enseignant peut saisir une clé API dans les paramètres
- [ ] La clé est chiffrée en base de données
- [ ] Le test de connexion API fonctionne
- [ ] Seuls les enseignants voient la configuration API

### Phase 3 : Modèles de Correction
- [ ] L'enseignant peut saisir un modèle pour chaque phase élève
- [ ] Les mots-clés et pondérations sont enregistrés
- [ ] Le modèle est validé avant activation de la phase
- [ ] Prévisualisation du modèle fonctionnelle

### Phase 4 : Moteur d'Évaluation IA
- [ ] La soumission élève déclenche l'évaluation IA
- [ ] L'API IA est appelée correctement
- [ ] La réponse IA est parsée (note + feedback)
- [ ] L'évaluation est stockée en base
- [ ] L'enseignant peut modifier l'évaluation IA

### Phase 5 : Intégration Carnet de Notes
- [ ] Chaque phase crée un item de note dans Moodle
- [ ] Les notes IA sont synchronisées au carnet
- [ ] La pondération par phase fonctionne
- [ ] L'évaluation par compétences fonctionne (si activée)

### Phase 6 : Qualité
- [ ] Export PDF fonctionnel
- [ ] Responsive design validé
- [ ] Tests de charge OK
- [ ] Documentation complète

---

## Scénarios de Test Fonctionnel

### Scénario 1 : Parcours Enseignant Complet
1. Connexion en tant qu'enseignant
2. Création d'une nouvelle activité "Gestion de Projet"
3. Configuration : phases 1-3 pilotées, phases 4-8 élèves
4. Saisie de la clé API
5. Remplissage des phases 1-3
6. Saisie des modèles de correction pour phases 4-8
7. Verrouillage et publication

### Scénario 2 : Parcours Élève Individuel
1. Connexion en tant qu'élève
2. Accès à l'activité
3. Consultation des phases 1-3 (lecture seule)
4. Complétion et soumission de chaque phase 4-8
5. Vérification autosave
6. Consultation des notes et feedbacks IA

### Scénario 3 : Parcours Élève Groupe
1. Connexion élève 1 (Groupe A)
2. Début de rédaction phase 4
3. Connexion élève 2 (Groupe A) en parallèle
4. Vérification synchronisation des données
5. Soumission par élève 1
6. Vérification que élève 2 voit la soumission
7. Vérification que les deux reçoivent la même note

### Scénario 4 : Évaluation IA
1. Élève soumet une phase
2. Vérification appel API (logs)
3. Vérification réponse parsée
4. Affichage note + feedback à l'élève
5. L'enseignant modifie la note IA
6. Vérification mise à jour carnet de notes

---

## Commandes CLI Utiles

```bash
# Purger le cache après modification
php admin/cli/purge_caches.php

# Voir les logs d'erreur
tail -f /var/log/apache2/error.log
# ou
tail -f [chemin_moodle]/moodledata/error.log

# Exécuter une mise à jour de la base
php admin/cli/upgrade.php

# Vérifier la version du plugin
php admin/cli/cfg.php --component=mod_gestionprojet

# Activer le mode debug
php admin/cli/cfg.php --name=debug --set=32767
php admin/cli/cfg.php --name=debugdisplay --set=1

# Voir les tâches planifiées
php admin/cli/scheduled_task.php --list

# Exécuter le cron
php admin/cli/cron.php
```

---

## Logs et Debugging

### Activer les logs détaillés
Dans `config.php` :
```php
$CFG->debug = (E_ALL | E_STRICT);
$CFG->debugdisplay = 1;
$CFG->debugstringids = 1;
```

### Logs AJAX autosave
Les erreurs autosave sont loguées dans :
```
[moodledata]/temp/gestionprojet_debug_[cmid].log
```

### Logs API IA (à implémenter)
```
[moodledata]/gestionprojet/ai_logs/[date]_[cmid].log
```

---

## Notes de Session

*Section pour documenter les observations lors des tests*

### [Date] - Session de test
- **Testeur** :
- **Version testée** :
- **Observations** :
- **Bugs identifiés** :
- **Actions correctives** :
