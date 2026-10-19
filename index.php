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
 * Notification rules of a course.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_sis_notification\local\rule_service;

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
$canmanage = has_capability('local/sis_notification:manage', $context);
$canviewhistory = has_capability('local/sis_notification:viewhistory', $context);
if (!$canmanage && !$canviewhistory) {
    require_capability('local/sis_notification:manage', $context);
}

$pageurl = new moodle_url('/local/sis_notification/index.php', ['courseid' => $course->id]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('rules', 'local_sis_notification'));
$PAGE->set_heading($course->fullname);

// Changes are only accepted as POST requests with a valid session key.
if (data_submitted() && in_array($action, ['enable', 'pause', 'delete'], true)) {
    require_sesskey();
    require_capability('local/sis_notification:manage', $context);
    try {
        $rule = rule_service::get_rule($id, $course->id);
        $name = format_string($rule->name, true, ['context' => $context]);
        if ($action === 'delete') {
            rule_service::delete($rule->id, $course->id);
            $message = get_string('ruledeleted', 'local_sis_notification', $name);
        } else if ($action === 'enable') {
            rule_service::set_enabled($rule->id, $course->id, true);
            $message = get_string('ruleenabledmessage', 'local_sis_notification', $name);
        } else {
            rule_service::set_enabled($rule->id, $course->id, false);
            $message = get_string('rulepausedmessage', 'local_sis_notification', $name);
        }
        redirect($pageurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (dml_missing_record_exception $e) {
        redirect($pageurl, get_string('rulenotfound', 'local_sis_notification'), null, \core\output\notification::NOTIFY_ERROR);
    } catch (invalid_parameter_exception $e) {
        $message = get_string('ruleenablefailed', 'local_sis_notification', (object) [
            'name' => $name, 'errors' => s($e->debuginfo),
        ]);
        redirect($pageurl, $message, null, \core\output\notification::NOTIFY_ERROR);
    } catch (moodle_exception $e) {
        redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

$rules = rule_service::get_rules($course->id);

// Delete asks for confirmation; the deletion itself is a POST request.
if ($action === 'confirmdelete' && $canmanage && isset($rules[$id])) {
    $name = format_string($rules[$id]->name, true, ['context' => $context]);
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmdelete', 'local_sis_notification', $name),
        new single_button(
            new moodle_url($pageurl, ['action' => 'delete', 'id' => $id]),
            get_string('deleterule', 'local_sis_notification'),
            'post',
            single_button::BUTTON_DANGER
        ),
        $pageurl
    );
    echo $OUTPUT->footer();
    exit;
}

/**
 * Button that submits a change as a POST request.
 *
 * @param moodle_url $pageurl
 * @param string $action
 * @param stdClass $rule
 * @param string $label
 * @param string $name Rule name, read out by screen readers.
 * @return string
 */
function local_sis_notification_action_form(
    moodle_url $pageurl,
    string $action,
    stdClass $rule,
    string $label,
    string $name
): string {
    $hidden = '';
    foreach (['sesskey' => sesskey(), 'courseid' => $rule->courseid, 'id' => $rule->id, 'action' => $action] as $key => $value) {
        $hidden .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
    $button = html_writer::tag('button', $label . html_writer::span(': ' . $name, 'visually-hidden sr-only'), [
        'type' => 'submit', 'class' => 'btn btn-secondary btn-sm',
    ]);
    return html_writer::tag('form', $hidden . $button, [
        'method' => 'post', 'action' => $pageurl->out_omit_querystring(), 'class' => 'd-inline',
    ]);
}

$cms = get_fast_modinfo($course)->get_cms();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('rules', 'local_sis_notification'));
echo html_writer::tag('p', get_string('rulesintro', 'local_sis_notification'));

$toolbar = '';
if ($canmanage) {
    $toolbar .= html_writer::link(
        new moodle_url('/local/sis_notification/edit.php', ['courseid' => $course->id]),
        get_string('addrule', 'local_sis_notification'),
        ['class' => 'btn btn-primary me-2 mr-2']
    );
}
if ($canviewhistory) {
    $toolbar .= html_writer::link(
        new moodle_url('/local/sis_notification/history.php', ['courseid' => $course->id]),
        get_string('viewhistory', 'local_sis_notification'),
        ['class' => 'btn btn-secondary']
    );
}
echo html_writer::div($toolbar, 'mb-3');

if (!$rules) {
    echo $OUTPUT->notification(get_string('norules', 'local_sis_notification'), \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table table-striped';
    $table->head = [
        get_string('rulename', 'local_sis_notification'),
        get_string('rulesummary', 'local_sis_notification'),
        get_string('rulestatus', 'local_sis_notification'),
    ];
    if ($canmanage) {
        $table->head[] = get_string('ruleactions', 'local_sis_notification');
    }
    foreach ($rules as $rule) {
        $name = format_string($rule->name, true, ['context' => $context]);
        $summary = (object) [
            'days' => $rule->days,
            'activity' => get_string('rulesummary_activity_missing', 'local_sis_notification'),
        ];
        if (isset($cms[$rule->cmid])) {
            $summary->activity = format_string($cms[$rule->cmid]->name, true, ['context' => $context]);
        }
        $condition = html_writer::div(get_string('rulesummary_' . $rule->conditiontype, 'local_sis_notification', $summary));
        $condition .= html_writer::div(
            $rule->repeatdays > 0
                ? get_string('repeatsummary', 'local_sis_notification', $rule)
                : get_string('norepeatsummary', 'local_sis_notification'),
            'text-muted small'
        );

        if ($rule->enabled) {
            $status = html_writer::span(get_string('ruleenabled', 'local_sis_notification'), 'badge bg-success text-white');
            if ($rule->activatedat) {
                $status .= html_writer::div(
                    get_string('ruleactivatedat', 'local_sis_notification', userdate($rule->activatedat)),
                    'text-muted small'
                );
            }
        } else {
            $status = html_writer::span(get_string('rulepaused', 'local_sis_notification'), 'badge bg-secondary text-white');
        }

        $row = [$name, $condition, $status];
        if ($canmanage) {
            $links = [];
            $links[] = local_sis_notification_action_form(
                $pageurl,
                $rule->enabled ? 'pause' : 'enable',
                $rule,
                get_string($rule->enabled ? 'pauserule' : 'enablerule', 'local_sis_notification'),
                $name
            );
            $rulepages = [
                'editrule' => '/local/sis_notification/edit.php',
                'previewrule' => '/local/sis_notification/preview.php',
            ];
            foreach ($rulepages as $string => $path) {
                $links[] = html_writer::link(
                    new moodle_url($path, ['courseid' => $course->id, 'id' => $rule->id]),
                    get_string($string, 'local_sis_notification') . html_writer::span(': ' . $name, 'visually-hidden sr-only'),
                    ['class' => 'btn btn-link btn-sm']
                );
            }
            $links[] = html_writer::link(
                new moodle_url($pageurl, ['action' => 'confirmdelete', 'id' => $rule->id]),
                get_string('deleterule', 'local_sis_notification') . html_writer::span(': ' . $name, 'visually-hidden sr-only'),
                ['class' => 'btn btn-link btn-sm text-danger']
            );
            $row[] = html_writer::div(implode(' ', $links), 'd-flex flex-wrap align-items-center');
        }
        $table->data[] = $row;
    }
    echo html_writer::div(html_writer::table($table), 'table-responsive');
}

echo $OUTPUT->footer();
