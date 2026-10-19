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
 * Evaluate course notification conditions without changing completion.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification\local;

/**
 * Pure timing decisions combined with Moodle completion and availability APIs.
 */
final class condition_evaluator {
    /**
     * Evaluate a condition for the current cycle.
     * @param \stdClass $rule Rule.
     * @param \stdClass $state Stored cycle.
     * @param \stdClass $course Course.
     * @param int $now Evaluation time.
     * @return \stdClass Result with allowed, reason, temporary, baseat and lastaccess.
     */
    public static function evaluate(\stdClass $rule, \stdClass $state, \stdClass $course, int $now): \stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $result = (object)['allowed' => true, 'reason' => '', 'temporary' => false,
            'baseat' => $state->baseat, 'lastaccess' => $state->lastaccess];
        if (!$course->visible || $course->startdate > $now || ($course->enddate && $course->enddate <= $now)) {
            $result->allowed = false;
            $result->reason = 'course_unavailable';
            $result->temporary = !$course->enddate || $course->enddate > $now;
            return $result;
        }
        switch ($rule->conditiontype) {
            case 'inactivity':
                $access = (int)$DB->get_field(
                    'user_lastaccess',
                    'timeaccess',
                    ['userid' => $state->userid, 'courseid' => $course->id]
                );
                $result->lastaccess = $access;
                $result->baseat = max($state->enrolat, $rule->activatedat, $course->startdate, $access);
                break;
            case 'course_incomplete':
                self::evaluate_course($state, $course, $result);
                break;
            case 'activity_incomplete':
                self::evaluate_activity($rule, $state, $course, $now, $result);
                break;
            default:
                $result->allowed = false;
                $result->reason = 'invalid_condition';
        }
        return $result;
    }

    /**
     * Evaluate course completion without running or modifying aggregation.
     * @param \stdClass $state Cycle.
     * @param \stdClass $course Course.
     * @param \stdClass $result Evaluation result.
     */
    private static function evaluate_course(\stdClass $state, \stdClass $course, \stdClass $result): void {
        global $DB;
        $completion = new \completion_info($course);
        if (!$completion->is_enabled() || !$completion->get_criteria()) {
            // Completion can be enabled again, so the cycle must not end.
            $result->allowed = false;
            $result->reason = 'completion_disabled';
            $result->temporary = true;
        } else if (
            $DB->get_field(
                'course_completions',
                'reaggregate',
                ['course' => $course->id, 'userid' => $state->userid]
            )
        ) {
            $result->allowed = false;
            $result->reason = 'completion_pending';
            $result->temporary = true;
        } else if ($completion->is_course_complete($state->userid)) {
            $result->allowed = false;
            $result->reason = 'completed';
        }
    }

    /**
     * Evaluate target visibility and completion for the recipient.
     * @param \stdClass $rule Rule.
     * @param \stdClass $state Cycle.
     * @param \stdClass $course Course.
     * @param int $now Timestamp.
     * @param \stdClass $result Evaluation result.
     */
    private static function evaluate_activity(
        \stdClass $rule,
        \stdClass $state,
        \stdClass $course,
        int $now,
        \stdClass $result
    ): void {
        $completion = new \completion_info($course);
        $modinfo = get_fast_modinfo($course, $state->userid);
        if (!isset($modinfo->cms[$rule->cmid])) {
            $result->allowed = false;
            $result->reason = 'activity_deleted';
            return;
        }
        $cm = $modinfo->get_cm($rule->cmid);
        if (!$completion->is_enabled($cm)) {
            $result->allowed = false;
            $result->reason = 'completion_disabled';
            $result->temporary = true;
        } else if (!$cm->uservisible) {
            $result->allowed = false;
            $result->reason = 'activity_unavailable';
            $result->temporary = true;
        } else {
            $data = $completion->get_data($cm, false, $state->userid);
            if ($data->completionstate != COMPLETION_INCOMPLETE) {
                $result->allowed = false;
                $result->reason = 'completed';
            }
            if (!$state->observedat) {
                $result->baseat = max($result->baseat, $now);
            }
        }
    }
}
