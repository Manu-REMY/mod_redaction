<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * French strings for mod_redaction.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Module info.
$string['modulename'] = 'Rédaction';
$string['modulenameplural'] = 'Rédactions';
$string['pluginname'] = 'Rédaction';
$string['pluginadministration'] = 'Administration Rédaction';
$string['modulename_help'] = 'Le module Rédaction permet aux enseignants de proposer une activité de rédaction de texte avec des consignes détaillées et une évaluation (manuelle ou assistée par IA).';

// Capabilities.
$string['redaction:addinstance'] = 'Ajouter une nouvelle activité Rédaction';
$string['redaction:view'] = 'Voir l\'activité Rédaction';
$string['redaction:editconsignes'] = 'Modifier les consignes';
$string['redaction:submit'] = 'Soumettre une rédaction';
$string['redaction:viewallsubmissions'] = 'Voir toutes les soumissions';
$string['redaction:grade'] = 'Noter les rédactions';
$string['redaction:viewhistory'] = 'Voir l\'historique des versions';

// Settings.
$string['autosave_settings'] = 'Sauvegarde automatique';
$string['autosave_interval'] = 'Intervalle de sauvegarde';
$string['autosave_interval_help'] = 'Fréquence de sauvegarde automatique du travail des élèves (en secondes).';
$string['submissionsettings'] = 'Paramètres de soumission';
$string['groupsubmission'] = 'Soumission par groupe';
$string['groupsubmission_help'] = 'Si activé, les élèves soumettent leur travail en tant que groupe. Tous les membres du groupe partagent la même rédaction et la même note.';

// AI Settings.
$string['ai_settings'] = 'Évaluation par IA';
$string['ai_enabled'] = 'Activer l\'évaluation IA';
$string['ai_enabled_help'] = 'Permet d\'utiliser l\'intelligence artificielle pour évaluer automatiquement les rédactions des élèves.';
$string['ai_provider'] = 'Fournisseur IA';
$string['ai_provider_help'] = 'Sélectionnez le service d\'IA à utiliser pour l\'évaluation.';
$string['ai_provider_select'] = 'Sélectionner un fournisseur...';
$string['ai_provider_builtin'] = 'Clé intégrée';
$string['ai_provider_required'] = 'Veuillez sélectionner un fournisseur IA.';
$string['ai_api_key'] = 'Clé API';
$string['ai_api_key_help'] = 'Votre clé API pour le fournisseur sélectionné. Cette clé sera chiffrée.';
$string['ai_api_key_builtin_notice'] = 'Albert utilise une clé API intégrée. Aucune configuration requise.';
$string['ai_api_key_required'] = 'La clé API est requise pour ce fournisseur.';
$string['ai_test_connection'] = 'Tester la connexion';
$string['ai_auto_apply'] = 'Appliquer automatiquement les notes IA';
$string['ai_auto_apply_help'] = 'Si activé, les notes générées par l\'IA seront automatiquement appliquées sans validation de l\'enseignant.';

// Home page.
$string['home'] = 'Accueil';
$string['teacher_section'] = 'Espace enseignant';
$string['student_section'] = 'Espace élève';
$string['consignes'] = 'Consignes';
$string['consignes_desc'] = 'Définir les consignes et critères d\'évaluation pour l\'activité.';
$string['correction_model'] = 'Modèle de correction';
$string['correction_model_desc'] = 'Définir le modèle de réponse et les instructions pour l\'évaluation IA.';
$string['my_redaction'] = 'Ma rédaction';
$string['my_redaction_desc'] = 'Rédiger et soumettre votre travail.';
$string['grading'] = 'Notation';
$string['grading_desc'] = 'Consulter et noter les soumissions des élèves.';

// Consignes page.
$string['consignes_title'] = 'Titre de l\'activité';
$string['consignes_content'] = 'Consignes détaillées';
$string['consignes_content_help'] = 'Décrivez précisément ce que les élèves doivent rédiger.';
$string['consignes_criteres'] = 'Critères d\'évaluation';
$string['consignes_criteres_help'] = 'Listez les critères qui seront utilisés pour évaluer les rédactions.';
$string['consignes_documents'] = 'Ressources et documents';
$string['consignes_documents_help'] = 'Liens vers des ressources ou documents utiles pour la rédaction.';
$string['consignes_locked'] = 'Consignes verrouillées';
$string['consignes_unlocked'] = 'Consignes déverrouillées';
$string['lock_consignes'] = 'Verrouiller les consignes';
$string['unlock_consignes'] = 'Déverrouiller les consignes';
$string['consignes_not_ready'] = 'Les consignes ne sont pas encore disponibles.';
$string['confirm_lock'] = 'Êtes-vous sûr de vouloir verrouiller les consignes ? Les élèves pourront voir le contenu.';

