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

use local_sis_notification\local\preview_service;
use local_sis_notification\local\rule_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/rule_test_helper.php');

/**
 * Tests for the preview of a rule.
 *
 * @package    local_sis_notification
 * @category   test
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(preview_service::class)]
final class rule_preview_test extends \advanced_testcase {
    use rule_test_helper;

    public function test_render_preview_uses_viewer_data_without_sending(): void {
        $this->resetAfterTest();
        $sink = $this->redirectMessages();
        $course = $this->create_course();
        $manager = $this->getDataGenerator()->create_user(['firstname' => 'Marta', 'lastname' => 'Souza']);
        $this->setUser($manager);
        $rule = $this->rule_data(['days' => 12]);
        $rule->bodyformat = FORMAT_HTML;

        $preview = preview_service::render($rule, $manager, $course);

        $this->assertSame('Hello Marta', $preview->subject);
        $this->assertStringContainsString('Marta Souza', $preview->html);
        $this->assertStringContainsString($course->fullname, $preview->html);
        $this->assertStringContainsString('12 days', strip_tags($preview->html));
        $this->assertStringNotContainsString('{', $preview->html);
        $this->assertSame(0, $sink->count());
    }

    public function test_eligibility_summary_counts_only_eligible_users(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $generator = $this->getDataGenerator();
        $generator->create_and_enrol($course, 'student');
        $generator->create_and_enrol($course, 'student');
        $generator->create_and_enrol($course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $generator->create_and_enrol($course, 'student', ['suspended' => 1]);

        $this->assertTrue(preview_service::engine_available());
        $summary = preview_service::eligibility_summary($course->id);

        $this->assertEquals(2, $summary->eligible);
        $this->assertEquals(2, $summary->temporary);
        $this->assertEquals(4, $summary->examined);
        $this->assertFalse($summary->truncated);
        $this->assertGreaterThan(0, $summary->computedat);
        $this->assertEquals(0, $DB->count_records('local_sis_notification_send'));
    }

    public function test_eligibility_summary_is_bounded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_course();
        $generator = $this->getDataGenerator();
        for ($i = 0; $i < 7; $i++) {
            $generator->create_and_enrol($course, 'student');
        }
        $limit = preview_service::$limit;
        $pagesize = preview_service::$pagesize;
        preview_service::$pagesize = 2;
        try {
            // Paging walks through every candidate when the limit is not reached.
            $summary = preview_service::eligibility_summary($course->id);
            $this->assertFalse($summary->truncated);
            $this->assertEquals(7, $summary->examined);
            $this->assertEquals(7, $summary->eligible);

            preview_service::$limit = 5;
            $summary = preview_service::eligibility_summary($course->id);
            $this->assertTrue($summary->truncated);
            $this->assertEquals(5, $summary->examined);
        } finally {
            preview_service::$limit = $limit;
            preview_service::$pagesize = $pagesize;
        }
    }

    public function test_eligibility_summary_respects_separate_groups(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->setup_history_course();
        $this->setUser($teacher);
        $summary = preview_service::eligibility_summary($course->id);
        // Only the users that share a group with the teacher are examined: the teacher and one student.
        $this->assertEquals(2, $summary->examined);
        $this->assertEquals(1, $summary->eligible);
        $this->assertEquals(0, $summary->temporary);
    }
}
