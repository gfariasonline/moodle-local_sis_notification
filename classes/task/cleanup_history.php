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
 * Remove expired notification history while preserving cycle counters.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification\task;

/**
 * Retention never reopens an accepted cycle.
 */
final class cleanup_history extends \core\task\scheduled_task {
    /**
     * Return the task name.
     * @return string Task name.
     */
    public function get_name(): string {
        return get_string('taskcleanup', 'local_sis_notification');
    }

    /**
     * Delete old terminal dispatches only.
     */
    public function execute(): void {
        global $DB;
        $days = max(1, (int)(get_config('local_sis_notification', 'retentiondays') ?: 180));
        $DB->delete_records_select('local_sis_notification_send', 'timemodified < :cutoff AND status = :status',
            ['cutoff' => time() - $days * DAYSECS, 'status' => 'accepted']);
    }
}