// Redaction page.
$string['redaction'] = 'Rédaction';
$string['redaction_title'] = 'Titre';
$string['redaction_title_placeholder'] = 'Donnez un titre à votre rédaction';
$string['redaction_content'] = 'Contenu';
$string['redaction_content_placeholder'] = 'Rédigez votre texte ici...';
$string['status_draft'] = 'Brouillon';
$string['status_submitted'] = 'Soumis';
$string['submit_redaction'] = 'Soumettre la rédaction';
$string['submit_confirm'] = 'Êtes-vous sûr de vouloir soumettre votre rédaction ? Vous ne pourrez plus la modifier après soumission.';
$string['submitted_on'] = 'Soumis le {$a}';
$string['unlock_submission'] = 'Déverrouiller pour modification';

// Correction model page.
$string['modele_reponse'] = 'Modèle de réponse';
$string['modele_reponse_help'] = 'Rédigez un exemple de réponse attendue. Cela aidera l\'IA à évaluer les productions.';
$string['modele_reponse_placeholder'] = 'Rédigez ici un exemple de réponse attendue...';
$string['grille_criteres'] = 'Grille de critères';
$string['grille_criteres_help'] = 'Définissez les critères de notation avec leur pondération (format JSON).';
$string['grille_criteres_placeholder'] = 'Format JSON : [{"name": "Critère", "weight": 5, "description": "..."}]';
$string['grille_criteres_example'] = 'Exemple de format JSON pour la grille de critères :';
$string['ai_instructions'] = 'Instructions pour l\'IA';
$string['ai_instructions_help'] = 'Donnez des instructions spécifiques à l\'IA pour guider son évaluation.';
$string['ai_instructions_placeholder'] = 'Exemple : Évalue la pertinence de la réponse, la qualité de l\'argumentation...';
$string['ai_disabled_warning'] = 'L\'évaluation IA n\'est pas activée pour cette activité.';
$string['dates_section'] = 'Dates';
$string['submission_date'] = 'Date de soumission attendue';
$string['deadline_date'] = 'Date limite';

// Grading page.
$string['grade'] = 'Note';
$string['grade_outof'] = 'Note sur {$a}';
$string['feedback'] = 'Commentaires';
$string['feedback_placeholder'] = 'Entrez vos commentaires pour l\'élève...';
$string['save_grade'] = 'Enregistrer la note';
$string['grade_saved'] = 'Note enregistrée';
$string['no_submission'] = 'Aucune soumission';
$string['not_submitted'] = 'Non soumis';
$string['view_submission'] = 'Voir la soumission';
$string['evaluate_ai'] = 'Évaluer avec l\'IA';
$string['apply_ai_grade'] = 'Appliquer la note IA';
$string['ai_evaluation'] = 'Évaluation IA';
$string['ai_evaluation_pending'] = 'Évaluation IA en cours...';
$string['ai_evaluation_complete'] = 'Évaluation IA terminée';
$string['ai_evaluation_failed'] = 'Échec de l\'évaluation IA';
$string['ai_grade'] = 'Note IA';
$string['ai_feedback'] = 'Commentaires IA';
$string['no_ai_evaluation'] = 'Aucune évaluation IA n\'a encore été effectuée pour cette soumission.';
$string['reevaluate'] = 'Réévaluer';
$string['unlock_confirm'] = 'Êtes-vous sûr de vouloir déverrouiller cette soumission ? L\'élève pourra la modifier à nouveau.';

// History.
$string['version_history'] = 'Historique des versions';
$string['version'] = 'Version {$a}';
$string['version_saved_by'] = 'Sauvegardé par {$a->name} le {$a->date}';
$string['word_count'] = '{$a} mots';
$string['char_count'] = '{$a} caractères';
$string['view_version'] = 'Voir cette version';
$string['compare_versions'] = 'Comparer les versions';
$string['no_history'] = 'Aucun historique disponible';

// Autosave messages.
$string['saving'] = 'Sauvegarde en cours...';
$string['saved'] = 'Sauvegardé';
$string['save_error'] = 'Erreur de sauvegarde';
$string['unsaved_changes'] = 'Modifications non sauvegardées';

// Errors.
$string['error:noconsignes'] = 'Les consignes n\'ont pas encore été définies par l\'enseignant.';
$string['error:nosubmission'] = 'Aucune soumission trouvée.';
$string['error:alreadysubmitted'] = 'Cette rédaction a déjà été soumise.';
$string['error:notsubmitted'] = 'Cette rédaction n\'a pas encore été soumise.';
$string['error:cannotgrade'] = 'Vous n\'avez pas la permission de noter.';
$string['error:invalidgrade'] = 'La note doit être comprise entre 0 et 20.';

