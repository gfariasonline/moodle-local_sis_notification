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
 * Deliver reserved reminders through the Message API.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification\local;

/**
 * Revalidate an outbox reservation and record its delivery outcome.
 */
final class delivery_worker {
    /**
     * Deliver a reserved notification after rechecking all eligibility gates.
     * @param int $sendid Outbox ID.
     * @param int|null $now Evaluation time.
     */
    public static function process(int $sendid, ?int $now = null): void {
        global $DB;
        $now = $now ?? time();
        $send = $DB->get_record('local_sis_notification_send', ['id' => $sendid]);
        if (!$send) {
            return;
        }
        $lock = dispatch_service::lock($send->ruleid);
        if (!$lock) {
            throw new \moodle_exception('locktimeout', 'error');
        }
        try {
            self::process_locked($sendid, $now);
        } finally {
            $lock->release();
        }
    }

    /**
     * Process a dispatch while holding its rule lock.
     * @param int $sendid Dispatch ID.
     * @param int $now Evaluation time.
     */
    private static function process_locked(int $sendid, int $now): void {
        global $DB;
        $send = $DB->get_record('local_sis_notification_send', ['id' => $sendid]);
        if (!$send) {
            return;
        }
        if ($send->status === 'processing' && $send->leaseuntil < $now) {
            self::status($send, 'uncertain', 'lease_expired', $now);
            return;
        }
        if ($send->status !== 'pending' || $send->dueat > $now) {
            return;
        }
        $context = self::delivery_context($send, $now);
        if (!$context) {
            return;
        }
        $rule = $context->rule;
        $state = $context->state;
        $course = $context->course;
        $message = self::build_message($rule, $send->userid, $course);
        $send->attempts++;
        $send->leaseuntil = $now + 300;
        self::status($send, 'processing', '', $now);
        self::deliver($message, $send, $state, $rule, $now);
    }

    /**
     * Render a Moodle notification from current rule and recipient records.
     * @param \stdClass $rule Rule.
     * @param int $userid Recipient.
     * @param \stdClass $course Course.
     * @return \core\message\message
     */
    private static function build_message(\stdClass $rule, int $userid, \stdClass $course): \core\message\message {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $rendered = message_renderer::render($rule, $user, $course);
        $message = new \core\message\message();
        $message->component = 'local_sis_notification';
        $message->name = 'course_notification';
        $message->notification = 1;
        $message->courseid = $course->id;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $rendered->subject;
        $message->fullmessage = $rendered->plain;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $rendered->html;
        $message->smallmessage = $rendered->subject;
        $message->contexturl = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
        $message->contexturlname = format_string($course->fullname);
        return $message;
    }

    /**
     * Call the messaging boundary; only proven acceptance advances the cycle.
     * @param \core\message\message $message Message.
     * @param \stdClass $send Dispatch.
     * @param \stdClass $state Cycle.
     * @param \stdClass $rule Rule.
     * @param int $now Evaluation time.
     */
    private static function deliver(\core\message\message $message, \stdClass $send,
            \stdClass $state, \stdClass $rule, int $now): void {
        global $DB;
        try {
            $messageid = message_send($message);
        } catch (\Throwable $exception) {
            self::status($send, 'uncertain', 'api_exception', $now);
            mtrace('SIS Notification: ambiguous dispatch ' . $send->id);
            return;
        }
        if (!$messageid) {
            self::status($send, 'uncertain', 'api_result_unknown', $now);
            return;
        }
        // If persistence fails after acceptance, the processing lease becomes uncertain.
        $transaction = $DB->start_delegated_transaction();
        try {
            $send->messageid = $messageid;
            self::status($send, 'accepted', '', $now);
            $state->sentcount++;
            $state->timemodified = $now;
            $state->nextdue = $now + $rule->repeatdays * DAYSECS;
            if (!$rule->repeatdays || $state->sentcount >= $rule->maxsends) {
                $state->status = 'complete';
            }
            $DB->update_record('local_sis_notification_state', $state);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
    }
    /**
     * Persist outbox status without message contents or sensitive exception text.
     * @param \stdClass $send Dispatch.
     * @param string $status State.
     * @param string $reason Reason code.
     * @param int $now Timestamp.
     */
    private static function status(\stdClass $send, string $status, string $reason, int $now): void {
        global $DB;
        $send->status = $status;
        $send->reason = $reason;
        $send->timemodified = $now;
        $DB->update_record('local_sis_notification_send', $send);
    }

    /**
     * Load matching configuration and cycle, rejecting stale reservations.
     * @param \stdClass $send Dispatch.
     * @param int $now Timestamp.
     * @return \stdClass|null
     */
    private static function current_cycle(\stdClass $send, int $now): ?\stdClass {
        global $DB;
        $rule = $DB->get_record('local_sis_notification_rule', ['id' => $send->ruleid]);
        $state = $DB->get_record('local_sis_notification_state', ['ruleid' => $send->ruleid, 'userid' => $send->userid]);
        if (!$rule || !$state || $rule->revision != $send->ruleversion || $state->cycle != $send->cycle) {
            self::status($send, 'suppressed', 'obsolete', $now);
            return null;
        }
        if (!$rule->enabled) {
            self::status($send, 'suppressed', 'rule_paused', $now);
            return null;
        }
        return (object)['rule' => $rule, 'state' => $state];
    }

    /**
     * Revalidate account, enrolment, course and notification condition.
     * @param \stdClass $send Dispatch.
     * @param int $now Timestamp.
     * @return \stdClass|null
     */
    private static function delivery_context(\stdClass $send, int $now): ?\stdClass {
        global $DB;
        $context = self::current_cycle($send, $now);
        if (!$context) {
            return null;
        }
        $rule = $context->rule;
        $state = $context->state;
        $eligibility = recipient_selector::get_eligibility($rule->courseid, $send->userid, $now);
        if ($eligibility->eligible && recipient_selector::has_gap($state, $eligibility)) {
            self::status($send, 'suppressed', 'obsolete', $now);
            return null;
        }
        if (!$eligibility->eligible) {
            self::status($send, 'suppressed', $eligibility->reason, $now);
            return null;
        }
        $course = $DB->get_record('course', ['id' => $rule->courseid]);
        if (!$course) {
            self::status($send, 'suppressed', 'course_deleted', $now);
            return null;
        }
        $reason = self::condition_reason($rule, $state, $course, $send, $now);
        if ($reason !== '') {
            self::status($send, 'suppressed', $reason, $now);
            return null;
        }
        $context->course = $course;
        return $context;
    }

    /**
     * Return a suppression reason, or an empty string when delivery is due.
     * @param \stdClass $rule Rule.
     * @param \stdClass $state Cycle.
     * @param \stdClass $course Course.
     * @param \stdClass $send Dispatch.
     * @param int $now Timestamp.
     * @return string
     */
    private static function condition_reason(\stdClass $rule, \stdClass $state,
            \stdClass $course, \stdClass $send, int $now): string {
        $condition = condition_evaluator::evaluate($rule, $state, $course, $now);
        if (!$condition->allowed || $condition->baseat + $rule->days * DAYSECS > $now ||
                ($rule->conditiontype === 'inactivity' && $condition->lastaccess > $state->lastaccess)) {
            return $condition->reason ?: 'new_access';
        }
        if ($state->status !== 'active' || $state->sentcount >= $rule->maxsends || $send->seq != $state->sentcount + 1) {
            return 'cycle_finished';
        }
        return '';
    }
}
