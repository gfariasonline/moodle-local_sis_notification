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
 * Discover due reminders with bounded work.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification\local;

/**
 * Global discovery budget and fair per-rule pagination.
 */
final class scanner {
    /**
     * Scan one bounded page per rule and reconcile pending outbox entries.
     * @param int|null $now Evaluation time.
     */
    public static function scan(int $now): void {
        global $DB;
                $limit = max(1, min(1000, (int)(get_config('local_sis_notification', 'batchsize') ?: 200)));
        $scanlock = \core\lock\lock_config::get_lock_factory('local_sis_notification')->get_lock('scan', 0);
        if (!$scanlock) {
            return;
        }
        try {
            $cursor = (int)get_config('local_sis_notification', 'scanruleid');
            $rules = $DB->get_records_select('local_sis_notification_rule', 'enabled = 1 AND id > :cursor',
                ['cursor' => $cursor], 'id', '*', 0, 20);
            if (!$rules) {
                $rules = $DB->get_records('local_sis_notification_rule', ['enabled' => 1], 'id', '*', 0, 20);
            }
            $budget = (object)['users' => $limit, 'tasks' => $limit, 'deadline' => microtime(true) + 20];
            foreach ($rules as $rule) {
                if ($budget->users <= 0 || $budget->tasks <= 0 || microtime(true) >= $budget->deadline) {
                    break;
                }
                set_config('scanruleid', $rule->id, 'local_sis_notification');
                $lock = dispatch_service::lock($rule->id);
                if (!$lock) {
                    continue;
                }
                try {
                    self::scan_rule($rule->id, $budget, $now);
                } finally {
                    $lock->release();
                }
            }
        } finally {
            $scanlock->release();
        }
    }

    /**
     * Observe and queue a page while the rule lock is held.
     * @param int $ruleid Rule ID.
     * @param \stdClass $budget Remaining global work.
     * @param int $now Evaluation time.
     */
    private static function scan_rule(int $ruleid, \stdClass $budget, int $now): void {
        global $DB;
        $rule = $DB->get_record('local_sis_notification_rule', ['id' => $ruleid, 'enabled' => 1]);
        if (!$rule) {
            return;
        }
        $course = $DB->get_record('course', ['id' => $rule->courseid]);
        if (!$course) {
            return;
        }
        $pagelimit = $budget->users;
        $users = recipient_selector::get_candidates($course->id, $rule->scanuserid, $pagelimit, $now);
        $processed = 0;
        foreach ($users as $user) {
            if (microtime(true) >= $budget->deadline) {
                break;
            }
            cycle_manager::observe($rule, $course, $user->id, $now);
            $rule->scanuserid = $user->id;
            $budget->users--;
            $processed++;
        }
        if ($processed === count($users) && count($users) < $pagelimit) {
            $rule->scanuserid = 0;
        }
        $DB->set_field('local_sis_notification_rule', 'scanuserid', $rule->scanuserid, ['id' => $rule->id]);
        $pending = $DB->get_records_select('local_sis_notification_send',
            'ruleid = :ruleid AND status = :status AND dueat <= :now',
            ['ruleid' => $rule->id, 'status' => 'pending', 'now' => $now], 'id', 'id', 0, $budget->tasks);
        foreach ($pending as $send) {
            $task = new \local_sis_notification\task\send_notifications();
            $task->set_custom_data(['sendid' => $send->id]);
            \core\task\manager::queue_adhoc_task($task, true);
            $budget->tasks--;
        }
        $DB->set_field_select('local_sis_notification_send', 'status', 'uncertain',
            'ruleid = :ruleid AND status = :status AND leaseuntil < :now',
            ['ruleid' => $rule->id, 'status' => 'processing', 'now' => $now]);
    }
}