// Misc.
$string['group'] = 'Groupe';
$string['student'] = 'Élève';
$string['lastmodified'] = 'Dernière modification';
$string['actions'] = 'Actions';
$string['back_to_home'] = 'Retour à l\'accueil';
$string['all_groups'] = 'Tous les groupes';
$string['all_students'] = 'Tous les élèves';
$string['filter'] = 'Filtrer';
$string['search'] = 'Rechercher';
$string['complete'] = 'Complet';
$string['incomplete'] = 'Incomplet';

// Task.
$string['task_evaluate_submission'] = 'Évaluer une soumission avec l\'IA';

// AI Errors.
$string['ai_not_enabled'] = 'L\'évaluation IA n\'est pas activée pour cette activité.';
$string['ai_request_failed'] = 'Échec de la requête IA';
$string['ai_invalid_response'] = 'Réponse IA invalide';
$string['ai_parse_error'] = 'Erreur de parsing de la réponse IA';
$string['ai_unknown_provider'] = 'Fournisseur IA inconnu : {$a}';

// AI Generation.
$string['ai_generate_criteria'] = 'Génération assistée par IA';
$string['ai_generate_criteria_help'] = 'Utilisez l\'IA pour générer automatiquement les critères d\'évaluation et les instructions basés sur les consignes que vous avez définies.';
$string['ai_generate_button'] = 'Générer avec l\'IA';
$string['ai_generate_need_consignes'] = 'Veuillez d\'abord définir et verrouiller les consignes avant de générer les critères.';
$string['ai_generate_confirm_overwrite'] = 'Cela va écraser la grille de critères et les instructions IA existantes. Continuer ?';
$string['ai_generate_success'] = 'Critères et instructions générés avec succès. Veuillez vérifier et ajuster si nécessaire.';
$string['ai_generate_error'] = 'Erreur lors de la génération des critères : {$a}';
$string['ai_generating'] = 'Génération en cours...';

// Admin Settings.
$string['settings_albert_heading'] = 'Configuration Albert (Etalab)';
$string['settings_albert_heading_desc'] = 'Albert est l\'IA souveraine française. Configurez ici la clé API pour permettre aux enseignants d\'utiliser Albert sans avoir à fournir leur propre clé.';
$string['settings_albert_api_key'] = 'Clé API Albert';
$string['settings_albert_api_key_desc'] = 'Clé API pour le service Albert (Etalab). Cette clé sera utilisée pour toutes les activités utilisant Albert comme fournisseur IA.';

// AI Evaluation Details.
$string['ai_criteria_details'] = 'Détail des critères d\'évaluation';
$string['ai_general_feedback'] = 'Commentaire général';
$string['level_excellent'] = 'Excellent';
$string['level_good'] = 'Bien';
$string['level_medium'] = 'À améliorer';
$string['level_low'] = 'Insuffisant';

// Index page.
$string['noredactions'] = 'Il n\'y a pas d\'activités Rédaction dans ce cours.';

// Privacy API.
$string['privacy:metadata:redaction_submission'] = 'Informations sur les soumissions des élèves pour les activités Rédaction.';
$string['privacy:metadata:redaction_submission:userid'] = 'L\'identifiant de l\'utilisateur qui a fait la soumission.';
$string['privacy:metadata:redaction_submission:groupid'] = 'L\'identifiant du groupe pour les soumissions de groupe.';
$string['privacy:metadata:redaction_submission:titre'] = 'Le titre de la soumission.';
$string['privacy:metadata:redaction_submission:contenu'] = 'Le contenu de la rédaction de l\'élève.';
$string['privacy:metadata:redaction_submission:status'] = 'Le statut de la soumission (brouillon ou soumis).';
$string['privacy:metadata:redaction_submission:grade'] = 'La note reçue pour la soumission.';
$string['privacy:metadata:redaction_submission:feedback'] = 'Le commentaire fourni par l\'enseignant.';
$string['privacy:metadata:redaction_submission:timesubmitted'] = 'L\'heure à laquelle la soumission a été effectuée.';
$string['privacy:metadata:redaction_submission:timecreated'] = 'L\'heure de création de l\'enregistrement de soumission.';
$string['privacy:metadata:redaction_submission:timemodified'] = 'L\'heure de la dernière modification de la soumission.';

$string['privacy:metadata:redaction_history'] = 'Informations sur l\'historique des versions des soumissions.';
$string['privacy:metadata:redaction_history:userid'] = 'L\'identifiant de l\'utilisateur associé à l\'entrée d\'historique.';
$string['privacy:metadata:redaction_history:titre'] = 'Le titre au moment de la sauvegarde.';
$string['privacy:metadata:redaction_history:contenu'] = 'Le contenu au moment de la sauvegarde.';
$string['privacy:metadata:redaction_history:version_number'] = 'Le numéro de version de cette sauvegarde.';
$string['privacy:metadata:redaction_history:word_count'] = 'Le nombre de mots au moment de la sauvegarde.';
$string['privacy:metadata:redaction_history:char_count'] = 'Le nombre de caractères au moment de la sauvegarde.';
$string['privacy:metadata:redaction_history:saved_by'] = 'L\'identifiant de l\'utilisateur qui a sauvegardé cette version.';
$string['privacy:metadata:redaction_history:timecreated'] = 'L\'heure à laquelle cette version a été sauvegardée.';

