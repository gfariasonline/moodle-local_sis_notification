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

namespace local_sis_notification;

use local_sis_notification\local\rule_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/rule_test_helper.php');

/**
 * Tests for enabling, pausing and deleting rules.
 *
 * @package    local_sis_notification
 * @category   test
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(rule_service::class)]
final class rule_state_test extends \advanced_testcase {
    use rule_test_helper;

    public function test_set_enabled_activates_and_keeps_date_on_resume(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $clock = $this->mock_clock_with_frozen(1700000000);
        $course = $this->create_course();
        $id = rule_service::save($this->rule_data(), $course->id);

        rule_service::set_enabled($id, $course->id, true);
        $rule = $DB->get_record('local_sis_notification_rule', ['id' => $id]);
        $this->assertEquals(1, $rule->enabled);
        $this->assertEquals(1700000000, $rule->activatedat);

        $clock->bump(3600);
        rule_service::set_enabled($id, $course->id, false);
        $rule = $DB->get_record('local_sis_notification_rule', ['id' => $id]);
        $this->assertEquals(0, $rule->enabled);
        $this->assertEquals(1700000000, $rule->activatedat);

        $clock->bump(3600);
        rule_service::set_enabled($id, $course->id, true);
        $rule = $DB->get_record('local_sis_notification_rule', ['id' => $id]);
        $this->assertEquals(1, $rule->enabled);
        $this->assertEquals(1700000000, $rule->activatedat);
        $this->assertEquals(1, $rule->revision);
    }

    public function test_set_enabled_revalidates_before_enabling(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $id = rule_service::save($this->rule_data([
            'conditiontype' => 'activity_incomplete', 'cmid' => $page->cmid,
        ]), $course->id);

        // The activity loses its completion tracking before the rule is enabled.
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_NONE, ['id' => $page->cmid]);
        rebuild_course_cache($course->id, true);

        try {
            rule_service::set_enabled($id, $course->id, true);
            $this->fail('Expected an exception');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('cmid', $e->debuginfo);
        }
        $this->assertEquals(0, $DB->get_field('local_sis_notification_rule', 'enabled', ['id' => $id]));
    }

    public function test_pause_suppresses_only_pending_sends(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $users = [];
        for ($i = 0; $i < 6; $i++) {
            $users[] = $this->getDataGenerator()->create_user();
        }
        $id = rule_service::save($this->rule_data(), $course->id);
        $otherid = rule_service::save($this->rule_data(['name' => 'Other']), $course->id);
        rule_service::set_enabled($id, $course->id, true);

        $statuses = ['pending', 'accepted', 'processing', 'uncertain', 'failed', 'suppressed'];
        $sendids = [];
        foreach ($statuses as $index => $status) {
            $sendids[$status] = $this->add_send($id, $users[$index]->id, $status);
        }
        $otherpending = $this->add_send($otherid, $users[0]->id, 'pending');
        $this->add_state($id, $users[0]->id, 3);

        rule_service::set_enabled($id, $course->id, false);

        $this->assertEquals('suppressed', $DB->get_field('local_sis_notification_send', 'status', ['id' => $sendids['pending']]));
        $this->assertEquals('rule_paused', $DB->get_field('local_sis_notification_send', 'reason', ['id' => $sendids['pending']]));
        foreach (['accepted', 'processing', 'uncertain', 'failed', 'suppressed'] as $status) {
            $send = $DB->get_record('local_sis_notification_send', ['id' => $sendids[$status]]);
            $this->assertEquals($status, $send->status);
            $this->assertSame('', $send->reason);
            $this->assertEquals(100, $send->timemodified);
        }
        $this->assertEquals('pending', $DB->get_field('local_sis_notification_send', 'status', ['id' => $otherpending]));
        $this->assertEquals(3, $DB->get_field('local_sis_notification_state', 'sentcount', ['ruleid' => $id]));
    }

    public function test_set_enabled_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $clock = $this->mock_clock_with_frozen(1700000000);
        $course = $this->create_course();
        $id = rule_service::save($this->rule_data(), $course->id);
        rule_service::set_enabled($id, $course->id, true);
        $clock->bump(500);
        rule_service::set_enabled($id, $course->id, true);
        $this->assertEquals(1700000000, $DB->get_field('local_sis_notification_rule', 'activatedat', ['id' => $id]));
        rule_service::set_enabled($id, $course->id, false);
        rule_service::set_enabled($id, $course->id, false);
        $this->assertEquals(0, $DB->get_field('local_sis_notification_rule', 'enabled', ['id' => $id]));
    }

    public function test_set_enabled_scoped_to_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $other = $this->create_course();
        $id = rule_service::save($this->rule_data(), $course->id);
        $this->expectException(\dml_missing_record_exception::class);
        rule_service::set_enabled($id, $other->id, true);
    }

    public function test_delete_removes_rule_states_and_sends_only_for_that_rule(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $user = $this->getDataGenerator()->create_user();
        $id = rule_service::save($this->rule_data(), $course->id);
        $keep = rule_service::save($this->rule_data(['name' => 'Keep']), $course->id);
        $this->add_state($id, $user->id);
        $this->add_send($id, $user->id, 'accepted');
        $this->add_state($keep, $user->id);
        $this->add_send($keep, $user->id, 'pending');

        rule_service::delete($id, $course->id);

        $this->assertFalse($DB->record_exists('local_sis_notification_rule', ['id' => $id]));
        $this->assertEquals(0, $DB->count_records('local_sis_notification_state', ['ruleid' => $id]));
        $this->assertEquals(0, $DB->count_records('local_sis_notification_send', ['ruleid' => $id]));
        $this->assertTrue($DB->record_exists('local_sis_notification_rule', ['id' => $keep]));
        $this->assertEquals(1, $DB->count_records('local_sis_notification_state', ['ruleid' => $keep]));
        $this->assertEquals(1, $DB->count_records('local_sis_notification_send', ['ruleid' => $keep]));
    }

    public function test_delete_is_scoped_to_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $other = $this->create_course();
        $id = rule_service::save($this->rule_data(), $course->id);
        try {
            rule_service::delete($id, $other->id);
            $this->fail('Expected an exception');
        } catch (\dml_missing_record_exception $e) {
            $this->assertTrue($DB->record_exists('local_sis_notification_rule', ['id' => $id]));
        }
    }

    public function test_operations_fail_while_rule_lock_is_held(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $id = rule_service::save($this->rule_data(), $course->id);

        // The default MySQL lock is re-entrant inside one connection, so use a lock type that is not.
        $factory = new \core\lock\file_lock_factory('local_sis_notification');
        $lock = $factory->get_lock('rule:' . $id, 5);
        $this->assertNotFalse($lock);
        rule_service::$lockfactory = $factory;
        $previous = rule_service::$locktimeout;
        rule_service::$locktimeout = 1;
        try {
            foreach (['save', 'enable', 'delete'] as $operation) {
                try {
                    match ($operation) {
                        'save' => rule_service::save($this->rule_data(['id' => $id]), $course->id),
                        'enable' => rule_service::set_enabled($id, $course->id, true),
                        'delete' => rule_service::delete($id, $course->id),
                    };
                    $this->fail("{$operation} should not run while the rule is locked");
                } catch (\moodle_exception $e) {
                    $this->assertEquals('rulelocked', $e->errorcode);
                }
            }
        } finally {
            rule_service::$locktimeout = $previous;
            rule_service::$lockfactory = null;
            $lock->release();
        }

        $this->assertTrue($DB->record_exists('local_sis_notification_rule', ['id' => $id]));
        $this->assertEquals(0, $DB->get_field('local_sis_notification_rule', 'enabled', ['id' => $id]));

        // The lock is released after each operation, so a normal call works again.
        rule_service::set_enabled($id, $course->id, true);
        rule_service::delete($id, $course->id);
        $this->assertFalse($DB->record_exists('local_sis_notification_rule', ['id' => $id]));
    }
}
