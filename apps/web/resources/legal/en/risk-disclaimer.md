---
title: 'Risk Score Disclaimer'
version: '1.0'
effective_date: '2026-10-24'
summary_of_changes: 'Initial version.'
requires_acceptance: false
---

<!-- template-notice -->

> **The score shows risk signals; it is not a guarantee. Independent verification is required.**

This disclaimer explains what the risk score and the risk signals of {{product.name}} mean and what they do not mean. It is provided by {{company.name}} and forms part of the Terms of Service at {{product.url}}/legal/terms.

## 1. What the risk score is

1.1. For each payment request {{product.name}} compares the facts it can observe (the payee bank details, the vendor master data, the sender and reply-to addresses, the sender domain, the wording of the message, the amount and the history of earlier requests) with a published, versioned set of rules.

1.2. Each rule that matches produces a **risk signal** with an explanation, for example "bank details differ from the verified account", "sender domain looks like the vendor's domain" or "the message asks for urgency and secrecy". The signals are added up to a score from 0 to 100 and a level: low, medium, high or critical. Some signals set a minimum level on their own.

1.3. The score is calculated by deterministic rules, not by a language model. The rules version and the facts used are stored with each assessment, so the same request with the same facts always receives the same score.

## 2. What the risk score is not

2.1. **It is not a guarantee.** A low score does not prove that a request is genuine, that the payee is who they claim to be or that a payment is safe. A fraudster may use a compromised genuine mailbox, a genuine invoice template and an account that has not yet been reported.

2.2. **It is not an accusation.** A high or critical score does not prove fraud and does not establish that any person or company acted unlawfully. Vendors change banks, staff change e-mail providers and urgent payments are sometimes legitimate.

2.3. **It is not a payment control.** {{product.name}} never holds, blocks, delays or moves money and has no access to your bank accounts. Statuses in the Service do not prevent a payment in your bank.

2.4. **It is not professional advice.** The score and the assistant's explanations are not legal, financial, tax or security advice.

## 3. Independent verification is required

Before you pay a request that changes bank details, adds a new payee, changes an employee's salary account or demands an urgent payment, confirm it through a channel you already trust:

1. **Stop.** Do not pay and do not reply to the message that asked for the payment.
2. **Call.** Call the vendor or the employee on a number that you obtained independently: from the signed contract, from the vendor master data verified earlier or from your HR records. Never use a phone number, link or contact from the message itself.
3. **Confirm.** Confirm who requested the change and the last four digits of the new account, and record the outcome in {{product.name}}.

The Service records the verification and the decision in the evidence ledger. The person who approves the payment remains responsible for the decision.

## 4. Where this disclaimer is shown

This disclaimer, or a short form of it, accompanies every risk score: on the case file of a payment request, in evidence packets and journals, in e-mails and Telegram messages about requests that need a decision, and on the risk widgets of the dashboard.

## 5. Statistics about prevented fraud

Amounts shown as "prevented" are estimates based on requests that your team rejected after a failed verification. They do not prove that a loss would have occurred and are not a guarantee of future results.

## 6. Liability

Our responsibility for the Service is set out in the Terms of Service, in particular sections 8.5, 8.6 and 12.5. Nothing in this disclaimer limits liability that cannot be limited by law.

## 7. Contact

Questions about how the score works: {{product.support_email}}. Report a suspected fraud attempt to your bank and, where appropriate, to the police.
