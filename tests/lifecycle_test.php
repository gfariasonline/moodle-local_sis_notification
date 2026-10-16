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
 * Test privacy and course lifecycle cleanup.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification;

use local_sis_notification\local\dispatch_service;
use local_sis_notification\privacy\provider;

/**
 * Exercise course cleanup without affecting other courses.
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class lifecycle_test extends \advanced_testcase {
    /**
     * Create an enabled synthetic rule.
     * @param int $courseid Course ID.
     * @return int Rule ID.
     */
    private function rule(int $courseid): int {
        global $DB;
        return $DB->insert_record('local_sis_notification_rule', (object)[
            'courseid' => $courseid, 'name' => 'Test', 'subject' => 'Hello', 'body' => 'Return',
            'enabled' => 1, 'activatedat' => time() - 20 * DAYSECS, 'createdby' => 2,
        ]);
    }

    /**
     * Course reset pauses rules and removes student state.
     */
    public function test_reset_pauses_rules(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['startdate' => time() - 30 * DAYSECS]);
        $ruleid = $this->rule($course->id);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        dispatch_service::scan();
        $this->assertEquals(1, $DB->count_records('local_sis_notification_state'));
        $event = \core\event\course_reset_ended::create([
            'context' => \context_course::instance($course->id), 'other' => ['reset_options' => []],
        ]);
        \local_sis_notification\observer::course_reset($event);
        $this->assertEquals(0, $DB->get_field('local_sis_notification_rule', 'enabled', ['id' => $ruleid]));
        $this->assertEquals(0, $DB->count_records('local_sis_notification_state'));
    }

    /**
     * Export context discovery includes authors as well as recipients.
     */
    public function test_privacy_author_discovery_and_erasure(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $author = $this->getDataGenerator()->create_user();
        $ruleid = $this->rule($course->id);
        $DB->set_field('local_sis_notification_rule', 'createdby', $author->id, ['id' => $ruleid]);
        $context = \context_course::instance($course->id);
        $this->assertEquals([$context->id], array_values(provider::get_contexts_for_userid($author->id)->get_contextids()));
        $approved = new \core_privacy\local\request\approved_contextlist($author, 'local_sis_notification', [$context->id]);
        provider::delete_data_for_user($approved);
        $this->assertEquals(0, $DB->get_field('local_sis_notification_rule', 'createdby', ['id' => $ruleid]));
        $this->assertTrue($DB->record_exists('local_sis_notification_rule', ['id' => $ruleid]));
    }

    /**
     * Course backup restores settings inactive without student history.
     */
    public function test_backup_restore_inactive_and_activity_mapping(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $activity = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'completion' => 1]);
        $ruleid = $this->rule($course->id);
        $DB->update_record('local_sis_notification_rule', (object)['id' => $ruleid, 'conditiontype' => 'activity_incomplete',
            'cmid' => $activity->cmid]);
        $backup = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            2
        );
        $backup->execute_plan();
        $backupid = $backup->get_backupid();
        $backup->destroy();
        $newcourse = \restore_dbops::create_new_course('Restored', 'restored', $course->category);
        $restore = new \restore_controller(
            $backupid,
            $newcourse,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            2,
            \backup::TARGET_NEW_COURSE
        );
        $restore->execute_precheck();
        $restore->execute_plan();
        $restore->destroy();
        $copy = $DB->get_record('local_sis_notification_rule', ['courseid' => $newcourse]);
        $this->assertNotFalse($copy);
        $this->assertEquals(0, $copy->enabled);
        $this->assertNotEquals($activity->cmid, $copy->cmid);
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $copy->cmid, 'course' => $newcourse]));
        $this->assertEquals(0, $DB->count_records('local_sis_notification_state', ['ruleid' => $copy->id]));
    }

    /**
     * Deleted accounts leave no recipient state after a subsequent scan.
     */
    public function test_deleted_user_cleanup_survives_later_scan(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['startdate' => time() - 30 * DAYSECS]);
        $this->rule($course->id);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        dispatch_service::scan();
        $this->assertTrue($DB->record_exists('local_sis_notification_state', ['userid' => $user->id]));
        delete_user($user);
        dispatch_service::scan();
        $this->assertFalse($DB->record_exists('local_sis_notification_state', ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists('local_sis_notification_send', ['userid' => $user->id]));
    }
}
