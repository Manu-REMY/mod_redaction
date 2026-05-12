<?php
// This file is part of Moodle - http://moodle.org/

namespace mod_redaction\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Form to create/edit a redaction date override.
 *
 * Custom data keys:
 *   - mode: 'user' or 'group'
 *   - cmid: course module id
 *   - redactionid: instance id
 *   - context: \context_module
 *   - existing: stdClass|null (the existing override when editing)
 *   - userlist: array<int,string> userid => fullname (mode=user)
 *   - grouplist: array<int,string> groupid => name (mode=group)
 *   - groupmodewarning: bool (mode=user and group_submission=1)
 *
 * @package    mod_redaction
 * @copyright  2026 Emmanuel REMY
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class override_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $custom = $this->_customdata;
        $mode = $custom['mode'];

        $mform->addElement('hidden', 'cmid', $custom['cmid']);
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'mode', $mode);
        $mform->setType('mode', PARAM_ALPHA);
        $mform->addElement('hidden', 'overrideid', !empty($custom['existing']->id) ? $custom['existing']->id : 0);
        $mform->setType('overrideid', PARAM_INT);

        if ($mode === 'user') {
            if (!empty($custom['groupmodewarning'])) {
                $mform->addElement('static', 'groupmodewarn', '',
                    \html_writer::tag('div',
                        get_string('override_user_in_group_mode_warning', 'mod_redaction'),
                        ['class' => 'mod_redaction-overrides-warning alert alert-warning']
                    )
                );
            }
            $mform->addElement('select', 'userid', get_string('overrideuser', 'mod_redaction'), $custom['userlist']);
            $mform->setType('userid', PARAM_INT);
            $mform->addRule('userid', null, 'required', null, 'client');
        } else {
            $mform->addElement('select', 'groupid', get_string('overridegroup', 'mod_redaction'), $custom['grouplist']);
            $mform->setType('groupid', PARAM_INT);
            $mform->addRule('groupid', null, 'required', null, 'client');

            $mform->addElement('text', 'sortorder', get_string('overridesortorder', 'mod_redaction'), ['size' => 4]);
            $mform->setType('sortorder', PARAM_INT);
            $mform->setDefault('sortorder', 0);
            $mform->addHelpButton('sortorder', 'overridesortorder', 'mod_redaction');
        }

        $mform->addElement('date_time_selector', 'deadline_date',
            get_string('overridedeadline', 'mod_redaction'), ['optional' => true]);
        $mform->addHelpButton('deadline_date', 'overridedeadline', 'mod_redaction');

        $this->add_action_buttons(true,
            !empty($custom['existing']->id)
                ? get_string('savechanges')
                : get_string('addoverride', 'mod_redaction'));

        if (!empty($custom['existing'])) {
            $this->set_data((array) $custom['existing']);
        }
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        if (empty($data['deadline_date'])) {
            $errors['deadline_date'] = get_string('override_no_deadline', 'mod_redaction');
        }

        if ($data['mode'] === 'user' && empty($data['overrideid'])) {
            $exists = $DB->record_exists('redaction_overrides', [
                'redactionid' => (int) $this->_customdata['redactionid'],
                'userid' => (int) $data['userid'],
            ]);
            if ($exists) {
                $errors['userid'] = get_string('override_duplicate_user', 'mod_redaction');
            }
        }

        return $errors;
    }
}
