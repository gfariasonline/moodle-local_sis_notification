<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Form to create and edit a notification rule.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification\form;

use local_sis_notification\local\course_access;
use local_sis_notification\local\rule_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Rule form. The custom data has the course and, when editing, the stored rule.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $course = $this->_customdata['course'];
        $rule = $this->_customdata['rule'] ?? null;
        $context = \context_course::instance($course->id);
        $locked = $rule && $rule->enabled;

        $mform->addElement('hidden', 'courseid', $course->id);
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('hidden', 'id', $rule->id ?? 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('header', 'generalhdr', get_string('general', 'local_sis_notification'));
        $mform->addElement('text', 'name', get_string('name', 'local_sis_notification'), ['size' => 50, 'maxlength' => 255]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('header', 'conditionhdr', get_string('condition', 'local_sis_notification'));
        if ($locked) {
            $mform->addElement('static', 'lockednote', '', get_string('cyclelockednote', 'local_sis_notification'));
        } else if ($rule) {
            $mform->addElement('static', 'changenote', '', get_string('cyclechangenote', 'local_sis_notification'));
        }

        $types = [];
        foreach (rule_service::get_condition_types() as $type) {
            $types[$type] = get_string('conditiontype_' . $type, 'local_sis_notification');
        }
        $mform->addElement('select', 'conditiontype', get_string('conditiontype', 'local_sis_notification'), $types);

        $mform->addElement('text', 'days', get_string('days', 'local_sis_notification'), ['size' => 6, 'maxlength' => 4]);
        $mform->setType('days', PARAM_RAW_TRIMMED);
        $mform->setDefault('days', 7);
        $mform->addHelpButton('days', 'days', 'local_sis_notification');

        $activities = [0 => get_string('choosedots')] + course_access::get_activity_options($course);
        $mform->addElement('select', 'cmid', get_string('cmid', 'local_sis_notification'), $activities);
        $mform->setType('cmid', PARAM_INT);
        $mform->addHelpButton('cmid', 'cmid', 'local_sis_notification');
        $mform->hideIf('cmid', 'conditiontype', 'neq', rule_service::CONDITION_ACTIVITY_INCOMPLETE);

        if ($locked) {
            // Frozen elements export their defaults during POST validation.
            foreach (['conditiontype', 'days', 'cmid'] as $field) {
                $mform->setDefault($field, $rule->$field);
            }
            $mform->hardFreeze(['conditiontype', 'days', 'cmid']);
        }

        $this->add_message_fields($context, $rule === null);

        $mform->addElement('header', 'sendinghdr', get_string('sending', 'local_sis_notification'));
        $mform->addElement('text', 'repeatdays', get_string('repeatdays', 'local_sis_notification'), [
            'size' => 6, 'maxlength' => 4,
        ]);
        $mform->setType('repeatdays', PARAM_RAW_TRIMMED);
        $mform->setDefault('repeatdays', 0);
        $mform->addHelpButton('repeatdays', 'repeatdays', 'local_sis_notification');

        $maxsends = range(1, rule_service::MAXSENDS_MAX);
        $mform->addElement(
            'select',
            'maxsends',
            get_string('maxsends', 'local_sis_notification'),
            array_combine($maxsends, $maxsends)
        );
        $mform->setType('maxsends', PARAM_INT);
        $mform->setDefault('maxsends', 1);
        $mform->addHelpButton('maxsends', 'maxsends', 'local_sis_notification');
        $mform->hideIf('maxsends', 'repeatdays', 'eq', '0');

        if ($rule && !$locked) {
            $mform->addElement(
                'advcheckbox',
                'resetcycle',
                get_string('resetcycle', 'local_sis_notification'),
                get_string('resetcycle_check', 'local_sis_notification')
            );
            $mform->setType('resetcycle', PARAM_BOOL);
            $mform->addHelpButton('resetcycle', 'resetcycle', 'local_sis_notification');
        }

        if (!$rule) {
            $mform->addElement(
                'advcheckbox',
                'enableonsave',
                get_string('enableonsave', 'local_sis_notification'),
                get_string('enableonsave_check', 'local_sis_notification')
            );
            $mform->setType('enableonsave', PARAM_BOOL);
            $mform->setDefault('enableonsave', 1);
        }

        foreach (['generalhdr', 'conditionhdr', 'messagehdr', 'sendinghdr'] as $header) {
            $mform->setExpanded($header, true);
        }

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Validation, shared with the rule service.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $course = $this->_customdata['course'];
        $rule = $this->_customdata['rule'] ?? null;

        $record = (object) $data;
        $record->id = $rule->id ?? 0;
        foreach (rule_service::validate($record, $course->id) as $field => $message) {
            $errors[$field] = $message;
        }
        return $errors;
    }

    /**
     * Initial values for an existing rule.
     *
     * @param \stdClass $rule
     */
    public function set_rule(\stdClass $rule): void {
        $data = clone $rule;
        $data->body = ['text' => $rule->body, 'format' => FORMAT_HTML];
        $this->set_data($data);
    }

    /**
     * Add the message editor and supported template variable hints.
     * @param \context $context Course context.
     * @param bool $isnew Whether a new rule is being created, which pre-fills an example message.
     */
    private function add_message_fields(\context $context, bool $isnew): void {
        $mform = $this->_form;
        $mform->addElement('header', 'messagehdr', get_string('message', 'local_sis_notification'));
        $mform->addElement('text', 'subject', get_string('subject', 'local_sis_notification'), [
            'size' => 60, 'maxlength' => rule_service::TEXT_MAXLENGTH,
        ]);
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', null, 'required', null, 'client');

        $mform->addElement('editor', 'body', get_string('body', 'local_sis_notification'), null, [
            'maxfiles' => 0,
            'subdirs' => 0,
            'context' => $context,
            'trusttext' => false,
            'noclean' => false,
            'enable_filemanagement' => false,
        ]);
        $mform->setType('body', PARAM_RAW);
        $mform->addRule('body', null, 'required', null, 'client');
        if ($isnew) {
            $mform->setDefault('subject', get_string('defaultsubject', 'local_sis_notification'));
            $mform->setDefault('body', [
                'text' => get_string('defaultbody', 'local_sis_notification'),
                'format' => FORMAT_HTML,
            ]);
        }

        $common = rule_service::format_variables(rule_service::get_allowed_variables(rule_service::CONDITION_INACTIVITY));
        $activity = rule_service::format_variables(['activityname', 'activityurl']);
        $mform->addElement(
            'static',
            'variableshelp',
            get_string('variables', 'local_sis_notification'),
            \html_writer::tag('p', s(get_string('variables_common', 'local_sis_notification', $common)), ['class' => 'mb-1']) .
            \html_writer::tag('p', s(get_string('variables_activity', 'local_sis_notification', $activity)), ['class' => 'mb-0'])
        );
    }
}
