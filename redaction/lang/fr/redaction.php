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
$string['task_auto_submit_deadline'] = 'Soumettre automatiquement les brouillons à la date limite';
$string['auto_submitted_at_deadline'] = 'Soumis automatiquement à la date limite';

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
$string['ai_confidence'] = 'Fiabilité de l\'évaluation';
$string['ai_strengths'] = 'Points forts';
$string['ai_weaknesses'] = 'Axes d\'amélioration';
$string['ai_keywords'] = 'Analyse des mots-clés';
$string['ai_keywords_found'] = 'Mots-clés identifiés';
$string['ai_keywords_missing'] = 'Mots-clés manquants';
$string['ai_suggestions'] = 'Conseils pour progresser';
$string['ai_overall_appreciation'] = 'Appréciation globale';
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

// Messages AJAX.
$string['error:empty_content'] = 'Le contenu ne peut pas être vide.';
$string['error:rate_limit_exceeded'] = 'Limite de requêtes atteinte. Veuillez patienter avant de demander une nouvelle évaluation.';
$string['error:evaluation_cooldown'] = 'Veuillez patienter quelques minutes avant de demander une nouvelle évaluation pour cette soumission.';
$string['error:encryption_unavailable'] = 'Le chiffrement Moodle n\'est pas disponible. Ce plugin nécessite Moodle 4.5+.';
$string['ajax:submitted'] = 'Soumis';
$string['ajax:submit_failed'] = 'Échec de la soumission';
$string['ajax:invalid_submission'] = 'Soumission invalide';
$string['ajax:unlocked'] = 'Déverrouillé';
$string['ajax:unlock_failed'] = 'Échec du déverrouillage';
$string['ajax:invalid_action'] = 'Action invalide';
$string['ajax:invalid_json'] = 'Données JSON invalides';
$string['ajax:lock_updated'] = 'Statut de verrouillage mis à jour';
$string['ajax:consignes_locked'] = 'Les consignes sont verrouillées';
$string['ajax:saved'] = 'Sauvegardé';
$string['ajax:already_submitted'] = 'Déjà soumis';
$string['ajax:invalid_page'] = 'Page invalide';
$string['ai_provider_admin_key'] = 'Clé administrateur';
$string['ai_albert_no_key'] = 'La clé API Albert n\'est pas configurée. Veuillez contacter votre administrateur.';

// Paramètres de limitation.
$string['settings_rate_limit'] = 'Limite de fréquence des évaluations IA';
$string['settings_rate_limit_desc'] = 'Nombre maximum d\'évaluations IA par heure et par activité. Mettre 0 pour aucune limite (non recommandé).';

// Paramètres de tarification des tokens.
$string['settings_token_pricing_heading'] = 'Tarification des tokens';
$string['settings_token_pricing_heading_desc'] = 'Configurez le prix par million de tokens pour chaque fournisseur IA. Ces valeurs sont utilisées pour estimer les coûts sur le tableau de bord enseignant. Mettez-les à jour lorsque les fournisseurs modifient leurs tarifs.';
$string['settings_token_pricing'] = 'Tarification des tokens (JSON)';
$string['settings_token_pricing_desc'] = 'Prix par million de tokens en USD, au format JSON. Chaque fournisseur doit avoir des valeurs "input" et "output". Exemple : {"openai": {"input": 2.50, "output": 10.00}, "anthropic": {"input": 3.00, "output": 15.00}}';

// Événements.
$string['event_submission_created'] = 'Soumission créée';
$string['event_submission_submitted'] = 'Soumission soumise';
$string['event_grade_updated'] = 'Note mise à jour';
$string['event_ai_evaluation_requested'] = 'Évaluation IA demandée';
$string['event_ai_evaluation_completed'] = 'Évaluation IA terminée';
$string['event_ai_grade_applied'] = 'Note IA appliquée';

// Chaînes JS notation.
$string['js:evaluating'] = 'Évaluation en cours...';
$string['js:evaluate_with_ai'] = 'Évaluer avec l\'IA';
$string['js:words'] = 'mots';
$string['js:characters'] = 'caractères';
$string['js:no_history'] = 'Aucun historique disponible.';
$string['js:loading_error'] = 'Erreur de chargement.';
$string['js:connection_error'] = 'Erreur de connexion';

