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

/**
 * Data and fixtures shared by the rule service tests.
 *
 * @package    local_sis_notification
 * @category   test
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait rule_test_helper {
    /**
     * Course with completion tracking enabled.
     *
     * @return \stdClass
     */
    private function create_course(): \stdClass {
        return $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
    }

    /**
     * Build valid rule data.
     *
     * @param array $overrides
     * @return \stdClass
     */
    private function rule_data(array $overrides = []): \stdClass {
        return (object) array_merge([
            'name' => 'Weekly nudge',
            'conditiontype' => 'inactivity',
            'days' => 7,
            'cmid' => 0,
            'subject' => 'Hello {firstname}',
            'body' => '<p>Hi {fullname}, you have not visited {coursename} ({courseurl}) for {days} days.</p>',
            'repeatdays' => 0,
            'maxsends' => 1,
        ], $overrides);
    }

    /**
     * Insert a send row for a rule.
     *
     * @param int $ruleid
     * @param int $userid
     * @param string $status
     * @param int $seq
     * @return int
     */
    private function add_send(int $ruleid, int $userid, string $status, int $seq = 1): int {
        global $DB;
        return $DB->insert_record('local_sis_notification_send', (object) [
            'ruleid' => $ruleid, 'userid' => $userid, 'cycle' => 1, 'seq' => $seq, 'ruleversion' => 1,
            'dueat' => 100, 'status' => $status, 'leaseuntil' => 0, 'attempts' => 0, 'reason' => '',
            'messageid' => 0, 'timecreated' => 100, 'timemodified' => 100,
        ]);
    }

    /**
     * Insert a state row for a rule.
     *
     * @param int $ruleid
     * @param int $userid
     * @param int $sentcount
     * @return int
     */
    private function add_state(int $ruleid, int $userid, int $sentcount = 1): int {
        global $DB;
        return $DB->insert_record('local_sis_notification_state', (object) [
            'ruleid' => $ruleid, 'userid' => $userid, 'cycle' => 1, 'baseat' => 100, 'enrolat' => 100,
            'observedat' => 100, 'lastaccess' => 100, 'sentcount' => $sentcount, 'nextdue' => 0,
            'ruleversion' => 1, 'status' => 'active', 'timecreated' => 100, 'timemodified' => 100,
        ]);
    }

    /**
     * Create a course with a separate groups mode and two groups with one student each.
     *
     * @return array
     */
    private function setup_history_course(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1, 'groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $manager = $generator->create_and_enrol($course, 'manager');
        $s1 = $generator->create_and_enrol($course, 'student', ['firstname' => 'Alice']);
        $s2 = $generator->create_and_enrol($course, 'student', ['firstname' => 'Bruno']);
        $g1 = $generator->create_group(['courseid' => $course->id]);
        $g2 = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $g1->id, 'userid' => $teacher->id]);
        $generator->create_group_member(['groupid' => $g1->id, 'userid' => $s1->id]);
        $generator->create_group_member(['groupid' => $g2->id, 'userid' => $s2->id]);
        return [$course, $teacher, $manager, $s1, $s2];
    }
}
