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
 * Privacy metadata, export and erasure for notification state.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Course-context privacy provider for recipients and rule authors.
 */
final class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {
    /**
     * Describe stored personal data and the messaging subsystem link.
     * @param collection $collection Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_sis_notification_rule', ['createdby' => 'privacy:metadata:createdby'],
            'privacy:metadata:rule');
        $fields = ['userid' => 'privacy:metadata:userid', 'timecreated' => 'privacy:metadata:time',
            'timemodified' => 'privacy:metadata:time', 'status' => 'privacy:metadata:status'];
        $collection->add_database_table('local_sis_notification_state', $fields + [
            'enroluntil' => 'privacy:metadata:time', 'baseat' => 'privacy:metadata:time',
            'enrolat' => 'privacy:metadata:time', 'observedat' => 'privacy:metadata:time',
            'lastaccess' => 'privacy:metadata:time', 'nextdue' => 'privacy:metadata:time', 'sentcount' => 'privacy:metadata:count',
            'cycle' => 'privacy:metadata:count'], 'privacy:metadata:state');
        $collection->add_database_table('local_sis_notification_send', $fields + ['dueat' => 'privacy:metadata:time',
            'leaseuntil' => 'privacy:metadata:time', 'messageid' => 'privacy:metadata:messageid',
            'reason' => 'privacy:metadata:status', 'attempts' => 'privacy:metadata:count', 'seq' => 'privacy:metadata:count',
            'cycle' => 'privacy:metadata:count'], 'privacy:metadata:send');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        return $collection;
    }

    /**
     * Find course contexts for authors and recipients, even after history retention.
     * @param int $userid User ID.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $list = new contextlist();
        $list->add_from_sql('SELECT ctx.id FROM {context} ctx JOIN {local_sis_notification_rule} r ON r.courseid = ctx.instanceid
            WHERE ctx.contextlevel = :level AND (r.createdby = :author OR EXISTS
            (SELECT s.id FROM {local_sis_notification_state} s WHERE s.ruleid = r.id AND s.userid = :student) OR EXISTS
            (SELECT d.id FROM {local_sis_notification_send} d WHERE d.ruleid = r.id AND d.userid = :recipient))',
            ['level' => CONTEXT_COURSE, 'author' => $userid, 'student' => $userid, 'recipient' => $userid]);
        return $list;
    }

    /**
     * List authors and recipients in a course.
     * @param userlist $userlist Requested context.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $userlist->add_from_sql('createdby', 'SELECT createdby FROM {local_sis_notification_rule}
            WHERE courseid = :courseid AND createdby > 0', ['courseid' => $context->instanceid]);
        foreach (['local_sis_notification_state', 'local_sis_notification_send'] as $table) {
            $userlist->add_from_sql('userid', "SELECT s.userid FROM {{$table}} s JOIN {local_sis_notification_rule} r
                ON r.id = s.ruleid WHERE r.courseid = :courseid", ['courseid' => $context->instanceid]);
        }
    }

    /**
     * Export data from approved course contexts.
     * @param approved_contextlist $contextlist Approved contexts and user.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            $data = (object)['authoredrules' => array_values($DB->get_records(
                'local_sis_notification_rule',
                ['courseid' => $context->instanceid, 'createdby' => $userid]))];
            $tables = ['local_sis_notification_state' => 'cycles', 'local_sis_notification_send' => 'dispatches'];
            foreach ($tables as $table => $key) {
                $data->$key = array_values($DB->get_records_sql("SELECT s.* FROM {{$table}} s
                    JOIN {local_sis_notification_rule} r ON r.id = s.ruleid WHERE r.courseid = :courseid AND s.userid = :userid",
                    ['courseid' => $context->instanceid, 'userid' => $userid]));
            }
            writer::with_context($context)->export_data([get_string('pluginname', 'local_sis_notification')], $data);
        }
    }

    /**
     * Erase student data and anonymise authors in one course; keep configuration.
     * @param \context $context Course context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context->contextlevel == CONTEXT_COURSE) {
            self::erase($context->instanceid, null);
        }
    }

    /**
     * Erase one user's records in approved contexts.
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_COURSE) {
                self::erase($context->instanceid, [$contextlist->get_user()->id]);
            }
        }
    }

    /**
     * Erase approved users in one course.
     * @param approved_userlist $userlist Approved users.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_COURSE && $userlist->get_userids()) {
            self::erase($context->instanceid, $userlist->get_userids());
        }
    }

    /**
     * Apply erasure under each rule lock; full erasure pauses rules.
     * @param int $courseid Course ID.
     * @param array|null $userids User IDs or all users.
     */
    private static function erase(int $courseid, ?array $userids): void {
        global $DB;
        foreach ($DB->get_records('local_sis_notification_rule', ['courseid' => $courseid]) as $rule) {
            $lock = \local_sis_notification\local\dispatch_service::lock($rule->id);
            if (!$lock) {
                throw new \coding_exception('Unable to lock notification rule for privacy erasure.');
            }
            try {
                $transaction = $DB->start_delegated_transaction();
                foreach (['local_sis_notification_state', 'local_sis_notification_send'] as $table) {
                    if ($userids === null) {
                        $DB->delete_records($table, ['ruleid' => $rule->id]);
                    } else {
                        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
                        $params['ruleid'] = $rule->id;
                        $DB->delete_records_select($table, 'ruleid = :ruleid AND userid ' . $insql, $params);
                    }
                }
                if ($userids === null || in_array($rule->createdby, $userids)) {
                    $DB->set_field('local_sis_notification_rule', 'createdby', 0, ['id' => $rule->id]);
                }
                // Do not recreate erased state on the next scan until a manager explicitly reactivates.
                $DB->set_field('local_sis_notification_rule', 'enabled', 0, ['id' => $rule->id]);
                $transaction->allow_commit();
            } finally {
                $lock->release();
            }
        }
    }
}
