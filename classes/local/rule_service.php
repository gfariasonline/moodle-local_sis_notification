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

use core\lock\lock_config;
use stdClass;

/**
 * Creates, validates and changes notification rules.
 *
 * Every public method that changes a rule takes the course id and only touches rules of that course,
 * and takes the same per-rule lock as the delivery engine ("rule:ID" in the local_sis_notification
 * lock factory) so a rule is never changed while the engine is processing it.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_service {
    /** @var string Notify students that were not seen in the course for a number of days. */
    public const CONDITION_INACTIVITY = 'inactivity';
    /** @var string Notify students that have not completed the course. */
    public const CONDITION_COURSE_INCOMPLETE = 'course_incomplete';
    /** @var string Notify students that have not completed one activity. */
    public const CONDITION_ACTIVITY_INCOMPLETE = 'activity_incomplete';

    /** @var string Delivery waiting to be sent. */
    public const STATUS_PENDING = 'pending';
    /** @var string Delivery being handled by the engine. */
    public const STATUS_PROCESSING = 'processing';
    /** @var string Delivery accepted by the messaging API (not a delivery confirmation). */
    public const STATUS_ACCEPTED = 'accepted';
    /** @var string Delivery with an unknown outcome. */
    public const STATUS_UNCERTAIN = 'uncertain';
    /** @var string Delivery that failed. */
    public const STATUS_FAILED = 'failed';
    /** @var string Delivery that was cancelled before it was sent. */
    public const STATUS_SUPPRESSED = 'suppressed';

    /** @var string Reason stored on pending deliveries suppressed because the rule was paused. */
    public const REASON_PAUSED = 'rule_paused';
    /** @var string Reason stored on pending deliveries suppressed because the cycle was restarted. */
    public const REASON_REVISED = 'rule_revised';

    /** @var int Smallest number of days. */
    public const DAYS_MIN = 1;
    /** @var int Largest number of days. */
    public const DAYS_MAX = 3650;
    /** @var int Largest number of messages per user and cycle, including the first one. */
    public const MAXSENDS_MAX = 10;
    /** @var int Maximum length of the name and of the subject. */
    public const TEXT_MAXLENGTH = 255;

    /** @var int Seconds to wait for the rule lock. */
    public static int $locktimeout = 10;

    /** @var \core\lock\lock_factory|null Lock factory override, for tests. The plugin lock factory is used when null. */
    public static ?\core\lock\lock_factory $lockfactory = null;

    /**
     * Variables that can be used in the subject and in the body.
     *
     * @param string $conditiontype
     * @return string[] Variable names, without braces.
     */
    public static function get_allowed_variables(string $conditiontype): array {
        $variables = ['firstname', 'fullname', 'coursename', 'courseurl', 'days'];
        if ($conditiontype === self::CONDITION_ACTIVITY_INCOMPLETE) {
            $variables[] = 'activityname';
            $variables[] = 'activityurl';
        }
        return $variables;
    }

    /**
     * Variables as a comma separated list, with braces.
     *
     * @param string[] $names
     * @return string
     */
    public static function format_variables(array $names): string {
        return implode(', ', array_map(static fn(string $name): string => '{' . $name . '}', $names));
    }

    /**
     * Supported condition types.
     *
     * @return string[]
     */
    public static function get_condition_types(): array {
        return [
            self::CONDITION_INACTIVITY,
            self::CONDITION_COURSE_INCOMPLETE,
            self::CONDITION_ACTIVITY_INCOMPLETE,
        ];
    }

    /**
     * Delivery statuses shown in the history.
     *
     * @return string[]
     */
    public static function get_statuses(): array {
        return [
            self::STATUS_ACCEPTED,
            self::STATUS_UNCERTAIN,
            self::STATUS_FAILED,
            self::STATUS_SUPPRESSED,
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
        ];
    }

    /**
     * Rules of a course.
     *
     * @param int $courseid
     * @return stdClass[] Rules indexed by id.
     */
    public static function get_rules(int $courseid): array {
        global $DB;
        return $DB->get_records('local_sis_notification_rule', ['courseid' => $courseid], 'id ASC');
    }

    /**
     * One rule of a course.
     *
     * @param int $id
     * @param int $courseid
     * @return stdClass
     */
    public static function get_rule(int $id, int $courseid): stdClass {
        global $DB;
        return $DB->get_record('local_sis_notification_rule', ['id' => $id, 'courseid' => $courseid], '*', MUST_EXIST);
    }

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
        return rule_validator::validate($data, $courseid);
    }

    /**
     * Create or update a rule.
     *
     * New rules are always created paused. Changing the condition, the days or the activity of an existing rule
     * restarts its cycle: it is only allowed on paused rules with the resetcycle flag.
     *
     * @param stdClass $data Rule data. The body can be the text or the array returned by an editor element.
     * @param int $courseid
     * @return int The rule id.
     */
    public static function save(stdClass $data, int $courseid): int {
        $id = empty($data->id) ? 0 : (int) $data->id;
        $save = static function () use ($data, $courseid): int {
            self::assert_valid($data, $courseid);
            $old = empty($data->id) ? null : self::get_rule((int) $data->id, $courseid);
            $new = rule_validator::normalise($data, $old);
            return self::in_transaction(
                static fn(): int => $old ? self::update_rule($old, $new) : self::insert_rule($new, $courseid)
            );
        };
        return $id ? self::with_lock($id, $save) : $save();
    }

    /**
     * Enable (resume) or pause a rule.
     *
     * Pausing keeps the activation date and the counters and suppresses the pending deliveries; deliveries that were
     * accepted, are being processed or have an uncertain outcome are never touched.
     *
     * @param int $id
     * @param int $courseid
     * @param bool $enabled
     */
    public static function set_enabled(int $id, int $courseid, bool $enabled): void {
        global $DB;

        self::with_lock($id, static function () use ($DB, $id, $courseid, $enabled): void {
            $rule = self::get_rule($id, $courseid);
            if ((bool) $rule->enabled === $enabled) {
                return;
            }
            if ($enabled) {
                self::assert_valid($rule, $courseid);
            }

            $now = self::now();
            $update = (object) ['id' => $rule->id, 'enabled' => (int) $enabled, 'timemodified' => $now];
            if ($enabled && empty($rule->activatedat)) {
                $update->activatedat = $now;
            }
            self::in_transaction(static function () use ($DB, $update, $rule, $enabled, $now): void {
                $DB->update_record('local_sis_notification_rule', $update);
                if (!$enabled) {
                    self::suppress_pending($rule->id, self::REASON_PAUSED, $now);
                }
            });
        });
    }

    /**
     * Delete a rule with its deliveries and per user states.
     *
     * @param int $id
     * @param int $courseid
     */
    public static function delete(int $id, int $courseid): void {
        global $DB;

        self::with_lock($id, static function () use ($DB, $id, $courseid): void {
            self::get_rule($id, $courseid);
            self::in_transaction(static function () use ($DB, $id): void {
                $DB->delete_records('local_sis_notification_send', ['ruleid' => $id]);
                $DB->delete_records('local_sis_notification_state', ['ruleid' => $id]);
                $DB->delete_records('local_sis_notification_rule', ['id' => $id]);
            });
        });
    }

    /**
     * Throw when the data of a rule is not valid.
     *
     * @param stdClass $data
     * @param int $courseid
     */
    private static function assert_valid(stdClass $data, int $courseid): void {
        $errors = self::validate($data, $courseid);
        if ($errors) {
            $parts = [];
            foreach ($errors as $field => $message) {
                $parts[] = $field . ': ' . $message;
            }
            throw new \invalid_parameter_exception(implode('; ', $parts));
        }
    }

    /**
     * Record with the editable fields of a rule.
     *
     * @param stdClass $new Normalised data.
     * @return stdClass
     */
    private static function build_record(stdClass $new): stdClass {
        $record = new stdClass();
        $record->name = trim((string) $new->name);
        $record->conditiontype = (string) $new->conditiontype;
        $record->days = (int) $new->days;
        $record->cmid = $record->conditiontype === self::CONDITION_ACTIVITY_INCOMPLETE ? (int) $new->cmid : 0;
        $record->subject = trim((string) $new->subject);
        $record->body = $new->body;
        $record->bodyformat = FORMAT_HTML;
        $record->repeatdays = (int) $new->repeatdays;
        $record->maxsends = $record->repeatdays === 0 ? 1 : (int) $new->maxsends;
        $record->timemodified = self::now();
        return $record;
    }

    /**
     * Create a paused rule.
     *
     * @param stdClass $new Normalised data.
     * @param int $courseid
     * @return int The rule id.
     */
    private static function insert_rule(stdClass $new, int $courseid): int {
        global $DB, $USER;

        $record = self::build_record($new);
        $record->courseid = $courseid;
        $record->enabled = 0;
        $record->activatedat = 0;
        $record->revision = 1;
        $record->createdby = (int) $USER->id;
        $record->timecreated = $record->timemodified;
        $record->scanuserid = 0;
        return $DB->insert_record('local_sis_notification_rule', $record);
    }

    /**
     * Update the editable fields of a rule, restarting its cycle when the condition, the days or the activity changed.
     *
     * @param stdClass $old Stored rule.
     * @param stdClass $new Normalised data.
     * @return int The rule id.
     */
    private static function update_rule(stdClass $old, stdClass $new): int {
        global $DB;

        $record = self::build_record($new);
        $record->id = $old->id;
        if (rule_validator::cycle_changed($old, $new)) {
            $record->revision = (int) $old->revision + 1;
            $record->activatedat = 0;
            $record->scanuserid = 0;
            self::suppress_pending($old->id, self::REASON_REVISED, $record->timemodified);
        }
        $DB->update_record('local_sis_notification_rule', $record);
        return (int) $old->id;
    }

    /**
     * Run work while holding the lock shared with the delivery engine.
     *
     * @param int $ruleid
     * @param callable $work
     * @return mixed What the work returns.
     */
    private static function with_lock(int $ruleid, callable $work) {
        $factory = self::$lockfactory ?? lock_config::get_lock_factory('local_sis_notification');
        $lock = $factory->get_lock('rule:' . $ruleid, self::$locktimeout, 120);
        if (!$lock) {
            throw new \moodle_exception('rulelocked', 'local_sis_notification');
        }
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * Run work in a transaction that is rolled back when it fails.
     *
     * @param callable $work
     * @return mixed What the work returns.
     */
    private static function in_transaction(callable $work) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $result = $work();
            $transaction->allow_commit();
            return $result;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Cancel the pending deliveries of a rule. Deliveries in any other status are left untouched.
     *
     * @param int $ruleid
     * @param string $reason
     * @param int $now
     */
    private static function suppress_pending(int $ruleid, string $reason, int $now): void {
        global $DB;
        $DB->execute(
            "UPDATE {local_sis_notification_send}
                SET status = :newstatus, reason = :reason, timemodified = :now
              WHERE ruleid = :ruleid AND status = :pending",
            [
                'newstatus' => self::STATUS_SUPPRESSED, 'reason' => $reason, 'now' => $now,
                'ruleid' => $ruleid, 'pending' => self::STATUS_PENDING,
            ]
        );
    }

    /**
     * Current time, from the clock so tests can control it.
     *
     * @return int
     */
    private static function now(): int {
        return \core\di::get(\core\clock::class)->time();
    }
}
