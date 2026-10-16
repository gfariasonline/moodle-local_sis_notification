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
use local_sis_notification\local\rule_validator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/rule_test_helper.php');

#[\PHPUnit\Framework\Attributes\CoversClass(rule_validator::class)]
/**
 * Tests for the validation of rule data.
 *
 * @package    local_sis_notification
 * @category   test
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_sis_notification\local\rule_validator
 */
final class rule_validation_test extends \advanced_testcase {
    use rule_test_helper;

    public function test_validate_valid_data_has_no_errors(): void {
        $this->resetAfterTest();
        $course = $this->create_course();
        $this->assertSame([], rule_service::validate($this->rule_data(), $course->id));
    }

    /**
     * Provide test cases.
     *
     * @return array
     */
    public static function invalid_field_provider(): array {
        return [
            'empty name' => ['name', ''],
            'long name' => ['name', str_repeat('a', 256)],
            'bad condition' => ['conditiontype', 'unknown'],
            'days zero' => ['days', 0],
            'days too big' => ['days', 3651],
            'days not integer' => ['days', '1.5'],
            'days text' => ['days', 'abc'],
            'empty subject' => ['subject', '   '],
            'long subject' => ['subject', str_repeat('a', 256)],
            'subject with newline' => ['subject', "Hello\nBcc: x@example.com"],
            'empty body' => ['body', '<p>&nbsp;</p>'],
            'negative repeat' => ['repeatdays', -1],
            'repeat too big' => ['repeatdays', 3651],
            'maxsends zero' => ['maxsends', 0],
            'maxsends too big' => ['maxsends', 11],
            'unknown variable subject' => ['subject', 'Hi {username}'],
            'unknown variable body' => ['body', '<p>Hi {nope}</p>'],
            'empty variable' => ['body', '<p>Hi {}</p>'],
            'activity variable on inactivity' => ['body', '<p>{activityname}</p>'],
            'activity url on inactivity' => ['subject', '{activityurl}'],
        ];
    }

    /**
     * Invalid values are rejected.
     *
     * @dataProvider invalid_field_provider
     * @param string $field Field name.
     * @param mixed $value Invalid value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_field_provider')]
    public function test_validate_rejects_field(string $field, $value): void {
        $this->resetAfterTest();
        $course = $this->create_course();
        $errors = rule_service::validate($this->rule_data([$field => $value]), $course->id);
        $this->assertArrayHasKey($field, $errors);
        $this->assertCount(1, $errors);
    }

    /**
     * Provide test cases.
     *
     * @return array
     */
    public static function valid_boundary_provider(): array {
        return [
            'days min' => ['days', 1],
            'days max' => ['days', 3650],
            'days numeric string' => ['days', '30'],
            'repeat min' => ['repeatdays', 1],
            'repeat max' => ['repeatdays', 3650],
            'maxsends min' => ['maxsends', 1],
            'maxsends max' => ['maxsends', 10],
            'subject 255' => ['subject', str_repeat('a', 255)],
            'name 255' => ['name', str_repeat('a', 255)],
            'multibyte subject 255' => ['subject', str_repeat('é', 255)],
        ];
    }

    /**
     * Boundary values are accepted.
     *
     * @dataProvider valid_boundary_provider
     * @param string $field Field name.
     * @param mixed $value Boundary value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('valid_boundary_provider')]
    public function test_validate_accepts_boundaries(string $field, $value): void {
        $this->resetAfterTest();
        $course = $this->create_course();
        $this->assertSame([], rule_service::validate($this->rule_data([$field => $value]), $course->id));
    }

    public function test_activity_variables_allowed_for_activity_condition(): void {
        $this->resetAfterTest();
        $course = $this->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $data = $this->rule_data([
            'conditiontype' => 'activity_incomplete',
            'cmid' => $page->cmid,
            'subject' => 'Pending: {activityname}',
            'body' => '<p>Open {activityurl} in {coursename}</p>',
        ]);
        $this->assertSame([], rule_service::validate($data, $course->id));
    }

    public function test_activity_condition_requires_activity_with_completion_in_same_course(): void {
        $this->resetAfterTest();
        $course = $this->create_course();
        $other = $this->create_course();
        $nocompletion = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $foreign = $this->getDataGenerator()->create_module('page', [
            'course' => $other->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $base = ['conditiontype' => 'activity_incomplete', 'subject' => 'Hi', 'body' => '<p>Hi</p>'];

        $errors = rule_service::validate($this->rule_data($base + ['cmid' => 0]), $course->id);
        $this->assertArrayHasKey('cmid', $errors);

        $errors = rule_service::validate($this->rule_data(['cmid' => $nocompletion->cmid] + $base), $course->id);
        $this->assertArrayHasKey('cmid', $errors);

        $errors = rule_service::validate($this->rule_data(['cmid' => $foreign->cmid] + $base), $course->id);
        $this->assertArrayHasKey('cmid', $errors);

        $errors = rule_service::validate($this->rule_data(['cmid' => 987654] + $base), $course->id);
        $this->assertArrayHasKey('cmid', $errors);
    }

    public function test_activity_in_course_without_completion_tracking_is_rejected(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 0]);
        global $DB;
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        // The generator refuses completion in a course without tracking, so set it directly.
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_MANUAL, ['id' => $page->cmid]);
        rebuild_course_cache($course->id, true);
        $errors = rule_service::validate($this->rule_data([
            'conditiontype' => 'activity_incomplete', 'cmid' => $page->cmid,
        ]), $course->id);
        $this->assertArrayHasKey('cmid', $errors);
    }

    public function test_course_incomplete_requires_completion_enabled_with_criteria(): void {
        global $DB;
        $this->resetAfterTest();
        $disabled = $this->getDataGenerator()->create_course(['enablecompletion' => 0]);
        $nocriteria = $this->create_course();
        $withcriteria = $this->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $withcriteria->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $DB->insert_record('course_completion_criteria', (object) [
            'course' => $withcriteria->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module' => 'page',
            'moduleinstance' => $page->cmid,
        ]);

        $data = $this->rule_data(['conditiontype' => 'course_incomplete']);
        $this->assertArrayHasKey('conditiontype', rule_service::validate($data, $disabled->id));
        $this->assertArrayHasKey('conditiontype', rule_service::validate($data, $nocriteria->id));
        $this->assertSame([], rule_service::validate($data, $withcriteria->id));
    }
}
