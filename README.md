# SIS Notification

Course reminders for Moodle, developed by **Thiago Serrao**.

This local plugin adds **Course notifications** to course settings. It does not create an activity. Teachers can create independent rules for:

- No recorded access to the course for a number of days.
- A course remaining incomplete.
- A selected activity remaining incomplete.

Each rule has a subject, message, delay, optional repeat interval and a maximum number of notifications. Rules start disabled. Days are periods of 24 hours, not calendar dates. Existing students are observed from activation, preventing an immediate backlog of reminders. Activity reminders start when the activity is first observed as available to that student.

## Installation

Install the `sis_notification` directory under Moodle's `local/` directory and complete the normal administrator upgrade. In Moodle installations with a public directory, use `public/local/sis_notification`.

Configure cron normally. Discovery runs every five minutes and uses Moodle ad hoc tasks for delivery. A delayed cron does not send all missed repetitions at once. The batch size limits recipients examined and tasks queued globally per discovery run; a cursor rotates between rules.

The initial release is alpha. Moodle 4.5 is the minimum API target; support for a version requires testing on that version. See the validation record supplied with the release for environments actually tested.

## Eligibility and delivery

Both the account and course enrolment must be active immediately before sending. Deleted, suspended or unconfirmed accounts, suspended enrolments, expired/future enrolments and disabled enrolment instances cannot receive notifications. At least one active enrolment and the `local/sis_notification:receive` capability are required. Multiple enrolments never duplicate a recipient.

Course and activity completion is read through Moodle APIs; the plugin does not change completion, grades or enrolments. Pending course completion aggregation defers the notification. Hidden or ended courses do not send reminders. An unavailable activity does not send reminders.

Notifications use Moodle's Message API. Web and email delivery depend on enabled processors and user preferences; mobile push also depends on the site's configured infrastructure. **Accepted by the Message API is not proof of email delivery or reading.** Offline mobile use is only visible after Moodle records it; this plugin does not implement a mobile administration interface.

Reservations, a unique key and locks prevent repeated workers from sending the same accepted notification. A crash after processor acceptance but before recording its result is ambiguous: the dispatch is marked uncertain and is not automatically resent. Investigate uncertain dispatches before taking further action.

## Templates and permissions

Supported variables: `{firstname}`, `{fullname}`, `{coursename}`, `{courseurl}`, `{days}`. Activity rules also accept `{activityname}` and `{activityurl}`. HTML is filtered. Attachments and uploaded images are not supported.

- `local/sis_notification:manage`: manage rules in the course.
- `local/sis_notification:viewhistory`: view dispatch history, respecting separate groups.
- `local/sis_notification:receive`: eligibility to receive reminders (student archetype by default).

Changing a rule's condition, days or activity requires pausing it and explicitly restarting its cycle. Editing text preserves counters. Course backups contain configuration only; restored rules are disabled and activity IDs are remapped. Resetting a course pauses rules and clears student cycles. User erasure removes personal state and pauses affected rules to avoid immediately recreating erased data.

History retention defaults to 180 days. Cycle counters are retained while the rule exists to prevent retention cleanup from repeating a completed reminder. Unknown outcomes remain available for investigation.

## Development checks

The configured local CI tool is `~/Sites/moodle-plugin-ci/bin/moodle-plugin-ci`. Run from the plugin checkout, with `MOODLE_DIR` pointing at the Moodle root containing `config.php` and `vendor/`:

```sh
~/Sites/moodle-plugin-ci/bin/moodle-plugin-ci phplint .
~/Sites/moodle-plugin-ci/bin/moodle-plugin-ci phpmd .
~/Sites/moodle-plugin-ci/bin/moodle-plugin-ci phpcs --max-warnings 0 .
~/Sites/moodle-plugin-ci/bin/moodle-plugin-ci phpdoc --moodle "$MOODLE_DIR" --max-warnings 0 .
~/Sites/moodle-plugin-ci/bin/moodle-plugin-ci validate --moodle "$MOODLE_DIR" .
~/Sites/moodle-plugin-ci/bin/moodle-plugin-ci savepoints .
~/Sites/moodle-plugin-ci/bin/moodle-plugin-ci phpunit --moodle "$MOODLE_DIR" --fail-on-warning .
```

PHPUnit uses synthetic accounts and a message sink. Never run notification tests against real recipients.

## Validation

Validated on 16 October 2026 with Moodle 5.3, PHP 8.4.26 and MariaDB 11.4.13: PHPUnit passed 88 tests / 267 assertions; Behat passed 8 scenarios / 97 steps. Static CI checks passed using PHP 8.3.35 for tool compatibility. Other Moodle versions, external SMTP/push delivery and representative production load have not been validated.

## Reference and licence

Licensed under GPL v3 or later. The design was informed by Catalyst's [Re-engagement activity](https://github.com/catalyst/moodle-mod_reengagement), reference commit `7a34c3f32b7e147ed22c62de5d0c0a76dc81364a`. The notification engine is implemented independently; no `mod_reengagement` installation is required.
