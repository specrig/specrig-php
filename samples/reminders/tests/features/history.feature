Feature: What was reminded

  Scenario: The last reminder of an order is shown
    Given order 7 was reminded of 120
    When someone asks for the reminder of order 7
    Then they see a reminder of 120

  Scenario: An order never reminded of has no reminder
    When someone asks for the reminder of order 8
    Then they are told the order was never reminded of

  Scenario: The reminders are told to come back while their database is down
    Given order 7 was reminded of 120
    And the reminders' database is down
    When someone asks for the reminder of order 7
    Then they are told to come back later
