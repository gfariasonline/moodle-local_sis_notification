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
 * Tests for edits of existing rules and for restarting their cycle.
 *
 * @package    local_sis_notification
 * @category   test
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_sis_notification\local\rule_service
 */
#[\PHPUnit\Framework\Attributes\CoversClass(rule_service::class)]
final class rule_cycle_test extends \advanced_testcase {
    use rule_test_helper;

    public function test_editing_text_keeps_counters_and_revision(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $user = $this->getDataGenerator()->create_user();
        $id = rule_service::save($this->rule_data(), $course->id);
        rule_service::set_enabled($id, $course->id, false);
        $state = $this->add_state($id, $user->id, 2);
        $pending = $this->add_send($id, $user->id, 'pending');
        $before = $DB->get_record('local_sis_notification_rule', ['id' => $id]);

        rule_service::save($this->rule_data([
            'id' => $id, 'subject' => 'New subject', 'body' => '<p>New body</p>', 'repeatdays' => 5, 'maxsends' => 3,
            'name' => 'New name',
        ]), $course->id);

        $after = $DB->get_record('local_sis_notification_rule', ['id' => $id]);
        $this->assertEquals('New subject', $after->subject);
        $this->assertEquals($before->revision, $after->revision);
        $this->assertEquals($before->activatedat, $after->activatedat);
        $this->assertEquals($before->scanuserid, $after->scanuserid);
        $this->assertEquals(2, $DB->get_field('local_sis_notification_state', 'sentcount', ['id' => $state]));
        $this->assertEquals('pending', $DB->get_field('local_sis_notification_send', 'status', ['id' => $pending]));
    }

    public function test_editing_text_of_enabled_rule_is_allowed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $id = rule_service::save($this->rule_data(), $course->id);
        rule_service::set_enabled($id, $course->id, true);

        rule_service::save($this->rule_data(['id' => $id, 'subject' => 'Changed']), $course->id);

        $rule = $DB->get_record('local_sis_notification_rule', ['id' => $id]);
        $this->assertEquals('Changed', $rule->subject);
        $this->assertEquals(1, $rule->enabled);
    }

    /**
     * Provide test cases.
     *
     * @return array
     */
    public static function cycle_change_provider(): array {
        return [
            'days' => [['days' => 14]],
            'conditiontype' => [['conditiontype' => 'course_incomplete']],
        ];
    }

    /**
     * A cycle-defining change needs a paused rule and the reset checkbox.
     *
     * @dataProvider cycle_change_provider
     * @param array $change Changed form values.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cycle_change_provider')]
    public function test_cycle_change_requires_paused_rule_and_checkbox(array $change): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $DB->insert_record('course_completion_criteria', (object) [
            'course' => $course->id, 'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module' => 'page', 'moduleinstance' => $page->cmid,
        ]);
        $id = rule_service::save($this->rule_data(), $course->id);

        // Enabled rule: rejected even with the checkbox.
        rule_service::set_enabled($id, $course->id, true);
        $errors = rule_service::validate($this->rule_data(['id' => $id, 'resetcycle' => 1] + $change), $course->id);
        $this->assertArrayHasKey(array_key_first($change), $errors);

        // Paused rule without the checkbox: rejected.
        rule_service::set_enabled($id, $course->id, false);
        $errors = rule_service::validate($this->rule_data(['id' => $id] + $change), $course->id);
        $this->assertArrayHasKey('resetcycle', $errors);

        // Paused rule with the checkbox: accepted.
        $errors = rule_service::validate($this->rule_data(['id' => $id, 'resetcycle' => 1] + $change), $course->id);
        $this->assertSame([], $errors);
    }

    public function test_cmid_change_requires_checkbox(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $one = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $two = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $base = ['conditiontype' => 'activity_incomplete', 'cmid' => $one->cmid];
        $id = rule_service::save($this->rule_data($base), $course->id);

        $errors = rule_service::validate($this->rule_data(['id' => $id, 'cmid' => $two->cmid] + $base), $course->id);
        $this->assertArrayHasKey('resetcycle', $errors);
        $errors = rule_service::validate(
            $this->rule_data(['id' => $id, 'cmid' => $two->cmid, 'resetcycle' => 1] + $base),
            $course->id
        );
        $this->assertSame([], $errors);
    }

    public function test_unchanged_cycle_fields_do_not_require_checkbox(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $id = rule_service::save($this->rule_data(), $course->id);
        $this->assertSame([], rule_service::validate($this->rule_data(['id' => $id, 'name' => 'x']), $course->id));
    }

    public function test_cycle_reset_bumps_revision_and_suppresses_pending(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $users = [];
        for ($i = 0; $i < 3; $i++) {
            $users[] = $this->getDataGenerator()->create_user();
        }
        $id = rule_service::save($this->rule_data(), $course->id);
        rule_service::set_enabled($id, $course->id, true);
        rule_service::set_enabled($id, $course->id, false);
        $DB->set_field('local_sis_notification_rule', 'scanuserid', 55, ['id' => $id]);
        $pending = $this->add_send($id, $users[0]->id, 'pending');
        $accepted = $this->add_send($id, $users[1]->id, 'accepted');
        $uncertain = $this->add_send($id, $users[2]->id, 'uncertain');
        $state = $this->add_state($id, $users[0]->id, 4);

        rule_service::save($this->rule_data(['id' => $id, 'days' => 30, 'resetcycle' => 1]), $course->id);

        $rule = $DB->get_record('local_sis_notification_rule', ['id' => $id]);
        $this->assertEquals(30, $rule->days);
        $this->assertEquals(2, $rule->revision);
        $this->assertEquals(0, $rule->activatedat);
        $this->assertEquals(0, $rule->scanuserid);
        $this->assertEquals(0, $rule->enabled);
        $this->assertEquals('suppressed', $DB->get_field('local_sis_notification_send', 'status', ['id' => $pending]));
        $this->assertEquals('accepted', $DB->get_field('local_sis_notification_send', 'status', ['id' => $accepted]));
        $this->assertEquals('uncertain', $DB->get_field('local_sis_notification_send', 'status', ['id' => $uncertain]));
        // States are never deleted by the service.
        $this->assertEquals(4, $DB->get_field('local_sis_notification_state', 'sentcount', ['id' => $state]));
    }

    public function test_checkbox_without_cycle_change_is_a_no_op(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $id = rule_service::save($this->rule_data(), $course->id);
        rule_service::save($this->rule_data(['id' => $id, 'subject' => 'Other', 'resetcycle' => 1]), $course->id);
        $rule = $DB->get_record('local_sis_notification_rule', ['id' => $id]);
        $this->assertEquals(1, $rule->revision);
    }

    public function test_reenabling_after_reset_sets_new_activation_date(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $clock = $this->mock_clock_with_frozen(1700000000);
        $course = $this->create_course();
        $id = rule_service::save($this->rule_data(), $course->id);
        rule_service::set_enabled($id, $course->id, true);
        rule_service::set_enabled($id, $course->id, false);
        rule_service::save($this->rule_data(['id' => $id, 'days' => 9, 'resetcycle' => 1]), $course->id);
        $clock->bump(1000);
        rule_service::set_enabled($id, $course->id, true);
        $this->assertEquals(1700001000, $DB->get_field('local_sis_notification_rule', 'activatedat', ['id' => $id]));
    }
}
