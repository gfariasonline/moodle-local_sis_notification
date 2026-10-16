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
 * Deliver a reserved notification.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification\task;

/**
 * One outbox ID, no personal data in the task payload.
 */
final class send_notifications extends \core\task\adhoc_task {
    /**
     * Revalidate and send the reserved notification.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (!empty($data->sendid)) {
            \local_sis_notification\local\dispatch_service::process((int)$data->sendid);
        }
    }
}
