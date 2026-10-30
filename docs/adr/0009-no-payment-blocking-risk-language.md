# ADR-0009 — No payment initiation or blocking; "risk, not safety" language policy

- Status: Accepted (2026-10-22)
- Related: PLAN §2, §9 (R1), §10; DOMAIN_CONTRACT §0, §2.18

## Context
Automatic blocking of payments would need bank integration, legal review and possibly licensing; claiming that a payment is safe would
create liability and false confidence.

## Decision
- ScamPause never moves, holds or blocks money. "Approved" means approved **by the customer's people**, recorded with evidence; the payment
  itself happens in the customer's bank.
- Copy policy enforced by tests: no "safe/guaranteed" wording in any channel; the lowest level is "Few signals" with a tooltip stating it is
  not a guarantee; marketing speaks about "risk signals", "independent verification" and "proof".
- Bank-detail and payroll changes always require independent callback verification, regardless of score.

## Consequences
- Positive: low regulatory surface for MVP; honest positioning; clear legal story.
- Negative: less "automagic" than a payment platform; value is demonstrated through prevented-amount reports and evidence.

## Alternatives considered
- Integrate with banks to hold payments — deferred to v2 after legal review and a bank partnership.
