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
 * Render a limited set of safe notification variables.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sis_notification\local;

/**
 * No executable templates or arbitrary profile fields.
 */
final class message_renderer {
    /**
     * Render HTML and plain text for Moodle processors.
     * @param \stdClass $rule Rule.
     * @param \stdClass $user Recipient.
     * @param \stdClass $course Course.
     * @return \stdClass Rendered subject, html and plain body.
     */
    public static function render(\stdClass $rule, \stdClass $user, \stdClass $course): \stdClass {
        $context = \context_course::instance($course->id);
        // Plain values: format_string() must not escape here, the HTML body escapes every value once with s().
        $options = ['context' => $context, 'escape' => false];
        $values = [
            '{firstname}' => $user->firstname, '{fullname}' => fullname($user),
            '{coursename}' => format_string($course->fullname, true, $options),
            '{courseurl}' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
            '{days}' => (string)$rule->days,
        ];
        if ($rule->conditiontype === 'activity_incomplete') {
            $cm = get_fast_modinfo($course, $user->id)->get_cm($rule->cmid);
            $values['{activityname}'] = format_string($cm->name, true, $options);
            $values['{activityurl}'] = (new \moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]))->out(false);
        }
        $subject = clean_param(strtr($rule->subject, $values), PARAM_TEXT);
        $htmlvalues = array_map('s', $values);
        $html = format_text(
            strtr($rule->body, $htmlvalues),
            $rule->bodyformat,
            ['context' => $context, 'filter' => false, 'trusted' => false, 'noclean' => false]
        );
        return (object)['subject' => $subject, 'html' => $html, 'plain' => html_to_text($html)];
    }
    /**
     * Body as clean HTML, whatever format it was submitted in.
     *
     * @param \stdClass $data
     * @param \stdClass|null $old
     * @param mixed $body Body value: text or the array of an editor element.
     * @return string
     */
    public static function clean_body(\stdClass $data, ?\stdClass $old, $body): string {
        $format = FORMAT_HTML;
        if (is_array($body)) {
            $format = (int) ($body['format'] ?? FORMAT_HTML);
            $body = (string) ($body['text'] ?? '');
        } else if (property_exists($data, 'bodyformat')) {
            $format = (int) $data->bodyformat;
        } else if ($old && !property_exists($data, 'body')) {
            $format = (int) $old->bodyformat;
        }
        $body = (string) $body;
        if ($format !== FORMAT_HTML) {
            $body = format_text($body, $format, [
                'filter' => false, 'para' => false, 'noclean' => true, 'context' => \core\context\system::instance(),
            ]);
        }
        return clean_text($body, FORMAT_HTML);
    }
}
