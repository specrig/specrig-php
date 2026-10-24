Feature: Placing and paying

  Scenario: A placed order is announced and invoiced by the worker
    Given billing accepts invoices
    When the customer places order 9 of 80
    Then order 9 of 80 is kept unpaid
    And the placement of order 9 for 80 is announced
    And billing is asked to invoice nothing yet
    When the shop's worker runs
    Then billing is asked to invoice order 9 for 80

  Scenario: An order without an amount is refused
    When the customer places order 9 of 0
    Then they are told the order is invalid

  Scenario: A paid order has nothing left to pay
    Given order 7 of 120 is placed
    And billing has reported order 7 paid
    When the customer opens order 7
    Then they see 0 to pay

  Scenario: A payment report the shop cannot read is dropped
    When billing reports a payment nobody can read
    Then the report is dropped

  Scenario: An order is cancelled by hand
    Given order 7 of 120 is placed
    When someone cancels order 7 by hand
    Then order 7 is gone
    And the cancellation of order 7 is announced
