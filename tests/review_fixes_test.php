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
 * Tests for the behaviour fixed after the code review.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification;

use local_sis_notification\local\condition_evaluator;
use local_sis_notification\local\delivery_worker;
use local_sis_notification\local\dispatch_service;
use local_sis_notification\local\message_renderer;
use local_sis_notification\privacy\provider;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Renderer escaping, privacy erasure, retries, completion, activity deletion and history cleanup.
 *
 * @coversNothing
 */
final class review_fixes_test extends \advanced_testcase {
    /**
     * Restore the static lock timeout changed by the tests.
     */
    protected function tearDown(): void {
        observer::$locktimeout = 30;
        parent::tearDown();
    }

    /**
     * Insert a rule.
     *
     * @param int $courseid
     * @param array $overrides
     * @return \stdClass
     */
    private function rule(int $courseid, array $overrides = []): \stdClass {
        global $DB;
        $now = time();
        $rule = (object) ($overrides + [
            'courseid' => $courseid, 'name' => 'Test', 'conditiontype' => 'inactivity', 'days' => 7, 'cmid' => 0,
            'subject' => 'Hello', 'body' => '<p>Return</p>', 'bodyformat' => FORMAT_HTML, 'repeatdays' => 0,
            'maxsends' => 1, 'enabled' => 1, 'activatedat' => $now - 20 * DAYSECS, 'revision' => 1, 'createdby' => 2,
            'timecreated' => $now, 'timemodified' => $now, 'scanuserid' => 0,
        ]);
        $rule->id = $DB->insert_record('local_sis_notification_rule', $rule);
        return $rule;
    }

    /**
     * Insert a delivery.
     *
     * @param int $ruleid
     * @param int $userid
     * @param string $status
     * @param int $seq
     * @param int $modified
     * @return int
     */
    private function send(int $ruleid, int $userid, string $status, int $seq = 1, int $modified = 0): int {
        global $DB;
        return $DB->insert_record('local_sis_notification_send', (object) [
            'ruleid' => $ruleid, 'userid' => $userid, 'cycle' => 1, 'seq' => $seq, 'ruleversion' => 1, 'dueat' => 0,
            'status' => $status, 'leaseuntil' => 0, 'attempts' => 0, 'reason' => '', 'messageid' => 0,
            'timecreated' => $modified ?: time(), 'timemodified' => $modified ?: time(),
        ]);
    }

    /**
     * An ampersand in a course or activity name is escaped once in the HTML and not at all in the subject.
     */
    public function test_renderer_does_not_double_escape_names(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Gestão & Pessoas']);
        $user = $this->getDataGenerator()->create_user();
        $rule = (object) [
            'conditiontype' => 'inactivity', 'days' => 7, 'cmid' => 0, 'bodyformat' => FORMAT_HTML,
            'subject' => 'Volte para {coursename}', 'body' => '<p>Curso {coursename}</p>',
        ];
        $rendered = message_renderer::render($rule, $user, $course);
        $this->assertSame('Volte para Gestão & Pessoas', $rendered->subject);
        $this->assertStringContainsString('Gestão &amp; Pessoas', $rendered->html);
        $this->assertStringNotContainsString('&amp;amp;', $rendered->html);
        $this->assertStringContainsString('Gestão & Pessoas', $rendered->plain);
    }

    /**
     * Erasing some users keeps the rule enabled; erasing the whole context pauses it.
     */
    public function test_privacy_erasure_pauses_rules_only_for_the_whole_context(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $rule = $this->rule($course->id);
        $this->send($rule->id, $student->id, 'accepted');
        $this->send($rule->id, $other->id, 'accepted');
        $context = \context_course::instance($course->id);

        $userlist = new \core_privacy\local\request\approved_userlist($context, 'local_sis_notification', [$student->id]);
        provider::delete_data_for_users($userlist);
        $this->assertEquals(1, $DB->get_field('local_sis_notification_rule', 'enabled', ['id' => $rule->id]));
        $this->assertFalse($DB->record_exists('local_sis_notification_send', ['ruleid' => $rule->id, 'userid' => $student->id]));
        $this->assertTrue($DB->record_exists('local_sis_notification_send', ['ruleid' => $rule->id, 'userid' => $other->id]));

        provider::delete_data_for_all_users_in_context($context);
        $this->assertEquals(0, $DB->get_field('local_sis_notification_rule', 'enabled', ['id' => $rule->id]));
        $this->assertEquals(0, $DB->count_records('local_sis_notification_send'));
    }

