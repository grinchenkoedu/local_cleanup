@local @local_cleanup
Feature: Review the files reports
  In order to reclaim disk space on an overloaded site
  As a site administrator
  I need the clean-up reports to open for the people allowed to see them and refuse everyone else

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | manager1 | Manager   | One      | manager1@example.com |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | manager1 | manager | System       |           |

  Scenario: A site administrator can open the files report
    Given I log in as "admin"
    When I visit "/local/cleanup/files.php"
    Then I should see "Files"
    And I should see "Nothing to show"
    And I should not see "you do not currently have permissions"

  Scenario: A site administrator can open the unlinked files report
    Given I log in as "admin"
    When I visit "/local/cleanup/ghost.php"
    Then I should see "Unlinked files"
    And I should see "Nothing to show"
    And I should not see "you do not currently have permissions"

  Scenario: A manager holding the view capability can open the files report
    Given I log in as "manager1"
    When I visit "/local/cleanup/files.php"
    Then I should see "Nothing to show"
    And I should not see "you do not currently have permissions"

  Scenario: A manager cannot delete without the deletion capability
    Given I log in as "manager1"
    When I visit "/local/cleanup/remove.php?id=1"
    Then I should see "you do not currently have permissions"

  Scenario: A teacher cannot reach the files report
    Given I log in as "teacher1"
    When I visit "/local/cleanup/files.php"
    Then I should see "you do not currently have permissions"

  Scenario: A teacher cannot reach the unlinked files report
    Given I log in as "teacher1"
    When I visit "/local/cleanup/ghost.php"
    Then I should see "you do not currently have permissions"
