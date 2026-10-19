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
 * English strings.
 *
 * @package    local_sis_notification
 * @copyright  2026 Thiago Serrao
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actionon'] = '{$a->action}: {$a->name}';
$string['addrule'] = 'Add a rule';
$string['addrulepage'] = 'Add rule';
$string['allrules'] = 'All rules';
$string['allstatuses'] = 'All statuses';
$string['attempts'] = 'Attempts';
$string['backtorules'] = 'Back to the rules';
$string['batchsize'] = 'Batch size';
$string['batchsize_desc'] = 'Maximum number of users processed per run of the notification task.';
$string['body'] = 'Message';
$string['cmid'] = 'Activity';
$string['cmid_help'] = 'Only activities of this course with activity completion enabled can be chosen.';
$string['condition'] = 'Condition';
$string['conditiontype'] = 'Notify students';
$string['conditiontype_activity_incomplete'] = 'who have not completed an activity';
$string['conditiontype_course_incomplete'] = 'who have not completed the course';
$string['conditiontype_inactivity'] = 'who have not accessed the course';
$string['confirmdelete'] = 'Delete the rule "{$a}"? Its delivery history is deleted too and this cannot be undone. To stop sending without losing the history, pause the rule instead.';
$string['countnoengine'] = 'The count of eligible students is not available yet.';
$string['countrecipients'] = 'Count eligible students';
$string['countrecipients_help'] = 'Counts the students who could receive a message from this course right now. It may take a few seconds in large courses.';
$string['countresult'] = '{$a->eligible} eligible students out of {$a->examined} examined; {$a->temporary} are temporarily not eligible. Counted at {$a->time}.';
$string['counttruncated'] = 'The count is partial: only the first {$a->examined} students were examined. Counted at {$a->time}.';
$string['cyclechangenote'] = 'Changing the condition, days or activity restarts the cycle of this rule.';
$string['cyclelockednote'] = 'The condition, days and activity cannot be changed while the rule is enabled. Pause the rule first.';
$string['days'] = 'Days';
$string['days_help'] = 'Number of days (1 to 3650) the condition must hold before the message is sent. The count never starts before the rule was enabled (or restarted), so students who already meet the condition at that moment are not notified at once. Inactivity: counted from the last access to the course or, if there was none after it, from the latest of the enrolment, the course start date and the rule activation. Course completion: counted from the latest of the enrolment, the course start date and the rule activation. Activity completion: counted from the first moment the activity is available to the student, and never before the enrolment, the course start date or the rule activation.';
$string['defaultbody'] = '<p>Hi {fullname},</p><p>We noticed that you have not accessed {coursename} for {days} days. Come back and continue from where you stopped: {courseurl}</p>';
$string['defaultsubject'] = 'We miss you, {firstname}';
$string['deleterule'] = 'Delete';
$string['editrule'] = 'Edit';
$string['editrulepage'] = 'Edit rule';
$string['enableonsave'] = 'Enable the rule';
$string['enableonsave_check'] = 'Enable this rule as soon as it is saved';
$string['enablerule'] = 'Enable';
$string['error_cmid'] = 'Choose an activity of this course that has activity completion enabled.';
$string['error_completioncriteria'] = 'This course has no completion criteria.';
$string['error_completiondisabled'] = 'Course completion is not enabled for this course.';
$string['error_conditiontype'] = 'Choose one of the available conditions.';
$string['error_cycleactive'] = 'This cannot be changed while the rule is enabled. Pause the rule first.';
$string['error_cyclereset'] = 'Check this box to confirm that the rule starts over.';
$string['error_maxlength'] = 'Use at most {$a} characters.';
$string['error_range'] = 'Enter a whole number from {$a->min} to {$a->max}.';
$string['error_repeatdays'] = 'Enter 0 to send once, or a whole number from 1 to {$a}.';
$string['error_subjectcontrol'] = 'The subject must be a single line of text.';
$string['error_variables'] = 'Unknown variable: {$a->unknown}. Allowed variables: {$a->allowed}.';
$string['filter'] = 'Filter';
$string['filterrule'] = 'Rule';
$string['filterstatus'] = 'Status';
$string['general'] = 'Rule';
$string['history'] = 'Notification history';
$string['historyempty'] = 'There are no deliveries to show.';
$string['historyintro'] = 'Deliveries to students of this course. Message contents are not shown.';
$string['lastchange'] = 'Last change';
$string['maxsends'] = 'Maximum messages per student';
$string['maxsends_help'] = 'The total number of messages (1 to 10) a student can receive in one cycle, including the first one. It only matters when repeating.';
$string['message'] = 'Message';
$string['messageprovider:course_notification'] = 'Course notifications sent by rules';
$string['name'] = 'Rule name';
$string['navigationlabel'] = 'Notifications';
$string['norepeatsummary'] = 'Sent once';
$string['norules'] = 'There are no notification rules in this course yet.';
$string['pauserule'] = 'Pause';
$string['pluginname'] = 'Course notifications';
$string['previewalert'] = 'The variables were filled in with your own data.';
$string['previewcountfailed'] = 'The count could not be completed.';
$string['previewintro'] = 'This is how the message looks for you. Nothing is sent.';
$string['previewmessage'] = 'Message';
$string['previewpage'] = 'Preview: {$a}';
$string['previewrule'] = 'Preview';
$string['previewsubject'] = 'Subject';
$string['previewusing'] = 'Preview generated with the course "{$a->course}" and the user "{$a->user}".';
$string['privacy:metadata'] = 'The course notifications plugin stores which notifications were sent to each user, with their status and dates.';
$string['privacy:metadata:core_message'] = 'Notifications are passed to the Moodle messaging subsystem.';
$string['privacy:metadata:count'] = 'Reminder cycle and processing counters.';
$string['privacy:metadata:createdby'] = 'The author of a notification rule.';
$string['privacy:metadata:messageid'] = 'Identifier returned by the Moodle Message API.';
$string['privacy:metadata:rule'] = 'Course notification configuration and authorship.';
$string['privacy:metadata:send'] = 'Notification reservations and processing outcomes.';
$string['privacy:metadata:state'] = 'Recipient enrolment and reminder cycle state.';
$string['privacy:metadata:status'] = 'Processing status and reason codes.';
$string['privacy:metadata:time'] = 'Timestamps used to evaluate and schedule reminders.';
$string['privacy:metadata:userid'] = 'The recipient user identifier.';
$string['reason'] = 'Reason';
$string['reason_activity_deleted'] = 'Target activity deleted';
$string['reason_activity_unavailable'] = 'Activity unavailable to this student';
$string['reason_api_exception'] = 'Messaging processor returned an unknown outcome';
$string['reason_api_result_unknown'] = 'Messaging API did not confirm acceptance';
$string['reason_completed'] = 'Course or activity already completed';
$string['reason_completion_disabled'] = 'Completion tracking or course criteria unavailable';
$string['reason_completion_pending'] = 'Waiting for course completion aggregation';
$string['reason_condition_met'] = 'Condition no longer applies';
$string['reason_course_deleted'] = 'Course deleted';
$string['reason_course_unavailable'] = 'Course hidden, not started or ended';
$string['reason_cycle_finished'] = 'Cycle has reached its notification limit';
$string['reason_enrolment_inactive'] = 'Enrolment suspended or enrolment method disabled';
$string['reason_invalid_condition'] = 'Invalid condition';
$string['reason_lease_expired'] = 'Processing stopped with an unknown outcome';
$string['reason_max_sends'] = 'Maximum messages reached';
$string['reason_new_access'] = 'The student returned before the notification was sent';
$string['reason_no_permission'] = 'Cannot receive notifications';
$string['reason_no_receive_capability'] = 'Recipient does not have permission to receive reminders';
$string['reason_not_enrolled'] = 'No longer enrolled';
$string['reason_obsolete'] = 'Reservation belongs to a previous cycle';
$string['reason_rule_disabled'] = 'Rule not enabled';
$string['reason_rule_paused'] = 'Rule paused';
$string['reason_rule_revised'] = 'Rule restarted';
$string['reason_unenrolled'] = 'No active enrolment within its validity period';
$string['reason_user_deleted'] = 'Account deleted or unavailable';
$string['reason_user_inactive'] = 'Account suspended or not confirmed';
$string['reason_user_suspended'] = 'Account or enrolment not active';
$string['repeatdays'] = 'Repeat every (days)';
$string['repeatdays_help'] = 'Use 0 to send the message only once. Otherwise the message is sent again after this many days (1 to 3650) while the student still meets the condition.';
$string['repeatsummary'] = 'Repeats every {$a->repeatdays} days, up to {$a->maxsends} messages';
$string['resetcycle'] = 'Restart the cycle';
$string['resetcycle_check'] = 'I understand that the rule will start over';
$string['resetcycle_help'] = 'Changing the condition, the number of days or the activity makes the rule start over: counters are restarted and messages still waiting are cancelled. Check this box to confirm. The rule must be paused to do this.';
$string['retentiondays'] = 'History retention (days)';
$string['retentiondays_desc'] = 'How long delivery records are kept before they are removed.';
$string['ruleactions'] = 'Actions';
$string['ruleactivatedat'] = 'Active since {$a}';
$string['rulecreated'] = 'The rule "{$a}" was created. It is paused: enable it when you are ready.';
$string['rulecreatedenabled'] = 'The rule "{$a}" was created and is enabled.';
$string['ruledeleted'] = 'The rule "{$a}" and its delivery history were deleted.';
$string['ruleenabled'] = 'Enabled';
$string['ruleenabledmessage'] = 'The rule "{$a}" is now enabled.';
$string['ruleenablefailed'] = 'The rule "{$a->name}" cannot be enabled: {$a->errors}';
$string['rulelocked'] = 'The rule is being processed right now. Try again in a moment.';
$string['rulename'] = 'Rule name';
$string['rulenotfound'] = 'The rule no longer exists.';
$string['rulepaused'] = 'Paused';
$string['rulepausedmessage'] = 'The rule "{$a}" was paused. Messages that were still waiting to be sent were cancelled.';
$string['rules'] = 'Notification rules';
$string['rulesheading'] = 'Rules in this course';
$string['rulesintro'] = 'Rules send a message to students of this course. A new rule is enabled when created, but you can untick the option while creating it or pause it later.';
$string['rulestatus'] = 'Status';
$string['rulesummary'] = 'Condition';
$string['rulesummary_activity_incomplete'] = '"{$a->activity}" not completed {$a->days} days after it becomes available';
$string['rulesummary_activity_missing'] = 'an activity that no longer exists';
$string['rulesummary_course_incomplete'] = 'Course not completed for {$a->days} days';
$string['rulesummary_inactivity'] = 'No access to the course for {$a->days} days';
$string['ruleupdated'] = 'The rule "{$a}" was saved.';
$string['scheduledfor'] = 'Scheduled for';
$string['sending'] = 'Sending';
$string['sequence'] = 'Message';
$string['sequencevalue'] = '#{$a->seq} (cycle {$a->cycle})';
$string['sis_notification:manage'] = 'Manage course notification rules';
$string['sis_notification:receive'] = 'Receive course notifications';
$string['sis_notification:viewhistory'] = 'View the course notification history';
$string['status_accepted'] = 'Accepted';
$string['status_accepted_help'] = 'Accepted by the messaging system. This is not a confirmation that the student received or read the message.';
$string['status_pending'] = 'Waiting';
$string['status_processing'] = 'Processing';
$string['status_suppressed'] = 'Cancelled';
$string['status_uncertain'] = 'Uncertain';
$string['statusnote'] = '"Accepted" means the message was accepted by the messaging system; it does not confirm delivery to the student.';
$string['student'] = 'Student';
$string['subject'] = 'Subject';
$string['task_cleanup_history'] = 'Clean up old notification history';
$string['task_process_rules'] = 'Process notification rules';
$string['taskcleanup'] = 'Clean up notification history';
$string['tasklifecycle'] = 'Finish the notification cleanup of a user';
$string['taskscan'] = 'Discover course notifications';
$string['variables'] = 'Variables you can use';
$string['variables_activity'] = 'Only when the condition is an activity: {$a}.';
$string['variables_common'] = 'Use these variables in the subject and in the message: {$a}.';
$string['viewhistory'] = 'View history';