// Délai d'application automatique.
$string['settings_auto_apply_delay'] = 'Délai d\'application automatique (minutes)';
$string['settings_auto_apply_delay_desc'] = 'Délai en minutes avant l\'application automatique des notes IA. Mettre à 0 pour une application immédiate. Cela donne aux enseignants le temps de vérifier les notes avant leur application.';
$string['task_apply_ai_grade'] = 'Appliquer les notes IA en attente';
$string['status_pending_apply'] = 'Application en attente';

// Notifications.
$string['notification_submission_subject'] = 'Nouvelle soumission de {$a}';
$string['notification_submission_body'] = '{$a->student} a soumis sa rédaction dans l\'activité « {$a->activity} » du cours « {$a->course} ».';
$string['notification_submission_body_html'] = '<p><strong>{$a->student}</strong> a soumis sa rédaction dans l\'activité « <em>{$a->activity}</em> » du cours « <em>{$a->course}</em> ».</p>';
$string['notification_submission_small'] = 'Nouvelle soumission de {$a}';
$string['notification_grade_subject'] = 'Votre rédaction « {$a} » a été notée';
$string['notification_grade_body'] = 'Votre rédaction dans l\'activité « {$a->activity} » a été notée : {$a->grade}/20 dans le cours « {$a->course} ».';
$string['notification_grade_body_html'] = '<p>Votre rédaction dans l\'activité « <em>{$a->activity}</em> » a été notée : <strong>{$a->grade}/20</strong> dans le cours « <em>{$a->course}</em> ».</p>';
$string['notification_grade_small'] = '{$a->activity} : {$a->grade}/20';
$string['notification_ai_eval_subject'] = 'Évaluation IA terminée';
$string['notification_ai_eval_body'] = 'L\'évaluation IA pour {$a->student} dans l\'activité « {$a->activity} » est terminée. Note suggérée : {$a->grade}/20 (fournisseur : {$a->provider}).';
$string['notification_ai_eval_body_html'] = '<p>L\'évaluation IA pour <strong>{$a->student}</strong> dans l\'activité « <em>{$a->activity}</em> » est terminée. Note suggérée : <strong>{$a->grade}/20</strong> (fournisseur : {$a->provider}).</p>';
$string['notification_ai_eval_small'] = 'Évaluation IA terminée pour {$a}';
$string['notification_ai_grade_subject'] = 'Note IA appliquée pour « {$a} »';
$string['notification_ai_grade_body'] = 'Une note générée par l\'IA a été automatiquement appliquée à votre rédaction dans « {$a->activity} » : {$a->grade}/20 dans le cours « {$a->course} ».';
$string['notification_ai_grade_body_html'] = '<p>Une note générée par l\'IA a été automatiquement appliquée à votre rédaction dans « <em>{$a->activity}</em> » : <strong>{$a->grade}/20</strong> dans le cours « <em>{$a->course}</em> ».</p>';
$string['notification_ai_grade_small'] = '{$a->activity} : note IA {$a->grade}/20';
$string['view_evaluation'] = 'Voir l\'évaluation';
$string['messageprovider:submission_received'] = 'Notification quand un étudiant soumet sa rédaction';
$string['messageprovider:grade_released'] = 'Notification quand une note est publiée';
$string['messageprovider:ai_evaluation_complete'] = 'Notification quand une évaluation IA est terminée';

// Opérations en masse.
$string['bulk_evaluate'] = 'Évaluer tout avec l\'IA';
$string['bulk_apply_grade'] = 'Appliquer toutes les notes IA';
$string['js:bulk_evaluating'] = 'Évaluation de toutes les soumissions...';
$string['js:bulk_evaluate_success'] = '{$a->queued} évaluation(s) en file d\'attente, {$a->skipped} ignorée(s).';
$string['js:bulk_applying'] = 'Application des notes...';
$string['js:bulk_apply_success'] = '{$a->applied} note(s) appliquée(s), {$a->skipped} ignorée(s).';
$string['js:bulk_apply_confirm'] = 'Appliquer toutes les notes IA terminées ? Cette action mettra à jour le carnet de notes.';
$string['js:no_evaluations'] = 'Aucune évaluation terminée à appliquer.';

