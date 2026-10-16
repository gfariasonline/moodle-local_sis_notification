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
 * Delivery history of a course.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_sis_notification\local\course_access;
use local_sis_notification\local\rule_service;

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$ruleid = optional_param('ruleid', 0, PARAM_INT);
$status = optional_param('status', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/sis_notification:viewhistory', $context);

$pageurl = new moodle_url('/local/sis_notification/history.php', [
    'courseid' => $course->id, 'ruleid' => $ruleid, 'status' => $status,
]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('history', 'local_sis_notification'));
$PAGE->set_heading($course->fullname);
if (has_capability('local/sis_notification:manage', $context)) {
    $PAGE->navbar->add(
        get_string('rules', 'local_sis_notification'),
        new moodle_url('/local/sis_notification/index.php', ['courseid' => $course->id])
    );
}
$PAGE->navbar->add(get_string('history', 'local_sis_notification'));

$rulenames = [];
foreach (rule_service::get_rules($course->id) as $rule) {
    $rulenames[$rule->id] = format_string($rule->name, true, ['context' => $context]);
}
$statusnames = [];
foreach (rule_service::get_statuses() as $name) {
    $statusnames[$name] = get_string('status_' . $name, 'local_sis_notification');
}

$total = course_access::count_history($course, $context, $ruleid, $status);
$rows = course_access::get_history($course, $context, $ruleid, $status, $page, $perpage);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('history', 'local_sis_notification'));
echo html_writer::tag('p', get_string('historyintro', 'local_sis_notification'));

// Filters are a plain GET form, so they work without JavaScript and on small screens.
$filter = html_writer::div(
    html_writer::label(get_string('filterrule', 'local_sis_notification'), 'id_ruleid', true, ['class' => 'me-2 mr-2']) .
    html_writer::select(
        $rulenames,
        'ruleid',
        $ruleid,
        [0 => get_string('allrules', 'local_sis_notification')],
        ['id' => 'id_ruleid', 'class' => 'form-select custom-select w-auto']
    ),
    'd-flex align-items-center me-3 mr-3 mb-2'
);
$filter .= html_writer::div(
    html_writer::label(get_string('filterstatus', 'local_sis_notification'), 'id_status', true, ['class' => 'me-2 mr-2']) .
    html_writer::select(
        $statusnames,
        'status',
        $status,
        ['' => get_string('allstatuses', 'local_sis_notification')],
        ['id' => 'id_status', 'class' => 'form-select custom-select w-auto']
    ),
    'd-flex align-items-center me-3 mr-3 mb-2'
);
$filter .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $course->id]);
$filter .= html_writer::tag('button', get_string('filter', 'local_sis_notification'), [
    'type' => 'submit', 'class' => 'btn btn-secondary mb-2',
]);
echo html_writer::tag('form', $filter, [
    'method' => 'get',
    'action' => (new moodle_url('/local/sis_notification/history.php'))->out_omit_querystring(),
    'class' => 'd-flex flex-wrap align-items-center mb-3',
]);

echo html_writer::tag('p', s(get_string('statusnote', 'local_sis_notification')), ['class' => 'text-muted small']);

if (!$rows) {
    echo $OUTPUT->notification(get_string('historyempty', 'local_sis_notification'), \core\output\notification::NOTIFY_INFO);
} else {
    $badges = [
        rule_service::STATUS_ACCEPTED => 'bg-success text-white',
        rule_service::STATUS_UNCERTAIN => 'bg-warning text-dark',
        rule_service::STATUS_FAILED => 'bg-danger text-white',
        rule_service::STATUS_SUPPRESSED => 'bg-secondary text-white',
        rule_service::STATUS_PENDING => 'bg-info text-dark',
        rule_service::STATUS_PROCESSING => 'bg-info text-dark',
    ];
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table table-striped';
    $table->head = [
        get_string('student', 'local_sis_notification'),
        get_string('rulename', 'local_sis_notification'),
        get_string('sequence', 'local_sis_notification'),
        get_string('rulestatus', 'local_sis_notification'),
        get_string('reason', 'local_sis_notification'),
        get_string('scheduledfor', 'local_sis_notification'),
        get_string('lastchange', 'local_sis_notification'),
    ];
    foreach ($rows as $row) {
        $reason = '';
        if ($row->reason !== '') {
            $key = 'reason_' . $row->reason;
            $reason = get_string_manager()->string_exists($key, 'local_sis_notification')
                ? get_string($key, 'local_sis_notification')
                : s($row->reason);
        }
        $statuslabel = $statusnames[$row->status] ?? s($row->status);
        $table->data[] = [
            html_writer::link(
                new moodle_url('/user/view.php', ['id' => $row->userid, 'course' => $course->id]),
                fullname($row)
            ),
            format_string($row->rulename, true, ['context' => $context]),
            get_string('sequencevalue', 'local_sis_notification', $row),
            html_writer::span($statuslabel, 'badge ' . ($badges[$row->status] ?? 'bg-secondary text-white')),
            $reason,
            $row->dueat ? userdate($row->dueat) : '',
            userdate($row->timemodified),
        ];
    }
    echo html_writer::div(html_writer::table($table), 'table-responsive');
    echo $OUTPUT->paging_bar($total, $page, $perpage, $pageurl);
}

echo $OUTPUT->footer();
