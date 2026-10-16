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

namespace local_sis_notification\local;

use stdClass;

/**
 * Validates and normalises the data of a notification rule.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_validator {
    /**
     * Validate rule data.
     *
     * When the data has an id the stored rule is used as the reference to detect changes that restart the cycle.
     *
     * @param stdClass $data Rule data, as submitted by the form or as stored.
     * @param int $courseid
     * @return string[] Error messages indexed by field. Empty when the data is valid.
     */
    public static function validate(stdClass $data, int $courseid): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $course = get_course($courseid);
        $old = empty($data->id) ? null : rule_service::get_rule((int) $data->id, $courseid);
        $new = self::normalise($data, $old);

        $errors = array_filter([
            'name' => self::text_error((string) $new->name, false),
            'conditiontype' => self::condition_error($course, $new),
            'cmid' => self::activity_error($course, $new),
            'days' => self::range_error($new->days, rule_service::DAYS_MIN, rule_service::DAYS_MAX),
            'repeatdays' => self::repeat_error($new->repeatdays),
            'maxsends' => self::range_error($new->maxsends, 1, rule_service::MAXSENDS_MAX),
            'subject' => self::text_error((string) $new->subject, true),
            'body' => self::body_error((string) $new->body),
        ], static fn(?string $message): bool => $message !== null);

        $errors += self::variable_errors($new, $errors);
        return $old ? array_merge($errors, self::cycle_errors($old, $new, $data)) : $errors;
    }

    /**
     * Merge submitted data with the stored rule (if any) and defaults, keeping only the editable fields.
     *
     * @param stdClass $data
     * @param stdClass|null $old
     * @return stdClass
     */
    public static function normalise(stdClass $data, ?stdClass $old): stdClass {
        $result = self::merge_fields($data, $old);
        $result->body = message_renderer::clean_body($data, $old, $result->body);
        if ($result->conditiontype !== rule_service::CONDITION_ACTIVITY_INCOMPLETE) {
            $result->cmid = 0;
        }
        return $result;
    }

    /**
     * Whether a change restarts the cycle of a rule.
     *
     * @param stdClass $old Stored rule.
     * @param stdClass $new Normalised data.
     * @return bool
     */
    public static function cycle_changed(stdClass $old, stdClass $new): bool {
        return (bool) self::changed_cycle_fields($old, $new);
    }

    /**
     * Fields that restart the cycle and differ from the stored rule.
     *
     * @param stdClass $old Stored rule.
     * @param stdClass $new Normalised data.
     * @return string[]
     */
    public static function changed_cycle_fields(stdClass $old, stdClass $new): array {
        $current = [
            'conditiontype' => (string) $new->conditiontype,
            'days' => self::to_int($new->days),
            'cmid' => self::to_int($new->cmid) ?? 0,
        ];
        $stored = [
            'conditiontype' => (string) $old->conditiontype,
            'days' => (int) $old->days,
            'cmid' => (int) $old->cmid,
        ];
        return array_keys(array_diff_assoc($current, $stored));
    }

    /**
     * Submitted values, then stored values, then defaults, for the editable fields.
     *
     * @param stdClass $data
     * @param stdClass|null $old
     * @return stdClass
     */
    private static function merge_fields(stdClass $data, ?stdClass $old): stdClass {
        $defaults = [
            'name' => '', 'conditiontype' => rule_service::CONDITION_INACTIVITY, 'days' => 7, 'cmid' => 0,
            'subject' => '', 'body' => '', 'repeatdays' => 0, 'maxsends' => 1,
        ];
        $result = new stdClass();
        foreach ($defaults as $field => $default) {
            $result->$field = property_exists($data, $field) ? $data->$field : ($old->$field ?? $default);
        }
        return $result;
    }

    /**
     * Error for a required single line text with a maximum length.
     *
     * @param string $value
     * @param bool $singleline Whether control characters (line breaks) are rejected.
     * @return string|null
     */
    private static function text_error(string $value, bool $singleline): ?string {
        $value = trim($value);
        if ($value === '') {
            return get_string('required');
        }
        if (\core_text::strlen($value) > rule_service::TEXT_MAXLENGTH) {
            return get_string('error_maxlength', 'local_sis_notification', rule_service::TEXT_MAXLENGTH);
        }
        if ($singleline && preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return get_string('error_subjectcontrol', 'local_sis_notification');
        }
        return null;
    }

    /**
     * Error for a whole number outside a range.
     *
     * @param mixed $value
     * @param int $min
     * @param int $max
     * @return string|null
     */
    private static function range_error($value, int $min, int $max): ?string {
        if (self::in_range($value, $min, $max)) {
            return null;
        }
        return get_string('error_range', 'local_sis_notification', (object) ['min' => $min, 'max' => $max]);
    }

    /**
     * Error for the repeat interval: zero (no repeat) or a number of days.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function repeat_error($value): ?string {
        if (self::in_range($value, 0, rule_service::DAYS_MAX)) {
            return null;
        }
        return get_string('error_repeatdays', 'local_sis_notification', rule_service::DAYS_MAX);
    }

    /**
     * Error when the body has no visible text.
     *
     * @param string $body
     * @return string|null
     */
    private static function body_error(string $body): ?string {
        $plain = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_replace('/[\s\x{00A0}]+/u', '', $plain) === '' ? get_string('required') : null;
    }

    /**
     * Error for the condition type, including what the course needs to support it.
     *
     * @param stdClass $course
     * @param stdClass $new Normalised data.
     * @return string|null
     */
    private static function condition_error(stdClass $course, stdClass $new): ?string {
        $type = (string) $new->conditiontype;
        if (!in_array($type, rule_service::get_condition_types(), true)) {
            return get_string('error_conditiontype', 'local_sis_notification');
        }
        if ($type !== rule_service::CONDITION_COURSE_INCOMPLETE) {
            return null;
        }
        $completion = new \completion_info($course);
        if (!$completion->is_enabled()) {
            return get_string('error_completiondisabled', 'local_sis_notification');
        }
        return $completion->has_criteria() ? null : get_string('error_completioncriteria', 'local_sis_notification');
    }

    /**
     * Error for the activity of an activity rule.
     *
     * @param stdClass $course
     * @param stdClass $new Normalised data.
     * @return string|null
     */
    private static function activity_error(stdClass $course, stdClass $new): ?string {
        if ($new->conditiontype !== rule_service::CONDITION_ACTIVITY_INCOMPLETE) {
            return null;
        }
        $cmid = self::to_int($new->cmid) ?? 0;
        if ($cmid <= 0) {
            return get_string('required');
        }
        return course_access::is_usable_activity($course, $cmid) ? null : get_string('error_cmid', 'local_sis_notification');
    }

    /**
     * Errors for variables that are not allowed in the subject and in the body.
     *
     * @param stdClass $new Normalised data.
     * @param string[] $errors Errors already found; those fields are skipped.
     * @return string[]
     */
    private static function variable_errors(stdClass $new, array $errors): array {
        if (!in_array($new->conditiontype, rule_service::get_condition_types(), true)) {
            return [];
        }
        $allowed = rule_service::get_allowed_variables((string) $new->conditiontype);
        $found = [];
        foreach (['subject', 'body'] as $field) {
            $unknown = isset($errors[$field]) ? [] : self::find_unknown_variables((string) $new->$field, $allowed);
            if ($unknown) {
                $found[$field] = get_string('error_variables', 'local_sis_notification', (object) [
                    'unknown' => implode(', ', $unknown),
                    'allowed' => rule_service::format_variables($allowed),
                ]);
            }
        }
        return $found;
    }

    /**
     * Errors for changes that restart the cycle: only allowed on a paused rule with an explicit confirmation.
     *
     * @param stdClass $old Stored rule.
     * @param stdClass $new Normalised data.
     * @param stdClass $data Submitted data.
     * @return string[]
     */
    private static function cycle_errors(stdClass $old, stdClass $new, stdClass $data): array {
        $changed = self::changed_cycle_fields($old, $new);
        if (!$changed) {
            return [];
        }
        if ($old->enabled) {
            return array_fill_keys($changed, get_string('error_cycleactive', 'local_sis_notification'));
        }
        return empty($data->resetcycle) ? ['resetcycle' => get_string('error_cyclereset', 'local_sis_notification')] : [];
    }

    /**
     * Variables used in a text that are not allowed.
     *
     * @param string $text
     * @param string[] $allowed Allowed names, without braces.
     * @return string[] Unknown variables, with braces.
     */
    private static function find_unknown_variables(string $text, array $allowed): array {
        preg_match_all('/\{([^{}]*)\}/', $text, $matches);
        $unknown = array_diff(array_unique($matches[1]), $allowed);
        return array_map(static fn(string $name): string => '{' . $name . '}', array_values($unknown));
    }

    /**
     * Whether the value is a whole number inside a range.
     *
     * @param mixed $value
     * @param int $min
     * @param int $max
     * @return bool
     */
    private static function in_range($value, int $min, int $max): bool {
        $number = self::to_int($value);
        return $number !== null && $number >= $min && $number <= $max;
    }

    /**
     * Strict integer conversion.
     *
     * @param mixed $value
     * @return int|null Null when the value is not an integer.
     */
    private static function to_int($value): ?int {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value)) {
            return (int) $value;
        }
        return null;
    }
}
