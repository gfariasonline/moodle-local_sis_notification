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
 * Regression tests for enrolment continuity and scan limits.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification;

use local_sis_notification\local\dispatch_service;

/**
 * Cover delayed cron and asynchronous completion.
 *
 * @coversNothing
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class regression_test extends \advanced_testcase {
    /**
     * Return an active old enrolment with a rule.
     * @return array
     */
    private function fixture(): array {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'startdate' => time() - 40 * DAYSECS]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $now = time();
        $DB->set_field('user_enrolments', 'timestart', $now - 30 * DAYSECS, ['userid' => $user->id]);
        $ruleid = $DB->insert_record('local_sis_notification_rule', (object)['courseid' => $course->id, 'name' => 'Reminder',
            'subject' => 'Hello', 'body' => 'Return', 'enabled' => 1, 'activatedat' => $now - 30 * DAYSECS, 'days' => 7]);
        return [$course, $user, $ruleid, $now];
    }

    /**
     * A gap between two enrolment periods opens a fresh cycle even without a scan during the gap.
     */
    public function test_new_enrolment_after_gap_restarts_clock(): void {
        global $DB;
        [$course, $user, $ruleid, $now] = $this->fixture();
        dispatch_service::scan($now);
        $original = $DB->get_record('local_sis_notification_state', ['ruleid' => $ruleid]);
        // The unlimited enrolment becomes finite after the original scan.
        $DB->set_field('user_enrolments', 'timeend', $now + DAYSECS, ['userid' => $user->id]);
        $enrol = enrol_get_plugin('self');
        $instance = $enrol->add_instance($course);
        $enrol->enrol_user(
            $DB->get_record('enrol', ['id' => $instance]),
            $user->id,
            $DB->get_field('role', 'id', ['shortname' => 'student']),
            $now + 3 * DAYSECS
        );
        dispatch_service::scan($now + 4 * DAYSECS);
        $state = $DB->get_record('local_sis_notification_state', ['id' => $original->id]);
        $this->assertEquals($original->cycle + 1, $state->cycle);
        $this->assertEquals($now + 3 * DAYSECS, $state->enrolat);
        $this->assertEquals($now + 10 * DAYSECS, $state->nextdue);
    }

    /**
     * An outstanding completion aggregation defers delivery without mutating completion.
     */
    public function test_pending_completion_aggregation_defers(): void {
        global $CFG, $DB;
        [$course, $user, $ruleid, $now] = $this->fixture();
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria.php');
        $DB->set_field('local_sis_notification_rule', 'conditiontype', 'course_incomplete', ['id' => $ruleid]);
        $DB->insert_record('course_completion_criteria', (object)['course' => $course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_SELF]);
        dispatch_service::scan($now);
        $send = $DB->get_record('local_sis_notification_send', ['ruleid' => $ruleid], '*', MUST_EXIST);
        $DB->insert_record('course_completions', (object)['course' => $course->id, 'userid' => $user->id, 'reaggregate' => $now]);
        $sink = $this->redirectMessages();
        dispatch_service::process($send->id, $now);
        $this->assertCount(0, $sink->get_messages());
        $this->assertEquals('completion_pending', $DB->get_field('local_sis_notification_send', 'reason', ['id' => $send->id]));
        $DB->set_field('course_completions', 'reaggregate', 0, ['course' => $course->id, 'userid' => $user->id]);
        dispatch_service::scan($now);
        dispatch_service::process($send->id, $now);
        $this->assertCount(1, $sink->get_messages());
    }

    /**
     * A global batch budget advances fairly between courses and rules.
     */
    public function test_global_batch_budget_and_rule_cursor(): void {
        global $DB;
        [, , $ruleid, $now] = $this->fixture();
        $second = $DB->get_record('local_sis_notification_rule', ['id' => $ruleid]);
        unset($second->id);
        $secondid = $DB->insert_record('local_sis_notification_rule', $second);
        set_config('batchsize', 1, 'local_sis_notification');
        dispatch_service::scan($now);
        $this->assertEquals(1, $DB->count_records('local_sis_notification_send'));
        dispatch_service::scan($now);
        $this->assertTrue($DB->record_exists('local_sis_notification_send', ['ruleid' => $secondid]));
    }

    /**
     * An unrelated old enrolment cannot turn overlapping enrolments into a gap.
     */
    public function test_historical_period_does_not_restart_overlapping_cycle(): void {
        global $DB;
        [$course, $user, $ruleid, $now] = $this->fixture();
        $manual = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $DB->set_field('user_enrolments', 'timeend', $now + 30 * DAYSECS, ['userid' => $user->id]);
        $oldinstance = $DB->insert_record('enrol', (object)['enrol' => 'manual', 'courseid' => $course->id, 'status' => 0]);
        $DB->insert_record('user_enrolments', (object)['enrolid' => $oldinstance, 'userid' => $user->id,
            'timestart' => $now - 60 * DAYSECS, 'timeend' => $now - 50 * DAYSECS]);
        dispatch_service::scan($now);
        $original = $DB->get_record('local_sis_notification_state', ['ruleid' => $ruleid]);
        $plugin = enrol_get_plugin('self');
        $instance = $plugin->add_instance($course);
        $plugin->enrol_user(
            $DB->get_record('enrol', ['id' => $instance]),
            $user->id,
            $DB->get_field('role', 'id', ['shortname' => 'student']),
            $now - 10 * DAYSECS
        );
        $DB->delete_records('user_enrolments', ['userid' => $user->id, 'enrolid' => $manual->id]);
        dispatch_service::scan($now + 1);
        $state = $DB->get_record('local_sis_notification_state', ['id' => $original->id]);
        $this->assertEquals($original->cycle, $state->cycle);
        $this->assertEquals($original->baseat, $state->baseat);
    }
}