// Détection de similarité.
$string['settings_plagiarism_heading'] = 'Détection de similarité';
$string['settings_plagiarism_heading_desc'] = 'Configurez le seuil de détection de similarité entre les soumissions des élèves. Utilise le coefficient de similarité de Jaccard.';
$string['settings_plagiarism_threshold'] = 'Seuil d\'alerte (%)';
$string['settings_plagiarism_threshold_desc'] = 'Pourcentage de similarité au-dessus duquel une alerte est affichée. Par défaut : 70%.';
$string['check_similarity'] = 'Vérifier la similarité';
$string['similarity_results'] = 'Résultats de similarité';
$string['similarity_alert'] = 'Forte similarité détectée';
$string['no_similar_submissions'] = 'Aucune soumission similaire trouvée.';

// Support mobile.
$string['mobile_view_title'] = 'Activité Rédaction';
$string['mobile_consignes_title'] = 'Consignes';
$string['mobile_submission_title'] = 'Ma soumission';
$string['mobile_evaluation_title'] = 'Évaluation';

// Training mode.
$string['training_settings'] = 'Mode entraînement';
$string['training_enabled'] = 'Activer le mode entraînement';
$string['training_enabled_help'] = 'Permet aux élèves de soumettre plusieurs fois leur travail pour obtenir un retour IA immédiat avant la soumission finale. Nécessite que l\'évaluation IA soit activée. Attention : la consommation de tokens IA sera multipliée par le nombre de tentatives.';
$string['training_cooldown'] = 'Délai entre les soumissions';
$string['training_cooldown_help'] = 'Temps minimum à attendre entre deux soumissions d\'entraînement.';
$string['training_min_change'] = 'Modification minimum requise';
$string['training_min_change_help'] = 'Pourcentage minimum de modification du contenu requis entre deux soumissions d\'entraînement pour éviter les soumissions identiques.';
$string['training_max_attempts'] = 'Nombre maximum de tentatives';
$string['training_max_attempts_help'] = 'Nombre maximum de soumissions d\'entraînement autorisées. 0 = illimité.';
$string['training_requires_ai'] = 'Le mode entraînement nécessite que l\'évaluation IA soit activée.';
$string['training_submit'] = 'Soumettre pour feedback';
$string['training_submitted'] = 'Soumission d\'entraînement envoyée. Le feedback sera disponible dans quelques instants.';
$string['training_final_submit'] = 'Soumission finale';
$string['training_final_confirm'] = 'Êtes-vous sûr de vouloir effectuer votre soumission finale ? Vous ne pourrez plus la modifier. C\'est cette version qui sera notée.';
$string['training_history'] = 'Historique des feedbacks';
$string['training_attempt'] = 'Tentative {$a}';
$string['training_remaining'] = 'Tentatives restantes : {$a}';
$string['training_status'] = 'Mode entraînement actif';
$string['training_error_training_not_enabled'] = 'Le mode entraînement n\'est pas activé pour cette activité.';
$string['training_error_already_submitted'] = 'La rédaction a déjà été soumise définitivement.';
$string['training_error_deadline_passed'] = 'La date limite est dépassée.';
$string['training_error_max_attempts_reached'] = 'Nombre maximum de tentatives atteint.';
$string['training_error_cooldown_active'] = 'Veuillez patienter avant la prochaine soumission.';
$string['training_error_cooldown_remaining'] = 'Veuillez patienter encore {$a} minute(s) avant la prochaine soumission.';
$string['training_error_evaluation_pending'] = 'Une évaluation est en cours. Attendez le résultat avant de soumettre à nouveau.';
$string['training_error_min_change'] = 'Le contenu n\'a pas assez changé depuis la dernière soumission. Modifiez davantage votre texte avant de resoumettre.';
$string['training_evaluating'] = 'Évaluation en cours...';
$string['training_feedback_title'] = 'Feedback d\'entraînement';
$string['training_no_feedback'] = 'Aucun feedback d\'entraînement disponible.';
$string['unlimited'] = 'Illimité';

