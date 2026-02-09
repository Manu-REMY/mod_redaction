@mod @mod_redaction
Feature: Create a redaction activity
  As a teacher
  I need to create a Writing activity in my course
  So that students can write and submit their work

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  @javascript
  Scenario: Teacher creates a Writing activity with default settings
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    When I add a "Writing" activity to course "Course 1" section "1"
    And I set the following fields to these values:
      | Activity name | My Writing Assignment |
      | Description   | Write an essay about history. |
    And I press "Save and return to course"
    Then I should see "My Writing Assignment"

  @javascript
  Scenario: Teacher creates a Writing activity with AI enabled
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    When I add a "Writing" activity to course "Course 1" section "1"
    And I set the following fields to these values:
      | Activity name        | AI Evaluated Essay  |
      | Description          | Write about science. |
      | Enable AI evaluation | 1                   |
    And I press "Save and return to course"
    Then I should see "AI Evaluated Essay"

  @javascript
  Scenario: Teacher creates a Writing activity with group submission
    Given the following "groups" exist:
      | name    | course | idnumber |
      | Group 1 | C1     | G1       |
      | Group 2 | C1     | G2       |
    And the following "group members" exist:
      | user     | group |
      | student1 | G1    |
    And I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    When I add a "Writing" activity to course "Course 1" section "1"
    And I set the following fields to these values:
      | Activity name    | Group Essay       |
      | Description      | Collaborative writing. |
      | Group submission | 1                 |
    And I press "Save and return to course"
    Then I should see "Group Essay"

  Scenario: Student can see the Writing activity on the course page
    Given the following "activities" exist:
      | activity   | name              | intro                | course | idnumber    |
      | redaction  | Writing Activity  | Write about nature.  | C1     | redaction1  |
    When I log in as "student1"
    And I am on "Course 1" course homepage
    Then I should see "Writing Activity"

  Scenario: Teacher views the Writing activity home page
    Given the following "activities" exist:
      | activity   | name              | intro                | course | idnumber    |
      | redaction  | My Writing Task   | Essay instructions.  | C1     | redaction1  |
    When I log in as "teacher1"
    And I am on the "My Writing Task" "redaction activity" page
    Then I should see "My Writing Task"
    And I should see "Instructions"
    And I should see "Grading"
