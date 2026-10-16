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
 * Reconcile course, enrolment and user lifecycle changes.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification;

/**
 * Lifecycle callbacks never send notifications.
 */
final class observer {
    /**
     * Pause rules and erase student cycles after a course reset.
     * @param \core\event\course_reset_ended $event Reset event.
     */
    public static function course_reset(\core\event\course_reset_ended $event): void {
        global $DB;
        foreach ($DB->get_records('local_sis_notification_rule', ['courseid' => $event->courseid]) as $rule) {
            $lock = \local_sis_notification\local\dispatch_service::lock($rule->id);
            if (!$lock) {
                throw new \coding_exception('Unable to lock notification rule during course reset.');
            }
            try {
                $transaction = $DB->start_delegated_transaction();
                $DB->delete_records('local_sis_notification_send', ['ruleid' => $rule->id]);
                $DB->delete_records('local_sis_notification_state', ['ruleid' => $rule->id]);
                $DB->update_record('local_sis_notification_rule', (object)['id' => $rule->id, 'enabled' => 0,
                    'activatedat' => 0, 'revision' => $rule->revision + 1, 'scanuserid' => 0]);
                $transaction->allow_commit();
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * Remove plugin records when their course no longer exists.
     * @param \core\event\course_deleted $event Course event.
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        foreach ($DB->get_records('local_sis_notification_rule', ['courseid' => $event->objectid]) as $rule) {
            self::delete_rule($rule->id);
        }
    }

    /**
     * Delete a rule with its student data under the shared lock.
     * @param int $ruleid Rule ID.
     */
    public static function delete_rule(int $ruleid): void {
        global $DB;
        $lock = \local_sis_notification\local\dispatch_service::lock($ruleid);
        if (!$lock) {
            throw new \coding_exception('Unable to lock notification rule for deletion.');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            $DB->delete_records('local_sis_notification_send', ['ruleid' => $ruleid]);
            $DB->delete_records('local_sis_notification_state', ['ruleid' => $ruleid]);
            $DB->delete_records('local_sis_notification_rule', ['id' => $ruleid]);
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }

    /**
     * Disable rules whose target activity was deleted.
     * @param \core\event\course_module_deleted $event Activity event.
     */
    public static function activity_deleted(\core\event\course_module_deleted $event): void {
        global $DB;
        foreach ($DB->get_records('local_sis_notification_rule', ['cmid' => $event->objectid]) as $rule) {
            $DB->set_field('local_sis_notification_rule', 'enabled', 0, ['id' => $rule->id]);
            $DB->set_field('local_sis_notification_send', 'status', 'suppressed', ['ruleid' => $rule->id, 'status' => 'pending']);
        }
    }

    /**
     * A complete unenrolment ends the cycle; overlapping enrolments preserve it.
     * @param \core\event\user_enrolment_deleted $event Enrolment event.
     */
    public static function enrolment_deleted(\core\event\user_enrolment_deleted $event): void {
        global $DB;
        $scanlock = self::scan_lock();
        try {
            $userid = $event->relateduserid;
            if ($DB->record_exists_sql('SELECT ue.id FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                    WHERE ue.userid = :userid AND e.courseid = :courseid
                    AND ue.timestart <= :nowstart AND (ue.timeend = 0 OR ue.timeend > :nowend)',
                    ['userid' => $userid, 'courseid' => $event->courseid, 'nowstart' => time(), 'nowend' => time()])) {
                return;
            }
            foreach ($DB->get_records('local_sis_notification_rule', ['courseid' => $event->courseid]) as $rule) {
                $lock = self::rule_lock($rule->id);
                try {
                    $DB->set_field('local_sis_notification_state', 'status', 'ended', ['ruleid' => $rule->id, 'userid' => $userid]);
                } finally {
                    $lock->release();
                }
            }
        } finally {
            $scanlock->release();
        }
    }

    /**
     * Remove recipient state and anonymise authorship for a deleted account.
     * @param \core\event\user_deleted $event User event.
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;
        // Match the scanner's lock order. Wait until any pre-deletion recipient snapshot is persisted.
        $scanlock = self::scan_lock();
        try {
            $userid = $event->objectid;
            $ruleids = array_unique(array_merge(
                $DB->get_fieldset_select('local_sis_notification_state', 'ruleid', 'userid = :userid', ['userid' => $userid]),
                $DB->get_fieldset_select('local_sis_notification_send', 'ruleid', 'userid = :userid', ['userid' => $userid]),
                $DB->get_fieldset_select('local_sis_notification_rule', 'id', 'createdby = :userid', ['userid' => $userid])
            ));
            foreach ($ruleids as $ruleid) {
                $lock = self::rule_lock((int)$ruleid);
                try {
                    $DB->delete_records('local_sis_notification_send', ['ruleid' => $ruleid, 'userid' => $userid]);
                    $DB->delete_records('local_sis_notification_state', ['ruleid' => $ruleid, 'userid' => $userid]);
                    $DB->set_field('local_sis_notification_rule', 'createdby', 0, ['id' => $ruleid, 'createdby' => $userid]);
                } finally {
                    $lock->release();
                }
            }
        } finally {
            $scanlock->release();
        }
    }

    /**
     * Synchronise lifecycle events with bounded discovery.
     * @return \core\lock\lock
     */
    private static function scan_lock(): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('local_sis_notification')->get_lock('scan', 30);
        if (!$lock) {
            throw new \coding_exception('Unable to lock notification discovery for lifecycle cleanup.');
        }
        return $lock;
    }

    /**
     * Wait for any in-flight rule operation before lifecycle cleanup.
     * @param int $ruleid Rule ID.
     * @return \core\lock\lock
     */
    private static function rule_lock(int $ruleid): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('local_sis_notification')->get_lock('rule:' . $ruleid, 30);
        if (!$lock) {
            throw new \coding_exception('Unable to lock notification rule for lifecycle cleanup.');
        }
        return $lock;
    }
}
