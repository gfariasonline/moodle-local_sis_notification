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
 * Restore course rules inactive and remap activity targets.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restored rules cannot send until explicitly activated.
 */
class restore_local_sis_notification_plugin extends restore_local_plugin {
    /**
     * Define course configuration paths.
     * @return array
     */
    protected function define_course_plugin_structure(): array {
        return [new restore_path_element(
            'sis_notification_rule',
            $this->get_pathfor('/rules/rule')
        )];
    }

    /**
     * Import one configuration, never its user data or active state.
     * @param array $data Backed up fields.
     */
    public function process_sis_notification_rule(array $data): void {
        global $DB, $USER;
        $data = (object)$data;
        $oldid = $data->id;
        unset($data->id);
        $data->courseid = $this->task->get_courseid();
        $data->enabled = 0;
        $data->activatedat = 0;
        $data->revision = 1;
        $data->createdby = $USER->id;
        $data->timecreated = time();
        $data->timemodified = time();
        $data->scanuserid = 0;
        $id = $DB->insert_record('local_sis_notification_rule', $data);
        $this->set_mapping('local_sis_notification_rule', $oldid, $id);
    }

    /**
     * Activity mappings exist only after the complete course has been restored.
     */
    protected function after_restore_course(): void {
        global $DB;
        // Iterate imported mappings so existing rules in a destination course are untouched.
        $mappings = $DB->get_records('backup_ids_temp', ['backupid' => $this->get_restoreid(),
            'itemname' => 'local_sis_notification_rule']);
        foreach ($mappings as $mapping) {
            $rule = $DB->get_record('local_sis_notification_rule', ['id' => $mapping->newitemid]);
            if ($rule && $rule->cmid) {
                $DB->set_field(
                    'local_sis_notification_rule',
                    'cmid',
                    (int)$this->get_mappingid('course_module', $rule->cmid, 0),
                    ['id' => $rule->id]
                );
            }
        }
    }
}
