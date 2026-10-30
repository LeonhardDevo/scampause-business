---
title: 'Data Protection Impact Assessment Template'
version: '1.0'
effective_date: '2026-10-24'
summary_of_changes: 'Initial version.'
requires_acceptance: false
---

<!-- template-notice -->

This template helps customers of {{product.name}} carry out a data protection impact assessment (DPIA) under Article 35 GDPR before they start checking payment requests with the Service. Checking payments can involve salary data of employees, phone numbers of vendor staff, the content of third-party e-mails, labels about suspected fraud and automated risk scoring, so a DPIA is recommended. {{company.name}} provides the information about the Service; the conclusions are the customer's. Replace every text in square brackets.

## 1. Description of the processing

| Item                   | Customer's answer                                                                                                        |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Controller and contact | [company name, privacy contact or DPO]                                                                                   |
| Purpose                | preventing payment fraud by checking payment requests before payment and recording verifications and approvals           |
| Payment types in scope | [vendor invoices, changes of bank details, payroll changes, urgent requests, other]                                      |
| Data subjects          | users, employees, vendor contacts, payees (including sole traders), e-mail senders (see {{product.url}}/legal/dpa-annex) |
| Data categories        | contact data, bank and salary data, e-mail content, verification records, decisions, fraud labels                        |
| Sources                | members, forwarded e-mails and uploads, vendor master data, public DNS and RDAP data                                     |
| Recipients             | members by role; sub-processors listed at {{product.url}}/legal/subprocessors                                            |
| Retention              | [files: chosen period; evidence: chosen period between 5 and 10 years; see the Privacy Policy]                           |
| Channels enabled       | [e-mail, Telegram bot, external AI providers: yes or no]                                                                 |

## 2. Necessity and proportionality

- **Legal basis:** [legitimate interest in preventing fraud and protecting funds; performance of employment contracts for payroll changes; legal obligations on internal control, if any]. Document the balancing test for legitimate interests.
- **Data minimisation:** bank details are masked to the last four digits for members; full details can be revealed only with a dedicated permission. Decide which roles get this permission: [roles].
- **Accuracy:** members record verification outcomes themselves; define who may record them and how they are trained: [procedure].
- **Transparency:** inform employees and vendor contacts, for example with the notice at {{product.url}}/legal/data-subject-notice: [how and when].
- **Rights:** use the data-subject tool to find, export or redact data of vendor contacts, employees and e-mail senders: [responsible person].

## 3. Risks and measures

| Risk to individuals                                                 | Measures provided by the Service                                                                                                            | Additional customer measures | Residual risk     |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------- | ----------------- |
| A genuine vendor or a sole trader is wrongly labelled as fraudulent | labels are visible only within the workspace, stored as fingerprint and last four digits, never shared across customers; removal is audited | [review procedure]           | [low/medium/high] |
| Disclosure of employees' salary accounts                            | encryption at rest, masking, audited and rate-limited reveal, no full numbers in notifications, logs or AI prompts                          | [access reviews]             | [ ]               |
| Excessive records about vendor staff                                | only the number dialled, the name of the person spoken to, the outcome and notes; no call audio; redaction tool                             | [note-taking guidance]       | [ ]               |
| Automated scoring treated as a decision about a person              | deterministic, explained rules; every payment decision taken by a member; disclaimer on every score                                         | [approval policy]            | [ ]               |
| Content of third-party e-mails kept too long                        | file retention by plan with automatic deletion of files and e-mail text                                                                     | [chosen retention]           | [ ]               |
| Data sent to AI providers                                           | external providers off by default; redaction of account numbers, phones and e-mail addresses; no training on customer data                  | [decision on the setting]    | [ ]               |
| Unauthorised access by members                                      | roles and permissions, separation of duties between requester and approver, two-factor authentication, audit log                            | [role assignment]            | [ ]               |
| Evidence altered after the fact                                     | hash-chained evidence ledger with verification; checkpoints when old entries are deleted                                                    | [periodic verification]      | [ ]               |

## 4. Consultation and sign-off

- Data protection officer's advice: [text, date].
- Views of data subjects or their representatives, where appropriate: [text].
- Prior consultation with the supervisory authority required: [yes/no, reasons].
- Approved by: [name, role, date]. Next review: [date].

Questions about the Service: {{company.privacy_email}}.