    /**
     * An exception from the messaging API is retried with a delay, then the outcome is unknown.
     */
    public function test_api_exception_is_retried_then_becomes_uncertain(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $rule = $this->rule($course->id);
        $sendid = $this->send($rule->id, $user->id, 'processing');
        $state = (object) ['id' => 0];
        $message = new \core\message\message();
        $message->component = 'local_sis_notification';
        $message->name = 'course_notification';
        $message->notification = 1;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = 'Subject';
        $message->fullmessage = 'Body';
        // An invalid format makes message_send() throw.
        $message->fullmessageformat = new \stdClass();
        $deliver = new \ReflectionMethod(delivery_worker::class, 'deliver');
        $now = time();

        foreach ([1, 2] as $attempts) {
            $DB->set_field('local_sis_notification_send', 'attempts', $attempts, ['id' => $sendid]);
            $send = $DB->get_record('local_sis_notification_send', ['id' => $sendid]);
            $deliver->invoke(null, $message, $send, $state, $rule, $now);
            $send = $DB->get_record('local_sis_notification_send', ['id' => $sendid]);
            $this->assertEquals('pending', $send->status);
            $this->assertEquals('api_exception', $send->reason);
            $this->assertEquals($now + 300 * $attempts, $send->dueat);
        }

        $DB->set_field('local_sis_notification_send', 'attempts', 3, ['id' => $sendid]);
        $send = $DB->get_record('local_sis_notification_send', ['id' => $sendid]);
        $deliver->invoke(null, $message, $send, $state, $rule, $now);
        $this->assertEquals('uncertain', $DB->get_field('local_sis_notification_send', 'status', ['id' => $sendid]));
        $this->expectOutputRegex('/SIS Notification/');
    }

    /**
     * Disabled completion is a temporary condition, so the cycle of the student does not end.
     */
    public function test_disabled_completion_is_temporary(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 0, 'startdate' => time() - 30 * DAYSECS]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $DB->set_field('user_enrolments', 'timecreated', time() - 20 * DAYSECS, ['userid' => $user->id]);
        $rule = $this->rule($course->id, ['conditiontype' => 'course_incomplete']);

        $state = (object) ['userid' => $user->id, 'baseat' => 0, 'lastaccess' => 0, 'enrolat' => 0];
        $result = condition_evaluator::evaluate($rule, $state, get_course($course->id), time());
        $this->assertFalse($result->allowed);
        $this->assertEquals('completion_disabled', $result->reason);
        $this->assertTrue($result->temporary);

