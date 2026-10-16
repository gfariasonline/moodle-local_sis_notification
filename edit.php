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
 * Create or edit a notification rule.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_sis_notification\form\rule_form;
use local_sis_notification\local\rule_service;

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/sis_notification:manage', $context);

$returnurl = new moodle_url('/local/sis_notification/index.php', ['courseid' => $course->id]);
$pageurl = new moodle_url('/local/sis_notification/edit.php', ['courseid' => $course->id, 'id' => $id]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('rules', 'local_sis_notification'), $returnurl);

$rule = $id ? rule_service::get_rule($id, $course->id) : null;
$title = get_string($rule ? 'editrulepage' : 'addrulepage', 'local_sis_notification');
$PAGE->set_title($title);
$PAGE->navbar->add($title);

$form = new rule_form($pageurl, ['course' => $course, 'rule' => $rule]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}

if ($data = $form->get_data()) {
    if ($rule && $rule->enabled) {
        // These fields are frozen while the rule is enabled; the stored values are the only valid ones.
        $data->conditiontype = $rule->conditiontype;
        $data->days = $rule->days;
        $data->cmid = $rule->cmid;
    }
    $data->id = $rule->id ?? 0;
    try {
        $savedid = rule_service::save($data, $course->id);
        $name = format_string($data->name, true, ['context' => $context]);
        $enable = !$rule && !empty($data->enableonsave);
        if ($enable) {
            rule_service::set_enabled($savedid, $course->id, true);
        }
        $messagekey = $rule ? 'ruleupdated' : ($enable ? 'rulecreatedenabled' : 'rulecreated');
        redirect(
            $returnurl,
            get_string($messagekey, 'local_sis_notification', $name),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($rule && !$form->is_submitted()) {
    $form->set_rule($rule);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
$form->display();
echo $OUTPUT->footer();
