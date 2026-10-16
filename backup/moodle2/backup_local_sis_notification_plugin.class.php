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
 * Back up course notification configuration only.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Student cycles and dispatches are deliberately excluded.
 */
class backup_local_sis_notification_plugin extends backup_local_plugin {
    /**
     * Attach configuration to the course backup connection point.
     */
    protected function define_course_plugin_structure(): void {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);
        $rules = new backup_nested_element('rules');
        $wrapper->add_child($rules);
        $rule = new backup_nested_element('rule', ['id'], [
            'name', 'conditiontype', 'days', 'cmid', 'subject', 'body', 'bodyformat', 'repeatdays', 'maxsends',
        ]);
        $rules->add_child($rule);
        $rule->set_source_table('local_sis_notification_rule', ['courseid' => backup::VAR_COURSEID]);
    }
}
