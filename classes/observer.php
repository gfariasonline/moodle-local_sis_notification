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
    /** @var int Seconds to wait for a rule lock before the cleanup is left to an ad hoc task or fails. */
    public static int $locktimeout = 30;

    /**
     * Pause rules and erase student cycles after a course reset.
     * @param \core\event\course_reset_ended $event Reset event.
     */
    public static function course_reset(\core\event\course_reset_ended $event): void {
        global $DB;
        foreach ($DB->get_records('local_sis_notification_rule', ['courseid' => $event->courseid]) as $rule) {
            $lock = self::rule_lock($rule->id);
            try {
                $transaction = $DB->start_delegated_transaction();
                try {
                    $DB->delete_records('local_sis_notification_send', ['ruleid' => $rule->id]);
                    $DB->delete_records('local_sis_notification_state', ['ruleid' => $rule->id]);
                    $DB->update_record('local_sis_notification_rule', (object)['id' => $rule->id, 'enabled' => 0,
                        'activatedat' => 0, 'revision' => $rule->revision + 1, 'scanuserid' => 0]);
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    $transaction->rollback($e);
                }
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
        $lock = self::rule_lock($ruleid);
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $DB->delete_records('local_sis_notification_send', ['ruleid' => $ruleid]);
                $DB->delete_records('local_sis_notification_state', ['ruleid' => $ruleid]);
                $DB->delete_records('local_sis_notification_rule', ['id' => $ruleid]);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
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
            $lock = self::rule_lock($rule->id);
            try {
                $now = \core\di::get(\core\clock::class)->time();
                $transaction = $DB->start_delegated_transaction();
                try {
                    // The target is gone, so the cycle restarts like it does for any other change of the target.
                    $DB->execute(
                        'UPDATE {local_sis_notification_rule}
                            SET enabled = 0, activatedat = 0, scanuserid = 0, revision = revision + 1, timemodified = :now
                          WHERE id = :id',
                        ['now' => $now, 'id' => $rule->id]
                    );
                    $DB->execute(
                        'UPDATE {local_sis_notification_send}
                            SET status = :newstatus, reason = :reason, timemodified = :now
                          WHERE ruleid = :ruleid AND status = :pending',
                        [
                            'newstatus' => 'suppressed', 'reason' => 'activity_deleted', 'now' => $now,
                            'ruleid' => $rule->id, 'pending' => 'pending',
                        ]
                    );
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    $transaction->rollback($e);
                }
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * A complete unenrolment ends the cycle; overlapping enrolments preserve it.
     * @param \core\event\user_enrolment_deleted $event Enrolment event.
     */
    public static function enrolment_deleted(\core\event\user_enrolment_deleted $event): void {
        self::run_or_defer('enrolment', (int)$event->relateduserid, (int)$event->courseid);
    }

    /**
     * Remove recipient state and anonymise authorship for a deleted account.
     * @param \core\event\user_deleted $event User event.
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        self::run_or_defer('user', (int)$event->objectid, 0);
    }

    /**
     * End the cycles of a user that is no longer enrolled in a course.
     *
     * Safe to repeat. Each rule is changed under its own lock, the one the scanner holds while it observes the rule.
     *
     * @param int $userid User ID.
     * @param int $courseid Course ID.
     */
    public static function cleanup_enrolment(int $userid, int $courseid): void {
        global $DB;
        $now = \core\di::get(\core\clock::class)->time();
        if (
            $DB->record_exists_sql(
                'SELECT ue.id FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                WHERE ue.userid = :userid AND e.courseid = :courseid
                AND ue.timestart <= :nowstart AND (ue.timeend = 0 OR ue.timeend > :nowend)',
                ['userid' => $userid, 'courseid' => $courseid, 'nowstart' => $now, 'nowend' => $now]
            )
        ) {
            return;
        }
        foreach ($DB->get_records('local_sis_notification_rule', ['courseid' => $courseid]) as $rule) {
            $lock = self::rule_lock($rule->id);
            try {
                $DB->set_field('local_sis_notification_state', 'status', 'ended', ['ruleid' => $rule->id, 'userid' => $userid]);
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * Remove the recipient state of a deleted account and anonymise the rules it authored.
     *
     * Safe to repeat. Each rule is changed under its own lock, the one the scanner holds while it observes the rule.
     *
     * @param int $userid User ID.
     */
    public static function cleanup_user(int $userid): void {
        global $DB;
        $ruleids = array_unique(array_merge(
            $DB->get_fieldset_select('local_sis_notification_state', 'ruleid', 'userid = :userid', ['userid' => $userid]),
            $DB->get_fieldset_select('local_sis_notification_send', 'ruleid', 'userid = :userid', ['userid' => $userid]),
            $DB->get_fieldset_select('local_sis_notification_rule', 'id', 'createdby = :userid', ['userid' => $userid])
        ));
        foreach ($ruleids as $ruleid) {
            $lock = self::rule_lock((int)$ruleid);
            try {
                $transaction = $DB->start_delegated_transaction();
                try {
                    $DB->delete_records('local_sis_notification_send', ['ruleid' => $ruleid, 'userid' => $userid]);
                    $DB->delete_records('local_sis_notification_state', ['ruleid' => $ruleid, 'userid' => $userid]);
                    $DB->set_field('local_sis_notification_rule', 'createdby', 0, ['id' => $ruleid, 'createdby' => $userid]);
                    $transaction->allow_commit();
                } catch (\Throwable $e) {
                    $transaction->rollback($e);
                }
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * Run a cleanup now, or leave it to an ad hoc task when a rule stays locked.
     *
     * Throwing from these events would abort the unenrolment or the deletion of the account, so a busy rule only
     * postpones the cleanup. The ad hoc task is retried by the task manager until it succeeds.
     *
     * @param string $kind Either enrolment or user.
     * @param int $userid User ID.
     * @param int $courseid Course ID, for enrolments.
     */
    private static function run_or_defer(string $kind, int $userid, int $courseid): void {
        try {
            if ($kind === 'user') {
                self::cleanup_user($userid);
            } else {
                self::cleanup_enrolment($userid, $courseid);
            }
        } catch (\moodle_exception $e) {
            if ($e->errorcode !== 'rulelocked') {
                throw $e;
            }
            $task = new \local_sis_notification\task\lifecycle_cleanup();
            $task->set_custom_data(['kind' => $kind, 'userid' => $userid, 'courseid' => $courseid]);
            \core\task\manager::queue_adhoc_task($task, true);
        }
    }

    /**
     * Wait for any in-flight rule operation before lifecycle cleanup.
     * @param int $ruleid Rule ID.
     * @return \core\lock\lock
     */
    private static function rule_lock(int $ruleid): \core\lock\lock {
        $lock = \local_sis_notification\local\dispatch_service::lock($ruleid, self::$locktimeout);
        if (!$lock) {
            throw new \moodle_exception('rulelocked', 'local_sis_notification');
        }
        return $lock;
    }
}
