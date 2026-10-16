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
 * Notification eligibility and delivery tests.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification;

use local_sis_notification\local\dispatch_service;
use local_sis_notification\local\recipient_selector;

/**
 * Exercise real enrolments, completion and the messaging sink.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(dispatch_service::class)]
final class engine_test extends \advanced_testcase {
    /**
     * Create a rule and enrolled student.
     * @return array
     */
    private function fixture(): array {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'startdate' => time() - 30 * DAYSECS]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $now = time();
        $DB->set_field('user_enrolments', 'timecreated', $now - 20 * DAYSECS, ['userid' => $user->id]);
        $rule = (object)[
            'courseid' => $course->id, 'name' => 'Return', 'conditiontype' => 'inactivity', 'days' => 7,
            'cmid' => 0, 'subject' => 'Hello {firstname}', 'body' => '<p>Return to {coursename}: {courseurl}</p>',
            'bodyformat' => FORMAT_HTML, 'repeatdays' => 0, 'maxsends' => 1, 'enabled' => 1,
            'activatedat' => $now - 20 * DAYSECS, 'revision' => 1, 'createdby' => 2,
            'timecreated' => $now, 'timemodified' => $now, 'scanuserid' => 0,
        ];
        $rule->id = $DB->insert_record('local_sis_notification_rule', $rule);
        return [$course, $user, $rule, $now];
    }

    /**
     * Suspended accounts and enrolments cannot receive a queued notification.
     */
    public function test_active_user_and_enrolment_are_rechecked(): void {
        global $DB;
        [$course, $user, $rule, $now] = $this->fixture();
        $this->assertTrue(recipient_selector::get_eligibility($course->id, $user->id, $now)->eligible);
        dispatch_service::scan($now);
        $send = $DB->get_record('local_sis_notification_send', ['ruleid' => $rule->id], '*', MUST_EXIST);
        $sink = $this->redirectMessages();
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        dispatch_service::process($send->id, $now);
        $this->assertCount(0, $sink->get_messages());
        $DB->set_field('user', 'suspended', 0, ['id' => $user->id]);
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['userid' => $user->id]);
        $this->assertFalse(recipient_selector::get_eligibility($course->id, $user->id, $now)->eligible);
        dispatch_service::process($send->id, $now);
        $this->assertCount(0, $sink->get_messages());
        $DB->set_field('user_enrolments', 'status', ENROL_USER_ACTIVE, ['userid' => $user->id]);
        dispatch_service::scan($now);
        dispatch_service::process($send->id, $now);
        $this->assertCount(1, $sink->get_messages());
        dispatch_service::process($send->id, $now);
        dispatch_service::scan($now);
        $this->assertCount(1, $sink->get_messages());
        $this->assertEquals('accepted', $DB->get_field('local_sis_notification_send', 'status', ['id' => $send->id]));
    }

    /**
     * Every eligibility gate must pass, not just an active account.
     */
    public function test_expired_future_disabled_and_unconfirmed_are_excluded(): void {
        global $DB;
        [$course, $user, , $now] = $this->fixture();
        foreach (['timeend' => $now, 'timestart' => $now + 1] as $field => $value) {
            $DB->set_field('user_enrolments', $field, $value, ['userid' => $user->id]);
            $this->assertFalse(recipient_selector::get_eligibility($course->id, $user->id, $now)->eligible);
            $DB->set_field('user_enrolments', $field, 0, ['userid' => $user->id]);
        }
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['courseid' => $course->id]);
        $this->assertFalse(recipient_selector::get_eligibility($course->id, $user->id, $now)->eligible);
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['courseid' => $course->id]);
        foreach (['confirmed' => 0, 'deleted' => 1] as $field => $value) {
            $DB->set_field('user', $field, $value, ['id' => $user->id]);
            $this->assertFalse(recipient_selector::get_eligibility($course->id, $user->id, $now)->eligible);
            $DB->set_field('user', $field, 1 - $value, ['id' => $user->id]);
        }
    }

    /**
     * Thresholds use course access, never site access.
     */
    public function test_threshold_and_new_inactivity_episode(): void {
        global $DB;
        [$course, $user, $rule, $now] = $this->fixture();
        $DB->set_field('local_sis_notification_rule', 'activatedat', $now - 7 * DAYSECS + 1, ['id' => $rule->id]);
        dispatch_service::scan($now);
        $this->assertEquals(0, $DB->count_records('local_sis_notification_send'));
        dispatch_service::scan($now + 1);
        $this->assertEquals(1, $DB->count_records('local_sis_notification_send'));
        $sink = $this->redirectMessages();
        $send = $DB->get_record('local_sis_notification_send', ['ruleid' => $rule->id]);
        dispatch_service::process($send->id, $now + 1);
        $this->assertCount(1, $sink->get_messages());
        $DB->insert_record('user_lastaccess', (object)['userid' => $user->id, 'courseid' => $course->id, 'timeaccess' => $now + 2]);
        dispatch_service::scan($now + 2);
        $this->assertEquals(1, $DB->count_records('local_sis_notification_send'));
        dispatch_service::scan($now + 2 + 7 * DAYSECS);
        $this->assertEquals(2, $DB->count_records('local_sis_notification_send'));
    }

    /**
     * A stale processing lease has an ambiguous outcome and is not resent.
     */
    public function test_processing_lease_becomes_uncertain(): void {
        global $DB;
        [, , , $now] = $this->fixture();
        dispatch_service::scan($now);
        $send = $DB->get_record('local_sis_notification_send', []);
        $DB->update_record(
            'local_sis_notification_send',
            (object)['id' => $send->id, 'status' => 'processing', 'leaseuntil' => $now - 1]
        );
        $sink = $this->redirectMessages();
        dispatch_service::process($send->id, $now);
        $this->assertEquals('uncertain', $DB->get_field('local_sis_notification_send', 'status', ['id' => $send->id]));
        $this->assertCount(0, $sink->get_messages());
    }

    /**
     * Repetition does not catch up missed intervals or exceed its cap.
     */
    public function test_repeat_limit_and_late_cron(): void {
        global $DB;
        [, , $rule, $now] = $this->fixture();
        $DB->update_record('local_sis_notification_rule', (object)['id' => $rule->id, 'repeatdays' => 2, 'maxsends' => 2]);
        $sink = $this->redirectMessages();
        dispatch_service::scan($now);
        dispatch_service::process($DB->get_field('local_sis_notification_send', 'id', []), $now);
        dispatch_service::scan($now + 20 * DAYSECS);
        $sends = $DB->get_records('local_sis_notification_send', [], 'id');
        $this->assertCount(2, $sends);
        dispatch_service::process(end($sends)->id, $now + 20 * DAYSECS);
        dispatch_service::scan($now + 40 * DAYSECS);
        $this->assertCount(2, $sink->get_messages());
        $this->assertEquals(2, $DB->count_records('local_sis_notification_send'));
    }

    /**
     * Course completion after reservation suppresses a pending notification.
     */
    public function test_course_completion_rechecked_before_send(): void {
        global $CFG, $DB;
        [$course, $user, $rule, $now] = $this->fixture();
        $DB->set_field('local_sis_notification_rule', 'conditiontype', 'course_incomplete', ['id' => $rule->id]);
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria.php');
        $DB->insert_record('course_completion_criteria', (object)['course' => $course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_SELF]);
        dispatch_service::scan($now);
        $send = $DB->get_record('local_sis_notification_send', ['ruleid' => $rule->id], '*', MUST_EXIST);
        $completion = new \completion_completion(['course' => $course->id, 'userid' => $user->id]);
        $completion->mark_complete($now);
        $sink = $this->redirectMessages();
        dispatch_service::process($send->id, $now);
        $this->assertCount(0, $sink->get_messages());
        $this->assertEquals('completed', $DB->get_field('local_sis_notification_send', 'reason', ['id' => $send->id]));
    }

    /**
     * Activity timing starts only when available and completion suppresses the queued message.
     */
    public function test_activity_availability_and_completion(): void {
        global $DB;
        [$course, $user, $rule, $now] = $this->fixture();
        $activity = $this->getDataGenerator()->create_module('page', ['course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL, 'visible' => 0]);
        $DB->update_record('local_sis_notification_rule', (object)['id' => $rule->id,
            'conditiontype' => 'activity_incomplete', 'cmid' => $activity->cmid]);
        dispatch_service::scan($now);
        $this->assertEquals(0, $DB->count_records('local_sis_notification_send'));
        set_coursemodule_visible($activity->cmid, 1);
        rebuild_course_cache($course->id, true);
        dispatch_service::scan($now + DAYSECS);
        $this->assertEquals(0, $DB->count_records('local_sis_notification_send'));
        dispatch_service::scan($now + 8 * DAYSECS);
        $send = $DB->get_record('local_sis_notification_send', ['ruleid' => $rule->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_id('page', $activity->cmid);
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $user->id);
        $sink = $this->redirectMessages();
        dispatch_service::process($send->id, $now + 8 * DAYSECS);
        $this->assertCount(0, $sink->get_messages());
        $this->assertEquals('completed', $DB->get_field('local_sis_notification_send', 'reason', ['id' => $send->id]));
    }

    /**
     * Multiple active enrolments keep one dispatch and preserve the original cycle base.
     */
    public function test_overlapping_enrolments_preserve_baseline(): void {
        global $DB;
        [$course, $user, $rule, $now] = $this->fixture();
        dispatch_service::scan($now);
        $original = $DB->get_record('local_sis_notification_state', ['ruleid' => $rule->id]);
        $instance = enrol_get_plugin('self')->add_instance($course);
        enrol_get_plugin('self')->enrol_user(
            $DB->get_record('enrol', ['id' => $instance]),
            $user->id,
            $DB->get_field('role', 'id', ['shortname' => 'student']),
            $now - DAYSECS
        );
        $DB->set_field('user_enrolments', 'timeend', $now, ['userid' => $user->id, 'enrolid' =>
            $DB->get_field('enrol', 'id', ['courseid' => $course->id, 'enrol' => 'manual'], IGNORE_MULTIPLE)]);
        dispatch_service::scan($now);
        $state = $DB->get_record('local_sis_notification_state', ['id' => $original->id]);
        $this->assertEquals($original->baseat, $state->baseat);
        $this->assertEquals(1, $DB->count_records('local_sis_notification_send'));
    }

    /**
     * The renderer strips injected markup in variables and unsafe HTML from the body.
     */
    public function test_renderer_cleans_html_and_subject(): void {
        [$course, $user, $rule] = $this->fixture();
        $user->firstname = '<img src=x onerror=alert(1)>';
        $rule->body = '<script>alert(1)</script><p>{firstname}</p>';
        $rendered = \local_sis_notification\local\message_renderer::render($rule, $user, $course);
        $this->assertStringNotContainsString('<script', $rendered->html);
        $this->assertStringNotContainsString('<img', $rendered->html);
        $this->assertStringNotContainsString('<img', $rendered->subject);
    }

    /**
     * Scheduled discovery reaches the real ad hoc task and message boundary.
     */
    public function test_real_adhoc_task_delivers_reserved_notification(): void {
        global $DB;
        [, , , $now] = $this->fixture();
        $sink = $this->redirectMessages();
        dispatch_service::scan($now);
        $this->runAdhocTasks('local_sis_notification\\task\\send_notifications');
        $this->assertCount(1, $sink->get_messages());
        $this->assertEquals(1, $DB->count_records('local_sis_notification_send', ['status' => 'accepted']));
    }
}
