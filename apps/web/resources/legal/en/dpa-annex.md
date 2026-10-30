---
title: 'DPA Annex: Processing Details for Payment Checks'
version: '1.0'
effective_date: '2026-10-24'
summary_of_changes: 'Initial version.'
requires_acceptance: false
---

<!-- template-notice -->

This annex supplements Annex 1 of the Data Processing Agreement at {{product.url}}/legal/dpa between {{company.name}} (the "Processor") and the customer that owns the workspace (the "Controller"). It describes the processing of personal data performed when {{product.name}} checks payment requests. In case of conflict, the Data Processing Agreement prevails.

## 1. Data subjects

| Category        | Description                                                                                                              |
| --------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Users           | the Controller's members who create, verify, approve or audit payment requests                                           |
| Employees       | employees of the Controller whose salary account details are changed or who receive payroll change alerts                |
| Vendor contacts | staff of the Controller's vendors and contractors recorded as callback contacts or spoken to during verification calls   |
| Payees          | vendors, contractors and employees named in payment requests, including sole traders                                     |
| E-mail senders  | persons whose e-mails and attachments are forwarded to the Service for checking, including persons impersonating vendors |

## 2. Categories of personal data

| Category                        | Examples                                                                                         | Protection                                                                             |
| ------------------------------- | ------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------- |
| Identification and contact data | names, roles, e-mail addresses, phone numbers, Telegram identifiers of members                   | phone numbers encrypted at rest; masked to the last four digits outside the case file  |
| Bank and salary data            | payee bank account numbers, routing codes, IBANs, account holders, salary accounts of employees  | encrypted at rest; masked to the last four digits; audited, rate-limited reveal        |
| Payment request content         | invoices, e-mails and attachments, extracted text, amounts, due dates, invoice numbers           | files encrypted with AES-256-GCM before storage; text encrypted at rest                |
| Verification records            | number dialled, name of the person spoken to, outcome, checklist, notes                          | encrypted at rest; outside the hash-chained evidence material, so they can be redacted |
| Decisions and evidence          | approvals, rejections with reasons, evidence ledger entries (identifiers and masked values only) | hash-chained, immutable for members                                                    |
| Offence-related labels          | bank accounts marked as confirmed fraud, which may relate to sole traders                        | stored as a fingerprint and the last four digits; never shared across customers        |
| Usage and security data         | IP addresses, user agents, timestamps of actions                                                 | audit log with retention limits                                                        |

Special categories of personal data are not intended to be processed. The Controller must not upload them unless it has a lawful basis and has assessed the risk.

## 3. Nature and purposes of processing

Storage, text extraction and optical character recognition, comparison of bank details with verified accounts, analysis of sender domains using public DNS and RDAP data, rule-based risk scoring, routing for approval, sending of notifications by e-mail and Telegram, recording of verifications and decisions in the evidence ledger, generation of evidence packets and journals, export and deletion — for the purpose of helping the Controller prevent payment fraud. The Processor does not make payment decisions and does not move funds.

## 4. Automated processing

Risk scores are produced automatically by deterministic rules. Every decision to pay, reject or cancel a request is taken by a member of the Controller; the Service does not take decisions producing legal or similarly significant effects for data subjects.

## 5. Retention

| Data                                                                     | Default                                         | Configurable range      |
| ------------------------------------------------------------------------ | ----------------------------------------------- | ----------------------- |
| Uploaded files, previews, extracted text, e-mail recipients and subjects | per plan: 90, 730 or 2555 days                  | 30 days to plan maximum |
| Evidence ledger, verification records, approvals, risk assessments       | {{retention.scampause_evidence}} days (7 years) | 5 to 10 years           |
| Evidence packets and journal downloads                                   | {{retention.scampause_exports}} days            | 1 to 90 days            |
| Approval notifications and payroll alerts                                | {{retention.scampause_notifications}} days      | 90 to 730 days          |
| Public domain lookups (not personal data)                                | {{retention.scampause_domain_intel}} days       | fixed                   |
| Assistant logs                                                           | {{retention.ai_queries}} days                   | per plan                |

When the evidence retention period ends, the oldest part of the ledger is deleted and a checkpoint entry keeps the remaining ledger verifiable. Members referenced in the ledger are pseudonymised instead of deleted when their accounts are deleted. When a workspace is deleted, all of the above is deleted after the grace period of {{retention.workspace_deletion_grace}} days.

## 6. Data subject requests

The Service provides the Controller with tools to find, export and redact the data of a vendor contact, an employee or an e-mail sender. Redaction removes names, phone numbers, the number dialled, verification notes and e-mail addresses from stored records, while the hash-chained evidence of decisions remains verifiable.

## 7. Sub-processors

The sub-processors that may process the data above, and the conditions under which each is used, are listed at {{product.url}}/legal/subprocessors. RDAP services and the DNS resolver receive only domain names. AI providers receive redacted text only when the Controller allows external AI providers.