// Visual criteria editor.
$string['grille_criteres_visual_help'] = 'Définissez vos critères d\'évaluation. La somme des poids devrait idéalement être égale à 20.';
$string['add_criterion'] = 'Ajouter un critère';
$string['remove_criterion'] = 'Supprimer';
$string['criterion_name'] = 'Nom du critère';
$string['criterion_name_placeholder'] = 'Ex: Pertinence, Structure, Expression...';
$string['criterion_description'] = 'Description';
$string['criterion_description_placeholder'] = 'Décrivez ce que ce critère évalue...';
$string['criterion_weight'] = 'Poids';
$string['total_weight'] = 'Total des poids';
$string['show_json'] = 'Afficher le JSON brut (avancé)';
$string['weight_warning_under'] = 'La somme des poids ({$a}) est inférieure à 20.';
$string['weight_warning_over'] = 'La somme des poids ({$a}) dépasse 20.';
$string['weight_ok'] = 'Total : {$a}/20';

// Training mode - grading view.
$string['training_attempts_count'] = 'Tentatives d\'entraînement : {$a}';
$string['training_history_teacher'] = 'Historique d\'entraînement';
$string['training_no_attempts'] = 'Aucune tentative d\'entraînement';
$string['training_attempt_date'] = 'Tentative {$a->num} - {$a->date}';
$string['training_grade_label'] = 'Note entraînement';

// Training timeline.
$string['training_timeline_title'] = 'Chronologie d\'entraînement';
$string['training_timeline_progress'] = 'Progression';
$string['training_timeline_no_data'] = 'Aucune tentative d\'entraînement';
$string['training_timeline_final'] = 'Soumission finale';
$string['training_timeline_attempt'] = 'Tentative {$a}';

// Home page additional strings.
$string['submissions_count'] = '{$a} soumission(s)';
$string['no_group_error'] = 'Vous n\'êtes dans aucun groupe. Contactez votre enseignant.';
$string['group_working'] = 'Vous travaillez en groupe :';
$string['view_consignes'] = 'Voir les consignes';
$string['consultation'] = 'Consultation';
$string['to_complete'] = 'À compléter';
$string['view_my_redaction'] = 'Voir ma rédaction';
$string['work_on_it'] = 'Travailler';
$string['grade_label'] = 'Note :';

// Redaction page additional strings.
$string['group_required'] = 'Vous devez appartenir à un groupe pour accéder à cette activité.';
$string['group_label'] = 'Groupe :';
$string['submitted_graded'] = '{$a} - Noté';

// Consignes page additional strings.
$string['criteres_placeholder'] = '- Critère 1\n- Critère 2\n- Critère 3';

// AI prompt builder strings.
$string['ai_default_criterion_relevance'] = 'Pertinence';
$string['ai_default_criterion_relevance_desc'] = 'La réponse est pertinente par rapport au sujet';
$string['ai_default_criterion_structure'] = 'Structure';
$string['ai_default_criterion_structure_desc'] = 'Organisation logique et claire du texte';
$string['ai_default_criterion_expression'] = 'Expression';
$string['ai_default_criterion_expression_desc'] = 'Qualité de l\'expression écrite (orthographe, grammaire, vocabulaire)';
$string['ai_default_criterion_argumentation'] = 'Argumentation';
$string['ai_default_criterion_argumentation_desc'] = 'Qualité et pertinence des arguments présentés';
$string['ai_criterion_default'] = 'Critère';

