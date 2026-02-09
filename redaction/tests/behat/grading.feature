@mod @mod_redaction
Feature: Teacher grades a submission in a redaction activity
  As a teacher
  I need to view and grade student submissions
  So that students receive feedback on their work

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity  | name             | intro                          | course | idnumber   | group_submission |
      | redaction | Graded Essay     | Write a short essay.           | C1     | redaction1 | 0                |

  Scenario: Teacher accesses the grading page
    Given I log in as "teacher1"
    When I am on the "Graded Essay" "redaction activity" page
    And I follow "Grading"
    Then I should see "Grading"

  Scenario: Teacher sees student list on grading page
    Given I log in as "teacher1"
    When I am on the "Graded Essay" "redaction activity" page
    And I follow "Grading"
    Then I should see "Student One"
    And I should see "Student Two"

  @javascript
  Scenario: Teacher grades a student submission
    Given I log in as "teacher1"
    And I am on the "Graded Essay" "redaction activity" page
    When I follow "Grading"
    And I click on "View submission" "link" in the "Student One" "table_row"
    And I set the field "grade" to "15"
    And I set the field "feedback" to "Good work, well structured essay."
    And I press "Save grade"
    Then I should see "Grade saved"

  Scenario: Teacher views submission status
    Given I log in as "teacher1"
    When I am on the "Graded Essay" "redaction activity" page
    And I follow "Grading"
    Then I should see "Not submitted"

  @javascript
  Scenario: Teacher can unlock a submitted work for editing
    Given I log in as "teacher1"
    And I am on the "Graded Essay" "redaction activity" page
    When I follow "Grading"
    And I click on "View submission" "link" in the "Student One" "table_row"
    And I click on "Unlock for editing" "button"
    Then I should see "Unlocked"

  Scenario: Teacher views instructions setup page
    Given I log in as "teacher1"
    When I am on the "Graded Essay" "redaction activity" page
    And I follow "Instructions"
    Then I should see "Activity title"
    And I should see "Detailed instructions"
    And I should see "Evaluation criteria"
