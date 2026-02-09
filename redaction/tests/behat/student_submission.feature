@mod @mod_redaction
Feature: Student submits work in a redaction activity
  As a student
  I need to write and submit my work in a Writing activity
  So that my teacher can evaluate it

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
      | redaction | Essay Assignment | Write an essay about your city. | C1     | redaction1 | 0                |

  Scenario: Student views the Writing activity
    When I log in as "student1"
    And I am on the "Essay Assignment" "redaction activity" page
    Then I should see "Essay Assignment"
    And I should see "My writing"

  @javascript
  Scenario: Student writes and saves a draft
    Given I log in as "student1"
    And I am on the "Essay Assignment" "redaction activity" page
    When I follow "My writing"
    Then I should see "Draft"

  @javascript
  Scenario: Student submits their writing
    Given I log in as "student1"
    And I am on the "Essay Assignment" "redaction activity" page
    When I follow "My writing"
    And I set the field "redaction_title" to "My City Essay"
    And I set the field "redaction_content" to "This is my essay about my beautiful city."
    And I press "Submit writing"
    Then I should see "Submitted"

  Scenario: Different students have independent submissions
    Given I log in as "student1"
    And I am on the "Essay Assignment" "redaction activity" page
    And I follow "My writing"
    And I log out
    When I log in as "student2"
    And I am on the "Essay Assignment" "redaction activity" page
    And I follow "My writing"
    Then I should see "Draft"

  Scenario: Student cannot access grading page
    Given I log in as "student1"
    When I am on the "Essay Assignment" "redaction activity" page
    Then I should not see "Grading"