$string['privacy:metadata:redaction_ai_evaluations'] = 'Informations sur les évaluations IA des soumissions.';
$string['privacy:metadata:redaction_ai_evaluations:userid'] = 'L\'identifiant de l\'utilisateur dont la soumission a été évaluée.';
$string['privacy:metadata:redaction_ai_evaluations:provider'] = 'Le fournisseur IA utilisé pour l\'évaluation.';
$string['privacy:metadata:redaction_ai_evaluations:model'] = 'Le modèle IA utilisé pour l\'évaluation.';
$string['privacy:metadata:redaction_ai_evaluations:raw_response'] = 'La réponse brute du service IA.';
$string['privacy:metadata:redaction_ai_evaluations:parsed_grade'] = 'La note extraite de la réponse IA.';
$string['privacy:metadata:redaction_ai_evaluations:parsed_feedback'] = 'Le commentaire extrait de la réponse IA.';
$string['privacy:metadata:redaction_ai_evaluations:criteria_json'] = 'Les scores détaillés par critère au format JSON.';
$string['privacy:metadata:redaction_ai_evaluations:status'] = 'Le statut de l\'évaluation IA.';
$string['privacy:metadata:redaction_ai_evaluations:applied_by'] = 'L\'identifiant de l\'enseignant qui a appliqué la note IA.';
$string['privacy:metadata:redaction_ai_evaluations:timecreated'] = 'L\'heure de création de l\'évaluation IA.';

$string['privacy:metadata:ai_provider'] = 'Le contenu des soumissions est envoyé à des services IA externes pour évaluation.';
$string['privacy:metadata:ai_provider:submission_content'] = 'Le contenu de la rédaction de l\'élève est envoyé au fournisseur IA pour évaluation.';

// Teacher Dashboard.
$string['dashboard_progress'] = 'Progression des soumissions';
$string['dashboard_submitted'] = 'Soumises';
$string['dashboard_graded'] = 'Notées';
$string['dashboard_average'] = 'Moyenne de la classe';
$string['dashboard_no_grades'] = 'Aucune note disponible';
$string['dashboard_ai_stats'] = 'Évaluations IA';
$string['dashboard_pending'] = 'en attente';
$string['dashboard_completed'] = 'terminées';
$string['dashboard_applied'] = 'appliquées';
$string['dashboard_ai_summary'] = 'Synthèse IA des feedbacks';
$string['dashboard_refresh'] = 'Actualiser';
$string['dashboard_difficulties'] = 'Difficultés identifiées';
$string['dashboard_strengths'] = 'Points forts';
$string['dashboard_recommendations'] = 'Recommandations pédagogiques';
$string['dashboard_no_difficulties'] = 'Aucune difficulté identifiée';
$string['dashboard_no_strengths'] = 'Aucun point fort identifié';
$string['dashboard_no_recommendations'] = 'Aucune recommandation';
$string['dashboard_observation'] = 'Observation générale';
$string['dashboard_analyzed'] = 'Analysées';
$string['dashboard_submissions'] = 'soumissions';
$string['dashboard_provider'] = 'Fournisseur';
$string['dashboard_no_summary'] = 'Aucune synthèse disponible';
$string['dashboard_summary_hint'] = 'La synthèse sera générée automatiquement après quelques évaluations IA.';
$string['dashboard_token_usage'] = 'Consommation de tokens IA';
$string['dashboard_total_tokens'] = 'Tokens totaux';
$string['dashboard_prompt_tokens'] = 'Tokens prompt';
$string['dashboard_completion_tokens'] = 'Tokens réponse';
$string['dashboard_evaluations'] = 'Évaluations';
$string['dashboard_by_provider'] = 'Par fournisseur';
$string['dashboard_requests'] = 'Requêtes';
$string['dashboard_tokens'] = 'Tokens';
$string['dashboard_students'] = 'Élèves';
$string['dashboard_grade_distribution'] = 'Répartition des notes';
$string['dashboard_hide'] = 'Masquer le tableau de bord';
$string['dashboard_show'] = 'Afficher le tableau de bord';
$string['dashboard_no_data'] = 'Pas assez de données pour générer une synthèse.';
$string['dashboard_summary_generated'] = 'Synthèse générée avec succès.';

// Unknown user/group.
$string['unknowngroup'] = 'Groupe inconnu';
$string['unknownuser'] = 'Utilisateur inconnu';
