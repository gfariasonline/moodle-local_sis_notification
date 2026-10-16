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
 * Previews of a rule: the rendered message and the number of users that could receive it. Nothing is sent.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_service {
    /** @var int Maximum seconds spent counting eligible users. */
    public const MAX_SECONDS = 8;

    /** @var int Maximum number of users examined when counting eligible users. */
    public static int $limit = 1000;

    /** @var int Number of candidates requested from the engine per page. */
    public static int $pagesize = 200;

    /**
     * Whether the engine classes used by the preview are available.
     *
     * @return bool
     */
    public static function engine_available(): bool {
        return class_exists(recipient_selector::class)
            && method_exists(recipient_selector::class, 'get_candidates')
            && method_exists(recipient_selector::class, 'get_eligibility');
    }

    /**
     * Render the message of a rule for one user, without sending it.
     *
     * Uses the engine renderer, so the preview matches what is delivered.
     *
     * @param stdClass $rule
     * @param stdClass $user
     * @param stdClass $course
     * @return stdClass With subject, html and plain.
     */
    public static function render(stdClass $rule, stdClass $user, stdClass $course): stdClass {
        return message_renderer::render($rule, $user, $course);
    }

    /**
     * Counts the users that are eligible for a notification right now.
     *
     * The engine candidates include suspended and expired users, so each one is checked with get_eligibility().
     * The work is bounded in the number of users examined and in time, so the result can be partial.
     *
     * @param int $courseid
     * @return stdClass|null Null when the engine is not available.
     */
    public static function eligibility_summary(int $courseid): ?stdClass {
        if (!self::engine_available()) {
            return null;
        }
        $started = microtime(true);
        $summary = (object) [
            'examined' => 0, 'eligible' => 0, 'temporary' => 0, 'ineligible' => 0,
            'truncated' => false, 'computedat' => \core\di::get(\core\clock::class)->time(),
        ];
        $allowed = course_access::get_visible_userids(get_course($courseid), \core\context\course::instance($courseid));

        $after = 0;
        do {
            $candidates = recipient_selector::get_candidates($courseid, $after, self::$pagesize, null);
            $candidatecount = count($candidates);
            foreach ($candidates as $candidate) {
                $userid = self::candidate_userid($candidate);
                $after = max($after, $userid);
                if (!self::is_countable($userid, $allowed)) {
                    continue;
                }
                if (self::budget_spent($summary, $started)) {
                    $summary->truncated = true;
                    return $summary;
                }
                $summary->examined++;
                $outcome = self::classify(recipient_selector::get_eligibility($courseid, $userid, null));
                $summary->$outcome++;
            }
        } while ($candidatecount >= self::$pagesize);
        return $summary;
    }

    /**
     * Whether a user takes part in the count: a real user the current user is allowed to see.
     *
     * @param int $userid
     * @param array|null $allowed User ids as keys, or null when there is no restriction.
     * @return bool
     */
    private static function is_countable(int $userid, ?array $allowed): bool {
        return $userid > 0 && ($allowed === null || isset($allowed[$userid]));
    }

    /**
     * Whether the count used up its user or time budget.
     *
     * @param stdClass $summary
     * @param float $started
     * @return bool
     */
    private static function budget_spent(stdClass $summary, float $started): bool {
        return $summary->examined >= self::$limit || microtime(true) - $started > self::MAX_SECONDS;
    }

    /**
     * Name of the summary counter an eligibility result belongs to.
     *
     * Users that cannot receive notifications at all are not "temporarily" ineligible, whatever the engine says.
     *
     * @param stdClass $result Result of get_eligibility().
     * @return string
     */
    private static function classify(stdClass $result): string {
        if (!empty($result->eligible)) {
            return 'eligible';
        }
        $temporary = !empty($result->temporary) && ($result->reason ?? '') !== 'no_receive_capability';
        return $temporary ? 'temporary' : 'ineligible';
    }

    /**
     * Candidate user id, whatever form the engine returns the candidates in.
     *
     * @param mixed $candidate
     * @return int
     */
    private static function candidate_userid($candidate): int {
        if (is_object($candidate)) {
            return (int) ($candidate->userid ?? $candidate->id ?? 0);
        }
        if (is_array($candidate)) {
            return (int) ($candidate['userid'] ?? $candidate['id'] ?? 0);
        }
        return (int) $candidate;
    }
}
