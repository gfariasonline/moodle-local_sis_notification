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
 * Regression tests for editing active notification rules.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification;

use local_sis_notification\form\rule_form;
use local_sis_notification\local\rule_service;

/**
 * Exercise frozen form values before native Moodle validation.
 */
final class rule_form_test extends \advanced_testcase {
    /**
     * Editing the text of an active three-day rule preserves its actual cycle fields.
     */
    public function test_edit_active_rule_preserves_three_day_delay(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $data = (object)['name' => 'Three days', 'conditiontype' => 'inactivity', 'days' => 3,
            'cmid' => 0, 'subject' => 'Hello', 'body' => '<p>Return</p>', 'repeatdays' => 0, 'maxsends' => 1];
        $id = rule_service::save($data, $course->id);
        rule_service::set_enabled($id, $course->id, true);
        $rule = rule_service::get_rule($id, $course->id);
        rule_form::mock_submit(['id' => $id, 'courseid' => $course->id, 'name' => 'Three days',
            'subject' => 'Updated subject', 'body' => ['text' => '<p>Updated body</p>', 'format' => FORMAT_HTML],
            'repeatdays' => 0, 'maxsends' => 1]);
        $form = new rule_form(null, ['course' => $course, 'rule' => $rule]);
        $submitted = $form->get_data();
        $this->assertNotNull($submitted);
        $this->assertEquals(3, $submitted->days);
        $this->assertEquals('Updated subject', $submitted->subject);
    }
}
