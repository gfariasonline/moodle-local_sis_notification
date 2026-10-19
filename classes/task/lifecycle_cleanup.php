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
 * Finish the cleanup of a user whose rules were locked when the event happened.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification\task;

use local_sis_notification\observer;

/**
 * Only the user id and the course id are kept in the task payload.
 */
final class lifecycle_cleanup extends \core\task\adhoc_task {
    /**
     * Return the task name.
     * @return string Task name.
     */
    public function get_name(): string {
        return get_string('tasklifecycle', 'local_sis_notification');
    }

    /**
     * Repeat the cleanup. A rule that is still locked makes the task fail, and the task manager tries again later.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $userid = (int)($data->userid ?? 0);
        if (!$userid) {
            return;
        }
        if (($data->kind ?? '') === 'user') {
            observer::cleanup_user($userid);
        } else if (($data->kind ?? '') === 'enrolment') {
            observer::cleanup_enrolment($userid, (int)($data->courseid ?? 0));
        }
    }
}
