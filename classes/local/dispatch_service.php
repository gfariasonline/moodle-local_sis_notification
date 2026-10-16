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
 * Public entry points for notification processing.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification\local;

/**
 * Stable entry points for scheduled tasks and configuration locks.
 */
final class dispatch_service {
    /**
     * Discover due reminders within the global batch budget.
     * @param int|null $now Evaluation time.
     */
    public static function scan(?int $now = null): void {
        scanner::scan($now ?? time());
    }
    /**
     * Process one reserved notification.
     * @param int $sendid Dispatch ID.
     * @param int|null $now Evaluation time.
     */
    public static function process(int $sendid, ?int $now = null): void {
        delivery_worker::process($sendid, $now ?? time());
    }
    /**
     * Acquire the same rule lock used by configuration changes.
     * @param int $ruleid Rule ID.
     * @return \core\lock\lock|false
     */
    public static function lock(int $ruleid) {
        return \core\lock\lock_config::get_lock_factory('local_sis_notification')->get_lock('rule:' . $ruleid, 1);
    }

}
