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

use local_sis_notification\local\course_access;
use local_sis_notification\local\rule_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/rule_test_helper.php');

/**
 * Tests for the delivery history.
 *
 * @package    local_sis_notification
 * @category   test
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \local_sis_notification\local\course_access
 */
#[\PHPUnit\Framework\Attributes\CoversClass(course_access::class)]
final class rule_history_test extends \advanced_testcase {
    use rule_test_helper;

    public function test_history_respects_separate_groups(): void {
        $this->resetAfterTest();
        [$course, $teacher, $manager, $s1, $s2] = $this->setup_history_course();
        $this->setAdminUser();
        $id = rule_service::save($this->rule_data(), $course->id);
        $this->add_send($id, $s1->id, 'accepted');
        $this->add_send($id, $s2->id, 'failed');
        $context = \context_course::instance($course->id);

        // The non-editing teacher belongs to group 1 and cannot access all groups.
        $this->setUser($teacher);
        $this->assertFalse(has_capability('moodle/site:accessallgroups', $context));
        $this->assertEquals(1, course_access::count_history($course, $context, 0, ''));
        $rows = course_access::get_history($course, $context, 0, '', 0, 50);
        $this->assertCount(1, $rows);
        $this->assertEquals($s1->id, reset($rows)->userid);

        // Users with accessallgroups see everything.
        $this->setUser($manager);
        $this->assertEquals(2, course_access::count_history($course, $context, 0, ''));
    }

    public function test_history_empty_for_separate_groups_user_without_groups(): void {
        $this->resetAfterTest();
        [$course, , , $s1] = $this->setup_history_course();
        $this->setAdminUser();
        $id = rule_service::save($this->rule_data(), $course->id);
        $this->add_send($id, $s1->id, 'accepted');
        $teacher2 = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $this->setUser($teacher2);
        $context = \context_course::instance($course->id);
        $this->assertEquals(0, course_access::count_history($course, $context, 0, ''));
        $this->assertSame([], course_access::get_history($course, $context, 0, '', 0, 50));
    }

    public function test_history_filters_paginates_and_hides_body(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $context = \context_course::instance($course->id);
        $one = rule_service::save($this->rule_data(['name' => 'One']), $course->id);
        $two = rule_service::save($this->rule_data(['name' => 'Two']), $course->id);
        $other = $this->create_course();
        $foreign = rule_service::save($this->rule_data(['name' => 'Foreign']), $other->id);
        $statuses = ['accepted', 'uncertain', 'failed', 'suppressed', 'pending', 'processing'];
        foreach ($statuses as $status) {
            $user = $this->getDataGenerator()->create_user();
            $this->add_send($one, $user->id, $status);
        }
        $this->add_send($two, $this->getDataGenerator()->create_user()->id, 'accepted');
        $this->add_send($foreign, $this->getDataGenerator()->create_user()->id, 'accepted');

        $this->assertEquals(7, course_access::count_history($course, $context, 0, ''));
        $this->assertEquals(6, course_access::count_history($course, $context, $one, ''));
        $this->assertEquals(2, course_access::count_history($course, $context, 0, 'accepted'));
        $this->assertEquals(0, course_access::count_history($course, $context, $foreign, ''));

        $page1 = course_access::get_history($course, $context, 0, '', 0, 5);
        $page2 = course_access::get_history($course, $context, 0, '', 1, 5);
        $this->assertCount(5, $page1);
        $this->assertCount(2, $page2);
        $this->assertSame([], array_intersect(array_keys($page1), array_keys($page2)));

        $row = reset($page1);
        $this->assertFalse(property_exists($row, 'body'));
        $this->assertFalse(property_exists($row, 'subject'));
        $this->assertTrue(property_exists($row, 'rulename'));
        $this->assertTrue(property_exists($row, 'firstname'));
        $this->assertTrue(property_exists($row, 'status'));
    }

    public function test_history_ignores_unknown_status_filter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $context = \context_course::instance($course->id);
        $id = rule_service::save($this->rule_data(), $course->id);
        $this->add_send($id, $this->getDataGenerator()->create_user()->id, 'accepted');
        $this->assertEquals(1, course_access::count_history($course, $context, 0, "' OR 1=1 --"));
    }
}
