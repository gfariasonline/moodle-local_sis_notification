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

namespace local_sis_notification\local;

use stdClass;

/**
 * What the current user can see in a course: activities that can drive a rule, group restrictions and the delivery history.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_access {
    /**
     * Activities that can be used by an activity rule.
     *
     * @param stdClass $course
     * @return string[] Activity names indexed by course module id.
     */
    public static function get_activity_options(stdClass $course): array {
        $options = [];
        foreach (self::get_usable_activities($course) as $cm) {
            $options[$cm->id] = format_string($cm->name, true, ['context' => $cm->context]);
        }
        return $options;
    }

    /**
     * Whether an activity of the course can drive an activity rule.
     *
     * @param stdClass $course
     * @param int $cmid
     * @return bool
     */
    public static function is_usable_activity(stdClass $course, int $cmid): bool {
        return isset(self::get_usable_activities($course)[$cmid]);
    }

    /**
     * User ids the current user may see in this course, or null when there is no restriction.
     *
     * Separate groups apply unless the user can access all groups.
     *
     * @param stdClass $course
     * @param \context $context
     * @return array|null User ids as keys.
     */
    public static function get_visible_userids(stdClass $course, \context $context): ?array {
        global $DB;
        $groupids = self::get_restricted_groupids($course, $context);
        if ($groupids === null) {
            return null;
        }
        if (!$groupids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED);
        $userids = $DB->get_fieldset_select('groups_members', 'DISTINCT userid', "groupid $insql", $params);
        return array_flip($userids);
    }

    /**
     * Number of history rows visible to the current user.
     *
     * @param stdClass $course
     * @param \context $context Course context.
     * @param int $ruleid Only this rule when greater than zero.
     * @param string $status Only this status when it is a known one.
     * @return int
     */
    public static function count_history(stdClass $course, \context $context, int $ruleid, string $status): int {
        global $DB;
        $query = self::history_query($course, $context, $ruleid, $status);
        if ($query === null) {
            return 0;
        }
        [$from, $params] = $query;
        return $DB->count_records_sql("SELECT COUNT(s.id) $from", $params);
    }

    /**
     * One page of the delivery history visible to the current user. The message content is never included.
     *
     * @param stdClass $course
     * @param \context $context Course context.
     * @param int $ruleid Only this rule when greater than zero.
     * @param string $status Only this status when it is a known one.
     * @param int $page First page is zero.
     * @param int $perpage
     * @return stdClass[] Rows indexed by delivery id.
     */
    public static function get_history(
        stdClass $course,
        \context $context,
        int $ruleid,
        string $status,
        int $page,
        int $perpage
    ): array {
        global $DB;
        $query = self::history_query($course, $context, $ruleid, $status);
        if ($query === null) {
            return [];
        }
        [$from, $params] = $query;
        $namefields = \core_user\fields::for_name()->get_sql('u')->selects;
        $sql = "SELECT s.id, s.ruleid, s.userid, s.cycle, s.seq, s.dueat, s.status, s.reason, s.attempts,
                       s.timemodified, r.name AS rulename $namefields
                  $from
              ORDER BY s.timemodified DESC, s.id DESC";
        return $DB->get_records_sql($sql, $params, max(0, $page) * $perpage, $perpage);
    }

    /**
     * Activities of the course with activity completion that are not being deleted.
     *
     * @param stdClass $course
     * @return \cm_info[] Indexed by course module id.
     */
    private static function get_usable_activities(stdClass $course): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $completion = new \completion_info($course);
        return array_filter(
            get_fast_modinfo($course)->get_cms(),
            static fn(\cm_info $cm): bool => !$cm->deletioninprogress && $completion->is_enabled($cm)
        );
    }

    /**
     * Group ids that restrict what the current user can see.
     *
     * @param stdClass $course
     * @param \context $context
     * @return int[]|null Null when there is no restriction; an empty array when the user is in no group.
     */
    private static function get_restricted_groupids(stdClass $course, \context $context): ?array {
        global $USER;
        if (groups_get_course_groupmode($course) != SEPARATEGROUPS || has_capability('moodle/site:accessallgroups', $context)) {
            return null;
        }
        $groups = groups_get_all_groups($course->id, $USER->id, (int) ($course->defaultgroupingid ?? 0), 'g.id');
        return array_map('intval', array_keys($groups));
    }

    /**
     * Shared FROM and WHERE of the history queries.
     *
     * @param stdClass $course
     * @param \context $context
     * @param int $ruleid
     * @param string $status
     * @return array|null The SQL and its parameters, or null when nothing can be visible.
     */
    private static function history_query(stdClass $course, \context $context, int $ruleid, string $status): ?array {
        $groupids = self::get_restricted_groupids($course, $context);
        if ($groupids === []) {
            return null;
        }

        $params = ['courseid' => $course->id];
        $where = ['r.courseid = :courseid'];
        if ($ruleid > 0) {
            $where[] = 's.ruleid = :ruleid';
            $params['ruleid'] = $ruleid;
        }
        if (in_array($status, rule_service::get_statuses(), true)) {
            $where[] = 's.status = :status';
            $params['status'] = $status;
        }
        if ($groupids !== null) {
            [$groupsql, $groupparams] = self::group_condition($groupids);
            $where[] = $groupsql;
            $params += $groupparams;
        }

        $from = "FROM {local_sis_notification_send} s
                 JOIN {local_sis_notification_rule} r ON r.id = s.ruleid
                 JOIN {user} u ON u.id = s.userid
                WHERE " . implode(' AND ', $where);
        return [$from, $params];
    }

    /**
     * Condition that keeps the deliveries of users that belong to any of the groups.
     *
     * @param int[] $groupids
     * @return array The SQL and its parameters.
     */
    private static function group_condition(array $groupids): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'grp');
        return ["EXISTS (SELECT 1 FROM {groups_members} gm WHERE gm.userid = s.userid AND gm.groupid $insql)", $params];
    }
}
