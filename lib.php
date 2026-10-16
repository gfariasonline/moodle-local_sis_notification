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
 * Library functions.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the notification rules to the course navigation.
 *
 * @param navigation_node $navigation Course node.
 * @param stdClass $course
 * @param context $context Course context.
 */
function local_sis_notification_extend_navigation_course($navigation, $course, $context) {
    if ($course->id == SITEID) {
        return;
    }
    $canmanage = has_capability('local/sis_notification:manage', $context);
    $canviewhistory = has_capability('local/sis_notification:viewhistory', $context);
    if (!$canmanage && !$canviewhistory) {
        return;
    }
    $page = $canmanage ? 'index.php' : 'history.php';
    $navigation->add(
        get_string('navigationlabel', 'local_sis_notification'),
        new moodle_url('/local/sis_notification/' . $page, ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_sis_notification',
        new pix_icon('i/email', '')
    );
}
