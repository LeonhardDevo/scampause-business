---
title: "Procedure: Changing an Employee's Salary Account"
family: payroll-change-procedure
locale: en
department: procedure
roles: []
tags: ["lang:en", "family:payroll-change-procedure", "payroll", "employees", "verification", "procedure"]
version: "1.1"
effective_date: 2026-03-01
workspaces: ["northwind"]
---

# Procedure: changing an employee's salary account

**Company:** Northwind Coffee Roasters LLC · **Owner of the procedure:** Maria Kovaleva (Finance Lead, also responsible for payroll)

Payroll diversion is a common scam: a fraudster writes from a personal-looking e-mail ("this is Jordan, I changed banks, please update my
direct deposit before Friday") and the next salary goes to the fraudster. This procedure makes sure salary accounts change only when the
employee really asked for it.

## 1. How employees request a change
- In person, by filling in the "Direct deposit change" form with the Finance Lead and showing a bank document with the new account; or
- by sending the form from their **work** account and then confirming it by phone (see step 3).
- Requests from personal e-mail addresses, text messages or social networks are never accepted on their own.

## 2. What the Finance Lead does on receiving any request
1. **Stop.** Do not change the payroll system yet. Upload the request to ScamPause as a payment request of kind "Payroll change"
   (forward the e-mail as .eml if it came by e-mail).
2. ScamPause automatically notifies the employee through a channel we already know — their linked Telegram account or the e-mail address
   in the HR file, **never the address the request came from** — and asks "Was it you?".
3. **Call.** Call the employee on the phone number in the HR file (ScamPause: employee payee → callback contact), or meet in person.
4. **Confirm** the last four digits of the new account, the bank name, and that the employee personally made the request.
5. Record the verification in ScamPause (method, number used, outcome, checklist). The record is permanent evidence.

An answer "Yes, it was me" in Telegram is useful but is **not** enough on its own: a phone or mailbox can be compromised. A callback or an
in-person confirmation is always required. An answer "No, it wasn't me" flags the request as critical; reject it as fraud.

## 3. Approval
A payroll account change needs **two approvers, one of them the Owner** (Payment Approval Policy, section 3). It cannot be approved from Telegram.

## 4. When the change takes effect
- Payroll runs every second Friday. A change approved by Wednesday 12:00 applies to the next run; later changes apply to the run after.
- Urgency ("please do it before Friday") is not a reason to skip any step.
- After the change, the employee receives a confirmation through the known channel.

## 5. If a salary was already paid to a wrong account
Call the bank immediately to request a recall, inform the Owner, pay the employee from company funds as required by law, and report the
fraud. Record everything in ScamPause.
