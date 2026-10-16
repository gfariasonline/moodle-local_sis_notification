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
 * Select actively enrolled notification recipients.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification\local;

/**
 * Eligibility shared by discovery, previews and the delivery worker.
 */
final class recipient_selector {
    /**
     * Return a bounded, deduplicated page of students, including temporarily suspended accounts.
     * @param int $courseid Course ID.
     * @param int $afteruserid Pagination cursor.
     * @param int $limit Page size.
     * @param int|null $now Evaluation time.
     * @return array
     */
    public static function get_candidates(int $courseid, int $afteruserid = 0, int $limit = 200, ?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        return $DB->get_records_sql('SELECT DISTINCT u.id FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid AND u.id > :cursor AND u.deleted = 0 AND ue.timestart <= :now
              ORDER BY u.id', ['courseid' => $courseid, 'cursor' => $afteruserid, 'now' => $now], 0, max(1, min(1000, $limit)));
    }

    /**
     * Verify both account and enrolment; a role alone never makes a recipient.
     * @param int $courseid Course ID.
     * @param int $userid User ID.
     * @param int|null $now Evaluation time.
     * @return \stdClass Result with eligible, baseline, reason and temporary properties.
     */
    public static function get_eligibility(int $courseid, int $userid, ?int $now = null): \stdClass {
        global $DB;
        $now = $now ?? time();
        $result = (object)['eligible' => false, 'baseline' => 0, 'reason' => 'unenrolled',
            'temporary' => false, 'until' => 0, 'periods' => []];
        if (!self::check_account($userid, $result)) {
            return $result;
        }
        $rows = $DB->get_records_sql(
            'SELECT ue.*, e.status AS instancestatus
            FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid WHERE e.courseid = :courseid AND ue.userid = :userid',
            ['courseid' => $courseid, 'userid' => $userid]
        );
        $merged = self::merge_periods($rows, $result);
        $result->periods = $merged;
        foreach ($merged as [$start, $end]) {
            if (self::covers_now($start, $end, $now)) {
                $result->eligible = true;
                $result->baseline = $start;
                $result->until = $end;
                break;
            }
        }
        if (!$result->eligible) {
            $result->reason = $result->temporary ? 'enrolment_inactive' : 'unenrolled';
            return $result;
        }
        $context = \context_course::instance($courseid);
        if (!has_capability('local/sis_notification:receive', $context, $userid, false)) {
            $result->eligible = false;
            $result->reason = 'no_receive_capability';
            $result->temporary = true;
            return $result;
        }
        $result->reason = '';
        return $result;
    }

    /**
     * Merge enrolment windows while preserving historical continuity.
     * @param array $rows Enrolment records.
     * @param \stdClass $result Mutable eligibility result.
     * @return array
     */
    private static function merge_periods(array $rows, \stdClass $result): array {
        $periods = [];
        foreach ($rows as $row) {
            if ($row->status != ENROL_USER_ACTIVE || $row->instancestatus != ENROL_INSTANCE_ENABLED) {
                $result->temporary = true;
                continue;
            }
            $periods[] = [(int)($row->timestart ?: $row->timecreated), (int)$row->timeend];
        }
        usort($periods, static fn($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($periods as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && (!$merged[$last][1] || $start <= $merged[$last][1])) {
                $merged[$last][1] = self::merge_end($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }
        return $merged;
    }

    /**
     * Detect a proven membership gap, including a newly imposed expiry date.
     * @param \stdClass $state Persisted cycle.
     * @param \stdClass $eligibility Current membership windows.
     * @return bool
     */
    public static function has_gap(\stdClass $state, \stdClass $eligibility): bool {
        if ($state->enroluntil && $eligibility->baseline > $state->enroluntil) {
            return true;
        }
        foreach ($eligibility->periods as [$start, $end]) {
            if (self::covers_now($start, $end, $state->enrolat)) {
                return $end && $eligibility->baseline > $end;
            }
        }
        return false;
    }

    /**
     * Check account status independently from enrolment status.
     * @param int $userid User ID.
     * @param \stdClass $result Eligibility result.
     * @return bool
     */
    private static function check_account(int $userid, \stdClass $result): bool {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user || $user->deleted || isguestuser($user)) {
            $result->reason = 'user_deleted';
            return false;
        }
        if ($user->suspended || !$user->confirmed) {
            $result->reason = 'user_inactive';
            $result->temporary = true;
            return false;
        }
        return true;
    }

    /**
     * An open end dominates finite window ends.
     * @param int $left Previous end.
     * @param int $right Next end.
     * @return int
     */
    private static function merge_end(int $left, int $right): int {
        return (!$left || !$right) ? 0 : max($left, $right);
    }

    /**
     * Test an effective enrolment period at a timestamp.
     * @param int $start Start timestamp.
     * @param int $end End timestamp or zero.
     * @param int $now Evaluation timestamp.
     * @return bool
     */
    private static function covers_now(int $start, int $end, int $now): bool {
        return $start <= $now && (!$end || $end > $now);
    }
}
