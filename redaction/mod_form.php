<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Module instance settings form.
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Module instance settings form.
 */
class mod_redaction_mod_form extends moodleform_mod {

    /**
     * Defines forms elements
     */
    public function definition() {
        global $CFG, $PAGE;

        $mform = $this->_form;

        // Load test API JavaScript module.
        $cmid = $this->_cm ? $this->_cm->id : 0;
        $PAGE->requires->js_call_amd('mod_redaction/test_api', 'init', [$cmid]);

        // Adding the "general" fieldset.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Adding the standard "name" field.
        $mform->addElement('text', 'name', get_string('modulename', 'redaction'), ['size' => '64']);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Adding the standard "intro" and "introformat" fields.
        $this->standard_intro_elements();

        // Autosave interval setting.
        $mform->addElement('header', 'autosavesettings', get_string('autosave_settings', 'redaction'));

        $options = [
            10 => '10 ' . get_string('seconds'),
            30 => '30 ' . get_string('seconds'),
            60 => '60 ' . get_string('seconds'),
            120 => '120 ' . get_string('seconds'),
        ];
        $mform->addElement(
            'select',
            'autosave_interval',
            get_string('autosave_interval', 'redaction'),
            $options
        );
        $mform->setDefault('autosave_interval', 30);
        $mform->addHelpButton('autosave_interval', 'autosave_interval', 'redaction');

        // Submission settings.
        $mform->addElement('header', 'submissionsettings', get_string('submissionsettings', 'redaction'));

        $mform->addElement('selectyesno', 'group_submission', get_string('groupsubmission', 'redaction'));
        $mform->setDefault('group_submission', 1);
        $mform->addHelpButton('group_submission', 'groupsubmission', 'redaction');

        // Training mode settings.
        $mform->addElement('header', 'training_settings', get_string('training_settings', 'redaction'));
        $mform->setExpanded('training_settings', false);

        $mform->addElement('selectyesno', 'training_enabled', get_string('training_enabled', 'redaction'));
        $mform->setDefault('training_enabled', 0);
        $mform->addHelpButton('training_enabled', 'training_enabled', 'redaction');

        // Cooldown between training submissions.
        $cooldownoptions = [
            300 => '5 ' . get_string('minutes'),
            600 => '10 ' . get_string('minutes'),
            900 => '15 ' . get_string('minutes'),
            1800 => '30 ' . get_string('minutes'),
            3600 => '60 ' . get_string('minutes'),
        ];
        $mform->addElement('select', 'training_cooldown', get_string('training_cooldown', 'redaction'), $cooldownoptions);
        $mform->setDefault('training_cooldown', 900);
        $mform->addHelpButton('training_cooldown', 'training_cooldown', 'redaction');
        $mform->hideIf('training_cooldown', 'training_enabled', 'eq', 0);

        // Minimum change percentage.
        $changeoptions = [];
        for ($i = 5; $i <= 50; $i += 5) {
            $changeoptions[$i] = $i . '%';
        }
        $mform->addElement('select', 'training_min_change', get_string('training_min_change', 'redaction'), $changeoptions);
        $mform->setDefault('training_min_change', 10);
        $mform->addHelpButton('training_min_change', 'training_min_change', 'redaction');
        $mform->hideIf('training_min_change', 'training_enabled', 'eq', 0);

        // Maximum training attempts.
        $attemptoptions = [0 => get_string('unlimited', 'redaction')];
        for ($i = 1; $i <= 20; $i++) {
            $attemptoptions[$i] = (string) $i;
        }
        $mform->addElement('select', 'training_max_attempts', get_string('training_max_attempts', 'redaction'), $attemptoptions);
        $mform->setDefault('training_max_attempts', 5);
        $mform->addHelpButton('training_max_attempts', 'training_max_attempts', 'redaction');
        $mform->hideIf('training_max_attempts', 'training_enabled', 'eq', 0);

        // AI Evaluation settings.
        $mform->addElement('header', 'ai_settings', get_string('ai_settings', 'redaction'));
        $mform->setExpanded('ai_settings', false);

        $mform->addElement('selectyesno', 'ai_enabled', get_string('ai_enabled', 'redaction'));
        $mform->setDefault('ai_enabled', 0);
        $mform->addHelpButton('ai_enabled', 'ai_enabled', 'redaction');

        $providers = [
            '' => get_string('ai_provider_select', 'redaction'),
            'albert' => 'Albert (Etalab) - ' . get_string('ai_provider_builtin', 'redaction'),
            'openai' => 'OpenAI (GPT-4)',
            'anthropic' => 'Anthropic (Claude)',
            'mistral' => 'Mistral AI',
        ];
        $mform->addElement('select', 'ai_provider', get_string('ai_provider', 'redaction'), $providers);
        $mform->setDefault('ai_provider', '');
        $mform->addHelpButton('ai_provider', 'ai_provider', 'redaction');
        $mform->hideIf('ai_provider', 'ai_enabled', 'eq', 0);

        // Notice for Albert (built-in key).
        $mform->addElement('static', 'ai_api_key_notice', '', get_string('ai_api_key_builtin_notice', 'redaction'));
        $mform->hideIf('ai_api_key_notice', 'ai_enabled', 'eq', 0);
        $mform->hideIf('ai_api_key_notice', 'ai_provider', 'neq', 'albert');

        // API key field (hidden for providers with built-in keys).
        $mform->addElement('passwordunmask', 'ai_api_key', get_string('ai_api_key', 'redaction'));
        $mform->setType('ai_api_key', PARAM_RAW);
        $mform->addHelpButton('ai_api_key', 'ai_api_key', 'redaction');
        $mform->hideIf('ai_api_key', 'ai_enabled', 'eq', 0);
        $mform->hideIf('ai_api_key', 'ai_provider', 'eq', 'albert');

        // Test API button.
        $mform->addElement(
            'button',
            'test_api_btn',
            get_string('ai_test_connection', 'redaction'),
            ['id' => 'id_test_api_btn']
        );
        $mform->hideIf('test_api_btn', 'ai_enabled', 'eq', 0);

        // Auto-apply AI grades option.
        $mform->addElement('selectyesno', 'ai_auto_apply', get_string('ai_auto_apply', 'redaction'));
        $mform->setDefault('ai_auto_apply', 0);
        $mform->addHelpButton('ai_auto_apply', 'ai_auto_apply', 'redaction');
        $mform->hideIf('ai_auto_apply', 'ai_enabled', 'eq', 0);

        // Add standard grading elements.
        $this->standard_grading_coursemodule_elements();

        // Add standard elements.
        $this->standard_coursemodule_elements();

        // Add standard buttons.
        $this->add_action_buttons();
    }

    /**
     * Enforce validation rules.
     *
     * @param array $data array of ("fieldname"=>value) of submitted data
     * @param array $files array of uploaded files "element_name"=>tmp_file_path
     * @return array of "element_name"=>"error_description" if there are errors
     */
    public function validation($data, $files) {
        global $CFG;

        $errors = parent::validation($data, $files);

        // Validate AI settings.
        if (!empty($data['ai_enabled'])) {
            if (empty($data['ai_provider'])) {
                $errors['ai_provider'] = get_string('ai_provider_required', 'redaction');
            }
            // API key is only required for providers without built-in keys.
            if (empty($data['ai_api_key']) && !empty($data['ai_provider'])) {
                require_once($CFG->dirroot . '/mod/redaction/classes/ai_config.php');
                if (\mod_redaction\ai_config::requires_user_api_key($data['ai_provider'])) {
                    $errors['ai_api_key'] = get_string('ai_api_key_required', 'redaction');
                }
            }
        }

        // Training mode requires AI to be enabled.
        if (!empty($data['training_enabled']) && empty($data['ai_enabled'])) {
            $errors['training_enabled'] = get_string('training_requires_ai', 'redaction');
        }

        return $errors;
    }
}