// AI prompt system strings.
$string['ai_prompt_system_intro'] = 'Tu es un assistant pédagogique expert en évaluation de rédactions d\'élèves. Tu dois évaluer le travail d\'un élève de manière juste, bienveillante et constructive.';
$string['ai_prompt_activity_context'] = 'Contexte de l\'activité';
$string['ai_prompt_title_label'] = 'Titre :';
$string['ai_prompt_criteria_section'] = 'Critères d\'évaluation';
$string['ai_prompt_specific_instructions'] = 'Instructions spécifiques';
$string['ai_prompt_response_format'] = 'Format de réponse';
$string['ai_prompt_response_format_intro'] = 'Tu DOIS répondre en JSON avec la structure suivante :';
$string['ai_prompt_grade_desc'] = 'note de 0 à 20';
$string['ai_prompt_feedback_desc'] = 'commentaire détaillé et constructif adressé directement à l\'élève';
$string['ai_prompt_criterion_name_desc'] = 'nom du critère';
$string['ai_prompt_criterion_comment_desc'] = 'commentaire détaillé sur ce critère';
$string['ai_prompt_strengths_desc'] = 'point fort';
$string['ai_prompt_weaknesses_desc'] = 'axe d\'amélioration';
$string['ai_prompt_keywords_found_desc'] = 'mots-clés trouvés';
$string['ai_prompt_keywords_missing_desc'] = 'mots-clés attendus mais absents';
$string['ai_prompt_suggestions_desc'] = 'conseil concret et actionnable d\'amélioration';
$string['ai_prompt_appreciation_desc'] = 'appréciation globale courte, 1-2 phrases, encourageante';
$string['ai_prompt_confidence_desc'] = '0.0 à 1.0';
$string['ai_prompt_training_context'] = 'CONTEXTE : MODE ENTRAÎNEMENT';
$string['ai_prompt_training_intro'] = 'Cette évaluation est un feedback formatif pour aider l\'élève à s\'améliorer AVANT sa soumission finale.';
$string['ai_prompt_training_detailed'] = 'Sois particulièrement détaillé dans tes suggestions d\'amélioration.';
$string['ai_prompt_training_identify'] = 'Identifie clairement ce qui doit être retravaillé.';
$string['ai_prompt_training_examples'] = 'Donne des exemples concrets de reformulations ou ajouts possibles.';
$string['ai_prompt_training_indicative'] = 'La note n\'est qu\'indicative, insiste sur les pistes d\'amélioration.';
$string['ai_prompt_important_instructions'] = 'Instructions importantes';
$string['ai_prompt_address_student'] = 'Adresse-toi directement à l\'élève avec bienveillance et encouragement (tutoiement).';
$string['ai_prompt_start_positive'] = 'Commence TOUJOURS par souligner les points positifs avant les axes d\'amélioration.';
$string['ai_prompt_level_criteria'] = 'Pour chaque critère, attribue un niveau : "excellent" (>=80%), "good" (>=60%), "medium" (>=40%), "low" (<40%).';
$string['ai_prompt_list_strengths'] = 'Liste 2 à 4 points forts et 2 à 4 axes d\'amélioration.';
$string['ai_prompt_give_suggestions'] = 'Donne 2 à 4 suggestions d\'amélioration concrètes, actionnables et réalisables.';
$string['ai_prompt_appreciation_instructions'] = 'L\'appréciation globale doit être encourageante et résumer l\'essentiel en 1-2 phrases.';
$string['ai_prompt_grade_coherence'] = 'La note doit être cohérente avec les scores des critères.';
$string['ai_prompt_feedback_structured'] = 'Le feedback doit être structuré et lisible.';
$string['ai_prompt_student_instructions'] = 'Consignes données aux élèves';
$string['ai_prompt_criteria_communicated'] = 'Critères communiqués aux élèves :';
$string['ai_prompt_model_answer'] = 'Modèle de réponse attendue';
$string['ai_prompt_student_work'] = 'Travail de l\'élève';
$string['ai_prompt_content_label'] = 'Contenu :';
$string['ai_prompt_evaluate_instruction'] = 'Évalue ce travail selon les critères définis et fournis ta réponse en JSON.';

// AI response parser display strings.
$string['ai_display_grade'] = 'Note :';
$string['ai_display_strengths'] = 'Points forts :';
$string['ai_display_weaknesses'] = 'Axes d\'amélioration :';
$string['ai_display_comments'] = 'Commentaires :';
$string['ai_display_criteria'] = 'Critères :';
$string['ai_display_suggestions'] = 'Suggestions :';

// AI criteria generation prompts.
$string['ai_generate_criteria_system_prompt'] = 'Tu es un assistant pédagogique expert. Ta tâche est de générer des critères d\'évaluation pour une activité de rédaction d\'élèves. Réponds UNIQUEMENT en format JSON avec la structure suivante : {"grille_criteres": [{"name": "Nom du critère", "weight": 5, "description": "Ce que ce critère évalue"}], "ai_instructions": "Instructions spécifiques pour l\'évaluateur IA"}. Le poids total doit être égal à 20. Génère 3 à 5 critères pertinents.';
$string['ai_generate_criteria_user_prompt'] = 'Génère des critères d\'évaluation pour l\'activité de rédaction suivante :\n\nTitre : {$a->titre}\n\nConsignes : {$a->consignes}\n\nCritères existants : {$a->criteres}\n\nGénère une grille de critères et des instructions d\'évaluation IA adaptées à cette activité.';
