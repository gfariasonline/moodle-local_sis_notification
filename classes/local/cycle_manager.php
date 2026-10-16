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
 * Track enrolment continuity and reminder episodes.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification\local;

/**
 * Persistent enrolment and condition episodes, independent of message delivery.
 */
final class cycle_manager {
    /**
     * Observe a recipient and reserve at most one current notification.
     * @param \stdClass $rule Rule.
     * @param \stdClass $course Course.
     * @param int $userid Recipient ID.
     * @param int $now Evaluation time.
     */
    public static function observe(\stdClass $rule, \stdClass $course, int $userid, int $now): void {
        global $DB;
        $eligibility = recipient_selector::get_eligibility($course->id, $userid, $now);
        $state = $DB->get_record('local_sis_notification_state', ['ruleid' => $rule->id, 'userid' => $userid]);
        if (!$eligibility->eligible) {
            if ($state && !$eligibility->temporary) {
                $DB->set_field('local_sis_notification_state', 'status', 'ended', ['id' => $state->id]);
            }
            return;
        }
        $gap = $state && recipient_selector::has_gap($state, $eligibility);
        $restart = $state && ($state->ruleversion != $rule->revision || $state->status === 'ended' || $gap);
        $state = self::prepare_state((object)['rule' => $rule, 'course' => $course, 'userid' => $userid,
            'eligibility' => $eligibility, 'state' => $state, 'restart' => $restart, 'now' => $now]);
        $condition = condition_evaluator::evaluate($rule, $state, $course, $now);
        if (!self::advance($rule, $state, $condition, $now)) {
            return;
        }
        self::reserve($rule, $state, $now);
    }
    /**
     * Initialise a cycle or extend the known continuity window.
     * @param \stdClass $input Evaluation context.
     * @return \stdClass
     */
    private static function prepare_state(\stdClass $input): \stdClass {
        global $DB;
        $rule = $input->rule;
        $course = $input->course;
        $userid = $input->userid;
        $eligibility = $input->eligibility;
        $state = $input->state;
        $restart = $input->restart;
        $now = $input->now;
        if (!$state || $restart) {
            $previous = $state;
            $state = (object)[
                'ruleid' => $rule->id, 'userid' => $userid, 'cycle' => $previous ? $previous->cycle + 1 : 1,
                'enroluntil' => $eligibility->until, 'enrolat' => $eligibility->baseline,
                'baseat' => max($eligibility->baseline, $rule->activatedat, $course->startdate),
                'observedat' => 0, 'lastaccess' => 0, 'sentcount' => 0, 'nextdue' => 0,
                'ruleversion' => $rule->revision, 'status' => 'active', 'timecreated' => $now, 'timemodified' => $now,
            ];
            if ($previous) {
                $state->id = $previous->id;
                $DB->update_record('local_sis_notification_state', $state);
            } else {
                $state->id = $DB->insert_record('local_sis_notification_state', $state);
            }
        }
        $state->enroluntil = $eligibility->until;
        return $state;
    }

    /**
     * Apply access/completion changes and decide whether the cycle is due.
     * @param \stdClass $rule Rule.
     * @param \stdClass $state Cycle, updated in place.
     * @param \stdClass $condition Evaluation.
     * @param int $now Timestamp.
     * @return bool
     */
    private static function advance(\stdClass $rule, \stdClass $state, \stdClass $condition, int $now): bool {
        global $DB;
        self::observe_access($rule, $state, $condition);
        $state->baseat = $condition->baseat;
        $state->lastaccess = $condition->lastaccess;
        $state->timemodified = $now;
        if (!$condition->allowed) {
            if (!$condition->temporary) {
                $state->status = 'complete';
            }
            $DB->update_record('local_sis_notification_state', $state);
            return false;
        }
        if (!$state->observedat) {
            $state->observedat = $now;
        }
        if (!$state->nextdue || !$state->sentcount) {
            $state->nextdue = $state->baseat + $rule->days * DAYSECS;
        }
        $DB->update_record('local_sis_notification_state', $state);
        if ($state->status !== 'active' || $state->nextdue > $now || $state->sentcount >= $rule->maxsends) {
            return false;
        }
        return true;
    }

    /**
     * Reserve one sequence or reopen a temporarily suppressed reservation.
     * @param \stdClass $rule Rule.
     * @param \stdClass $state Cycle.
     * @param int $now Timestamp.
     */
    private static function reserve(\stdClass $rule, \stdClass $state, int $now): void {
        global $DB;
        $key = ['ruleid' => $rule->id, 'userid' => $state->userid, 'cycle' => $state->cycle, 'seq' => $state->sentcount + 1];
        $existing = $DB->get_record('local_sis_notification_send', $key);
        if ($existing) {
            if ($existing->status === 'suppressed' && in_array($existing->reason,
                    ['rule_paused', 'user_inactive', 'enrolment_inactive', 'course_unavailable', 'activity_unavailable',
                     'no_receive_capability', 'completion_pending'], true)) {
                $DB->update_record('local_sis_notification_send', (object)['id' => $existing->id, 'status' => 'pending',
                    'reason' => '', 'dueat' => $now, 'timemodified' => $now]);
            }
            return;
        }
        $DB->insert_record('local_sis_notification_send', (object)($key + [
            'ruleversion' => $rule->revision, 'dueat' => $now, 'status' => 'pending',
            'leaseuntil' => 0, 'attempts' => 0, 'reason' => '', 'messageid' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]));
    }

    /**
     * A recorded return starts a fresh absence episode.
     * @param \stdClass $rule Rule.
     * @param \stdClass $state Cycle.
     * @param \stdClass $condition Evaluation.
     */
    private static function observe_access(\stdClass $rule, \stdClass $state, \stdClass $condition): void {
        global $DB;
        if ($rule->conditiontype === 'inactivity' && $condition->lastaccess > $state->lastaccess) {
            if ($state->sentcount || $DB->record_exists('local_sis_notification_send',
                    ['ruleid' => $rule->id, 'userid' => $state->userid, 'cycle' => $state->cycle])) {
                $state->cycle++;
                $state->sentcount = 0;
                $state->nextdue = 0;
            }
            $state->status = 'active';
        }
    }
}