        dispatch_service::scan(time());
        $this->assertEquals('active', $DB->get_field('local_sis_notification_state', 'status', ['ruleid' => $rule->id]));
    }

    /**
     * Deleting the target activity pauses the rule, restarts its cycle and explains the cancelled deliveries.
     */
    public function test_activity_deleted_pauses_rule_and_records_reason(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'completion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $rule = $this->rule($course->id, ['conditiontype' => 'activity_incomplete', 'cmid' => $page->cmid]);
        $pending = $this->send($rule->id, $user->id, 'pending');
        $accepted = $this->send($rule->id, $user->id, 'accepted', 2);

        // Moodle 5.2 deprecated course_delete_module() in favour of the course format actions.
        if (method_exists(\core_courseformat\local\cmactions::class, 'delete')) {
            (new \core_courseformat\local\cmactions($course))->delete($page->cmid);
        } else {
            course_delete_module($page->cmid);
        }

        $stored = $DB->get_record('local_sis_notification_rule', ['id' => $rule->id]);
        $this->assertEquals(0, $stored->enabled);
        $this->assertEquals(2, $stored->revision);
        $this->assertEquals(0, $stored->activatedat);
        $this->assertEquals('suppressed', $DB->get_field('local_sis_notification_send', 'status', ['id' => $pending]));
        $this->assertEquals('activity_deleted', $DB->get_field('local_sis_notification_send', 'reason', ['id' => $pending]));
        $this->assertEquals('accepted', $DB->get_field('local_sis_notification_send', 'status', ['id' => $accepted]));
    }

    /**
     * Hold the lock of a rule with a lock type that is not re-entrant inside one process.
     *
     * @param int $ruleid
     * @return \core\lock\lock
     */
    private function hold_rule_lock(int $ruleid): \core\lock\lock {
        global $CFG;
        $CFG->lock_factory = '\core\lock\file_lock_factory';
        // Lifecycle observers would wait for the real timeout otherwise.
        observer::$locktimeout = 1;
        $lock = \core\lock\lock_config::get_lock_factory('local_sis_notification')->get_lock('rule:' . $ruleid, 5);
        $this->assertNotFalse($lock);
        return $lock;
    }

    /**
     * A student that leaves the course ends the cycle at once, or later when the rule is locked.
     */
    public function test_unenrolment_ends_the_cycle_now_or_through_a_task(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $rule = $this->rule($course->id);
        $DB->insert_record('local_sis_notification_state', (object) [
            'ruleid' => $rule->id, 'userid' => $user->id, 'status' => 'active', 'timecreated' => 1, 'timemodified' => 1,
        ]);
        $event = \core\event\user_enrolment_deleted::create([
            'context' => \context_course::instance($course->id), 'objectid' => 1, 'courseid' => $course->id,
            'relateduserid' => $user->id, 'other' => ['userenrolment' => ['lastenrol' => 1], 'enrol' => 'manual'],
        ]);

        $lock = $this->hold_rule_lock($rule->id);
        observer::enrolment_deleted($event);
        $this->assertEquals('active', $DB->get_field('local_sis_notification_state', 'status', ['ruleid' => $rule->id]));
        $tasks = \core\task\manager::get_adhoc_tasks(task\lifecycle_cleanup::class);
        $this->assertCount(1, $tasks);

        $lock->release();
        reset($tasks)->execute();
        $this->assertEquals('ended', $DB->get_field('local_sis_notification_state', 'status', ['ruleid' => $rule->id]));
    }

    /**
     * Deleting an account never fails because a rule is locked; the cleanup is finished by a task.
     */
    public function test_user_deletion_cleanup_is_deferred_while_the_rule_is_locked(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $rule = $this->rule($course->id, ['createdby' => $user->id]);
        foreach ([$user, $other] as $account) {
            $DB->insert_record('local_sis_notification_state', (object) [
                'ruleid' => $rule->id, 'userid' => $account->id, 'status' => 'active', 'timecreated' => 1, 'timemodified' => 1,
            ]);
            $this->send($rule->id, $account->id, 'accepted');
        }
        $event = \core\event\user_deleted::create([
            'objectid' => $user->id, 'relateduserid' => $user->id, 'context' => \context_user::instance($user->id),
            'other' => ['username' => 'x', 'email' => 'x@example.com', 'idnumber' => '', 'picture' => 0,
                'mnethostid' => 1],
        ]);

        $lock = $this->hold_rule_lock($rule->id);
        observer::user_deleted($event);
        $this->assertTrue($DB->record_exists('local_sis_notification_state', ['userid' => $user->id]));
        $tasks = \core\task\manager::get_adhoc_tasks(task\lifecycle_cleanup::class);
        $this->assertCount(1, $tasks);

        $lock->release();
        reset($tasks)->execute();
        $this->assertFalse($DB->record_exists('local_sis_notification_state', ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists('local_sis_notification_send', ['userid' => $user->id]));
        $this->assertEquals(0, $DB->get_field('local_sis_notification_rule', 'createdby', ['id' => $rule->id]));
        $this->assertTrue($DB->record_exists('local_sis_notification_state', ['userid' => $other->id]));
        $this->assertTrue($DB->record_exists('local_sis_notification_send', ['userid' => $other->id]));
    }

    /**
     * A delivery waits for the next scan, quietly, while the scanner or another worker holds the rule.
     */
    public function test_worker_does_nothing_while_the_rule_is_locked(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $rule = $this->rule($course->id);
        $sendid = $this->send($rule->id, $user->id, 'pending');

        $lock = $this->hold_rule_lock($rule->id);
        dispatch_service::process($sendid, time());
        $lock->release();

        $this->assertEquals('pending', $DB->get_field('local_sis_notification_send', 'status', ['id' => $sendid]));
        $this->assertEquals(0, $DB->get_field('local_sis_notification_send', 'attempts', ['id' => $sendid]));
    }

    /**
     * Retention removes old accepted and suppressed deliveries and keeps the rest.
     */
    public function test_cleanup_removes_only_terminal_history(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('retentiondays', 30, 'local_sis_notification');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $rule = $this->rule($course->id);
        $old = time() - 31 * DAYSECS;
        $seq = 0;
        $ids = [];
        foreach (['accepted', 'suppressed', 'uncertain', 'pending', 'processing'] as $status) {
            $ids[$status] = $this->send($rule->id, $user->id, $status, ++$seq, $old);
        }
        $recent = $this->send($rule->id, $user->id, 'suppressed', ++$seq);

        (new task\cleanup_history())->execute();

        foreach (['accepted', 'suppressed'] as $status) {
            $this->assertFalse($DB->record_exists('local_sis_notification_send', ['id' => $ids[$status]]), $status);
        }
        foreach (['uncertain', 'pending', 'processing'] as $status) {
            $this->assertTrue($DB->record_exists('local_sis_notification_send', ['id' => $ids[$status]]), $status);
        }
        $this->assertTrue($DB->record_exists('local_sis_notification_send', ['id' => $recent]));
    }
}
