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

#[\PHPUnit\Framework\Attributes\CoversClass(rule_service::class)]
/**
 * Tests for creating and updating rules with the rule service.
 *
 * @package    local_sis_notification
 * @category   test
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_sis_notification\local\rule_service
 */
final class rule_service_test extends \advanced_testcase {
    use rule_test_helper;

    public function test_save_creates_inactive_rule(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $id = rule_service::save($this->rule_data(['enabled' => 1, 'activatedat' => 99, 'revision' => 9]), $course->id);

        $rule = $DB->get_record('local_sis_notification_rule', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals($course->id, $rule->courseid);
        $this->assertEquals(0, $rule->enabled);
        $this->assertEquals(0, $rule->activatedat);
        $this->assertEquals(1, $rule->revision);
        $this->assertEquals(0, $rule->scanuserid);
        $this->assertEquals($user->id, $rule->createdby);
        $this->assertEquals(FORMAT_HTML, $rule->bodyformat);
        $this->assertGreaterThan(0, $rule->timecreated);
        $this->assertEquals($rule->timecreated, $rule->timemodified);
        $this->assertEquals('inactivity', $rule->conditiontype);
        $this->assertEquals(0, $rule->cmid);
    }

    public function test_save_accepts_editor_array_and_cleans_body(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();

        $data = $this->rule_data([
            'body' => ['text' => '<p>Hello {firstname}</p><script>alert(1)</script>', 'format' => FORMAT_HTML],
        ]);
        $id = rule_service::save($data, $course->id);

        $rule = $DB->get_record('local_sis_notification_rule', ['id' => $id], '*', MUST_EXIST);
        $this->assertStringContainsString('Hello {firstname}', $rule->body);
        $this->assertStringNotContainsString('<script', $rule->body);
    }

    public function test_save_invalid_data_throws_and_stores_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();

        try {
            rule_service::save($this->rule_data(['days' => 0]), $course->id);
            $this->fail('Expected an exception');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('days', $e->debuginfo);
        }
        $this->assertEquals(0, $DB->count_records('local_sis_notification_rule'));
    }

    public function test_save_ignores_protected_fields_on_update(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $id = rule_service::save($this->rule_data(), $course->id);

        $update = $this->rule_data(['id' => $id, 'name' => 'Renamed']);
        $update->enabled = 1;
        $update->revision = 77;
        $update->activatedat = 5;
        $update->scanuserid = 12;
        $update->courseid = 999;
        $update->createdby = 4242;
        rule_service::save($update, $course->id);

        $rule = $DB->get_record('local_sis_notification_rule', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals('Renamed', $rule->name);
        $this->assertEquals(0, $rule->enabled);
        $this->assertEquals(1, $rule->revision);
        $this->assertEquals(0, $rule->activatedat);
        $this->assertEquals(0, $rule->scanuserid);
        $this->assertEquals($course->id, $rule->courseid);
        $this->assertNotEquals(4242, $rule->createdby);
    }

    public function test_save_rejects_rule_from_other_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $other = $this->create_course();
        $id = rule_service::save($this->rule_data(), $course->id);

        $this->expectException(\dml_missing_record_exception::class);
        rule_service::save($this->rule_data(['id' => $id]), $other->id);
    }

    public function test_validate_repeat_zero_forces_single_send_on_save(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $id = rule_service::save($this->rule_data(['repeatdays' => 0, 'maxsends' => 5]), $course->id);
        $this->assertEquals(1, $DB->get_field('local_sis_notification_rule', 'maxsends', ['id' => $id]));

        $id2 = rule_service::save($this->rule_data(['repeatdays' => 3, 'maxsends' => 5]), $course->id);
        $rule = $DB->get_record('local_sis_notification_rule', ['id' => $id2]);
        $this->assertEquals(3, $rule->repeatdays);
        $this->assertEquals(5, $rule->maxsends);
    }

    public function test_non_activity_condition_stores_zero_cmid(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $id = rule_service::save($this->rule_data(['cmid' => $page->cmid]), $course->id);
        $this->assertEquals(0, $DB->get_field('local_sis_notification_rule', 'cmid', ['id' => $id]));
    }

    public function test_get_rules_lists_only_course_rules(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $other = $this->create_course();
        $a = rule_service::save($this->rule_data(['name' => 'A']), $course->id);
        $b = rule_service::save($this->rule_data(['name' => 'B']), $course->id);
        rule_service::save($this->rule_data(['name' => 'C']), $other->id);

        $rules = rule_service::get_rules($course->id);
        $this->assertEquals([$a, $b], array_map('intval', array_keys($rules)));
    }

    public function test_get_allowed_variables(): void {
        $inactivity = rule_service::get_allowed_variables('inactivity');
        $this->assertEqualsCanonicalizing(['firstname', 'fullname', 'coursename', 'courseurl', 'days'], $inactivity);
        $activity = rule_service::get_allowed_variables('activity_incomplete');
        $this->assertContains('activityname', $activity);
        $this->assertContains('activityurl', $activity);
    }
}
