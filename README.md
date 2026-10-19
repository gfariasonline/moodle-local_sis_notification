# SIS Notification

Course reminders for Moodle, developed by **Thiago Serrao**.

This local plugin adds **Course notifications** to course settings. It does not create an activity. Teachers can create independent rules for:

- No recorded access to the course for a number of days.
- A course remaining incomplete.
- A selected activity remaining incomplete.

Each rule has a subject, message, delay, optional repeat interval and a maximum number of notifications. New rules are enabled when created (the option can be unticked to save them paused). Days are periods of 24 hours, not calendar dates. Existing students are observed from activation, preventing an immediate backlog of reminders. Activity reminders start when the activity is first observed as available to that student.

## Installation

Install the `sis_notification` directory under Moodle's `local/` directory and complete the normal administrator upgrade. In Moodle installations with a public directory, use `public/local/sis_notification`.

Configure cron normally. Discovery runs every five minutes and uses Moodle ad hoc tasks for delivery. A delayed cron does not send all missed repetitions at once. The batch size limits recipients examined and tasks queued globally per discovery run; a cursor rotates between rules.

The initial release is alpha. Moodle 4.5 is the minimum API target; support for a version requires testing on that version. See the validation record supplied with the release for environments actually tested.

## Eligibility and delivery

Both the account and course enrolment must be active immediately before sending. Deleted, suspended or unconfirmed accounts, suspended enrolments, expired/future enrolments and disabled enrolment instances cannot receive notifications. At least one active enrolment and the `local/sis_notification:receive` capability are required. Multiple enrolments never duplicate a recipient.

Course and activity completion is read through Moodle APIs; the plugin does not change completion, grades or enrolments. Pending course completion aggregation defers the notification, and so does disabled completion tracking: the rule resumes by itself when tracking is enabled again. Hidden or ended courses do not send reminders. An unavailable activity does not send reminders.

Notifications use Moodle's Message API. Web and email delivery depend on enabled processors and user preferences; mobile push also depends on the site's configured infrastructure. **Accepted by the Message API is not proof of email delivery or reading.** Offline mobile use is only visible after Moodle records it; this plugin does not implement a mobile administration interface.

Reservations, a unique key and locks prevent repeated workers from sending the same accepted notification. A crash after processor acceptance but before recording its result is ambiguous: the dispatch is marked uncertain and is not automatically resent. An error raised by the Message API is different: the dispatch is tried again up to three times, with a growing delay, before it is marked uncertain. Investigate uncertain dispatches before taking further action.

## Templates and permissions

Supported variables: `{firstname}`, `{fullname}`, `{coursename}`, `{courseurl}`, `{days}`. Activity rules also accept `{activityname}` and `{activityurl}`. HTML is filtered. Attachments and uploaded images are not supported.

- `local/sis_notification:manage`: manage rules in the course.
- `local/sis_notification:viewhistory`: view dispatch history, respecting separate groups.
- `local/sis_notification:receive`: eligibility to receive reminders (student archetype by default).

Changing a rule's condition, days or activity requires pausing it and explicitly restarting its cycle. Editing text preserves counters. Course backups contain configuration only; restored rules are disabled and activity IDs are remapped. Resetting a course pauses rules and clears student cycles. Deleting an activity used by a rule pauses it and restarts its cycle. Erasing the data of a whole course removes the personal state and pauses its rules to avoid immediately recreating erased data; erasing some users removes only their state and leaves the rules running. Unenrolling or deleting a user ends their cycles; when a rule is busy at that moment, an ad hoc task finishes the cleanup later.

History retention defaults to 180 days and applies to accepted and cancelled dispatches. Cycle counters are retained while the rule exists to prevent retention cleanup from repeating a completed reminder. Unknown outcomes, and dispatches that are still pending or processing, are never removed by the retention cleanup, so they remain available for investigation.

## Validation

Validated on 16 October 2026 with GitHub Actions (Moodle Plugin CI): Moodle 4.5 (PHP 8.2 / MariaDB and PHP 8.3 / PostgreSQL), Moodle 5.2 (PHP 8.3 / MariaDB) and Moodle 5.3 (PHP 8.3 / PostgreSQL). Each run covers PHP lint, coding standard, PHPDoc, plugin validation, upgrade savepoints, Grunt, PHPUnit and Behat. External SMTP/push delivery and representative production load have not been validated.

Version 0.1.1 was re-run locally on 19 October 2026: PHPUnit on Moodle 4.5 (PHP 8.1 / MariaDB) and Moodle 5.3 (PHP 8.3 / MariaDB), Behat on Moodle 4.5, and the Moodle coding standard on both.

## Changelog

### 0.1.1

- Lifecycle cleanup (course reset, rule or course deletion, deleted activity, erasure) waits longer for the rule lock and rolls back on failure. Unenrolling or deleting a user no longer fails when a rule is busy: an ad hoc task finishes the cleanup.
- Erasing some users no longer pauses the rules of the whole course.
- Deleting the activity of a rule pauses it, restarts its cycle and records the reason in the history.
- An error from the Message API is retried up to three times before the dispatch is marked uncertain; a busy rule no longer makes the delivery task fail.
- Disabled completion tracking no longer ends the cycle of the students.
- The retention cleanup also removes old cancelled dispatches.
- Course and activity names containing `&` are no longer escaped twice in the message.
- Creating a rule that cannot be enabled no longer sends the teacher back to a form that would create it again.
- The unused failed status was removed.

### 0.1.0

- Initial alpha release.

## Licence

Licensed under GPL v3 or later.
