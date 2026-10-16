@local @local_sis_notification
Feature: Manage the notification rules of a course
  In order to remind students about their progress
  As a teacher
  I need to create, enable, pause and delete notification rules in my course

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | teacher2 | Teacher   | Two      | teacher2@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | teacher2 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | name       | course | idnumber | completion |
      | page     | Read first | C1     | page1    | 1          |

  Scenario: A new rule is created enabled and can be paused and enabled again
    Given I am on the "Course 1" course page logged in as teacher1
    When I navigate to "Notifications" in current page administration
    Then I should see "There are no notification rules in this course yet."
    And I click on "Add a rule" "link"
    And I set the following fields to these values:
      | Rule name | Weekly nudge                              |
      | Days      | 7                                         |
      | Subject   | We miss you, {firstname}                  |
      | Message   | Hi {fullname}, come back to {coursename}. |
    And I press "Save changes"
    And I should see "The rule \"Weekly nudge\" was created and is enabled."
    And I should see "Enabled" in the "Weekly nudge" "table_row"
    And I click on "Pause" "button" in the "Weekly nudge" "table_row"
    And I should see "The rule \"Weekly nudge\" was paused."
    And I should see "Paused" in the "Weekly nudge" "table_row"
    And I click on "Enable" "button" in the "Weekly nudge" "table_row"
    And I should see "The rule \"Weekly nudge\" is now enabled."
    And I should see "Enabled" in the "Weekly nudge" "table_row"

  Scenario: A new rule is enabled by default and starts with an example message
    Given I am on the "Course 1" course page logged in as teacher1
    When I navigate to "Notifications" in current page administration
    And I click on "Add a rule" "link"
    Then the field "Subject" matches value "We miss you, {firstname}"
    And the field "Enable the rule" matches value "1"
    And I set the field "Rule name" to "Default enabled"
    And I press "Save changes"
    And I should see "The rule \"Default enabled\" was created and is enabled."
    And I should see "Enabled" in the "Default enabled" "table_row"

  Scenario: Unknown variables and invalid days are rejected
    Given I am on the "Course 1" course page logged in as teacher1
    When I navigate to "Notifications" in current page administration
    And I click on "Add a rule" "link"
    And I set the following fields to these values:
      | Rule name | Broken rule      |
      | Days      | 0                |
      | Subject   | Hello {username} |
      | Message   | Hello            |
    And I press "Save changes"
    Then I should see "Enter a whole number from 1 to 3650."
    And I should see "Unknown variable: {username}"

  Scenario: An activity rule needs an activity of the course with completion
    Given I am on the "Course 1" course page logged in as teacher1
    When I navigate to "Notifications" in current page administration
    And I click on "Add a rule" "link"
    And I set the following fields to these values:
      | Rule name       | Reading reminder                   |
      | Notify students | who have not completed an activity |
      | Days            | 3                                  |
      | Activity        | Read first                         |
      | Subject         | Please read {activityname}         |
      | Message         | Open {activityurl}                 |
    And I press "Save changes"
    Then I should see "The rule \"Reading reminder\" was created and is enabled."
    And I should see "\"Read first\" not completed 3 days after it becomes available" in the "Reading reminder" "table_row"

  Scenario: Deleting a rule asks for confirmation
    Given I am on the "Course 1" course page logged in as teacher1
    When I navigate to "Notifications" in current page administration
    And I click on "Add a rule" "link"
    And I set the following fields to these values:
      | Rule name | Temporary rule |
      | Subject   | Hello          |
      | Message   | Hello there    |
    And I press "Save changes"
    And I click on "Delete" "link" in the "Temporary rule" "table_row"
    Then I should see "Delete the rule \"Temporary rule\"?"
    And I press "Delete"
    And I should see "The rule \"Temporary rule\" and its delivery history were deleted."
    And "Temporary rule" "table_row" should not exist

  Scenario: The preview shows the message with the teacher's own data and sends nothing
    Given I am on the "Course 1" course page logged in as teacher1
    When I navigate to "Notifications" in current page administration
    And I click on "Add a rule" "link"
    And I set the following fields to these values:
      | Rule name | Template example  |
      | Subject   | Hello {firstname} |
      | Message   | Dear {fullname}   |
    And I press "Save changes"
    And I click on "Preview" "link" in the "Template example" "table_row"
    Then I should see "Hello Teacher"
    And I should see "Dear Teacher One"
    And I should see "Preview generated with the course \"Course 1\" and the user \"Teacher One\""
    And I should see "Nothing is sent."

  Scenario: Teachers without the manage capability cannot change rules
    Given the following "permission overrides" exist:
      | capability                    | permission | role           | contextlevel | reference |
      | local/sis_notification:manage | Prohibit   | editingteacher | Course       | C1        |
    When I am on the "Course 1" course page logged in as teacher2
    And I navigate to "Notifications" in current page administration
    Then I should see "Notification history"
    And I should not see "Add a rule"

  Scenario: Students do not see the notifications option
    When I am on the "Course 1" course page logged in as student1
    Then "Notifications" "link" should not exist in current page administration

  Scenario: Editing the text of an active three-day rule preserves the delay
    Given I am on the "Course 1" course page logged in as teacher1
    When I navigate to "Notifications" in current page administration
    And I click on "Add a rule" "link"
    And I set the following fields to these values:
      | Rule name | Three day reminder |
      | Days      | 3                  |
      | Subject   | Initial subject    |
      | Message   | Please return      |
    And I press "Save changes"
    And I click on "Edit" "link" in the "Three day reminder" "table_row"
    And I set the following fields to these values:
      | Subject | Updated subject |
      | Message | Updated message |
    And I press "Save changes"
    Then I should see "The rule \"Three day reminder\" was saved."
    And I should see "3 days" in the "Three day reminder" "table_row"
