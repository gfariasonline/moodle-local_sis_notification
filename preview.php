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
 * Preview of a notification rule. Nothing is sent from this page.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_sis_notification\local\preview_service;
use local_sis_notification\local\rule_service;

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$id = required_param('id', PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/sis_notification:manage', $context);

$returnurl = new moodle_url('/local/sis_notification/index.php', ['courseid' => $course->id]);
$pageurl = new moodle_url('/local/sis_notification/preview.php', ['courseid' => $course->id, 'id' => $id]);
$rule = rule_service::get_rule($id, $course->id);
$name = format_string($rule->name, true, ['context' => $context]);
$title = get_string('previewpage', 'local_sis_notification', $name);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('rules', 'local_sis_notification'), $returnurl);
$PAGE->navbar->add($title);

// The count is only computed on request: it can be slow in large courses.
$summary = null;
if (data_submitted() && optional_param('action', '', PARAM_ALPHA) === 'count') {
    require_sesskey();
    $summary = preview_service::eligibility_summary($course->id);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
echo html_writer::tag('p', get_string('previewintro', 'local_sis_notification'));

try {
    $preview = preview_service::render($rule, $USER, $course);
    echo $OUTPUT->notification(get_string('previewalert', 'local_sis_notification'), \core\output\notification::NOTIFY_INFO);
    echo html_writer::tag('h3', get_string('previewsubject', 'local_sis_notification'), ['class' => 'h5']);
    echo html_writer::tag('p', s($preview->subject));
    echo html_writer::tag('h3', get_string('previewmessage', 'local_sis_notification'), ['class' => 'h5']);
    echo html_writer::div($preview->html, 'border rounded p-3 mb-3');
} catch (moodle_exception $e) {
    echo $OUTPUT->notification($e->getMessage(), \core\output\notification::NOTIFY_ERROR);
}

if (!preview_service::engine_available()) {
    echo $OUTPUT->notification(get_string('countnoengine', 'local_sis_notification'), \core\output\notification::NOTIFY_INFO);
} else {
    if ($summary) {
        $a = (object) [
            'eligible' => $summary->eligible,
            'examined' => $summary->examined,
            'temporary' => $summary->temporary,
            'time' => userdate($summary->computedat),
        ];
        echo $OUTPUT->notification(
            get_string($summary->truncated ? 'counttruncated' : 'countresult', 'local_sis_notification', $a),
            $summary->truncated ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo html_writer::tag('p', get_string('countrecipients_help', 'local_sis_notification'), ['class' => 'text-muted small']);
    echo $OUTPUT->single_button(
        new moodle_url($pageurl, ['action' => 'count']),
        get_string('countrecipients', 'local_sis_notification'),
        'post'
    );
}

echo html_writer::div(
    html_writer::link($returnurl, get_string('backtorules', 'local_sis_notification'), ['class' => 'btn btn-secondary']),
    'mt-3'
);
echo $OUTPUT->footer();
