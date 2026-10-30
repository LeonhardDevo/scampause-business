---
title: "Urgent Payment Requests and Business E-mail Compromise: Red Flags and Rules"
family: urgent-payment-and-bec-red-flags
locale: en
department: training
roles: []
tags: ["lang:en", "family:urgent-payment-and-bec-red-flags", "bec", "red-flags", "urgent-wire", "training"]
version: "1.0"
effective_date: 2026-05-01
workspaces: ["northwind", "kedr"]
---

# Urgent payment requests and business e-mail compromise (BEC)

**Audience:** everyone at the Company who can see invoices or talk to the bank, including external bookkeepers.

## The four scams we see most

1. **Fake invoice.** An invoice for goods or services we never ordered, or a copy of a real invoice with different bank details.
2. **Vendor impersonation.** "Your supplier" writes from a look-alike domain (one letter or a hyphen different) and asks to update bank details.
3. **Payroll diversion.** "An employee" asks to change the salary account, usually from a personal mailbox, shortly before payday.
4. **Executive impersonation ("CEO fraud").** "The owner" or "the director" asks for an urgent, confidential wire — often while
   "travelling" or "in a meeting" and unable to talk.

A newer variant is the **QR invoice**: a "new contract" or "new lease" invoice with a QR code that pays someone else.

## Red flags

| Category | Examples in English | Examples in Russian |
|---|---|---|
| Urgency | "urgent", "today", "before 3 pm", "final notice" | «срочно», «сегодня же», «до конца дня», «последнее напоминание» |
| Secrecy | "keep this between us", "confidential", "don't tell" | «конфиденциально», «никому не говорите», «между нами» |
| Avoiding a call | "don't call, I'm in a meeting", "my phone is broken", "reply by e-mail only" | «не звоните», «я на совещании», «телефон не работает», «отвечайте только по почте» |
| Authority | "the CEO asked", "on behalf of the owner" | «директор поручил», «по поручению руководителя» |
| Payment change | "new bank details", "we changed banks", "updated remittance" | «новые реквизиты», «сменили банк», «изменились реквизиты» |
| Unusual method | gift cards, crypto, personal account, foreign account for a local vendor | подарочные карты, криптовалюта, перевод на карту физлица |
| Technical | sender domain differs slightly from the real one; Reply-To goes to a free mailbox; the display name is right but the address is not | |

One red flag is enough to stop. ScamPause detects these signals automatically and shows them with an explanation, but a message without
signals is not automatically genuine.

## Rules

1. **No exceptions for urgency or seniority.** Nobody at the Company, including the Owner, will ask you to bypass approvals. A request to
   bypass them is itself a red flag.
2. **Verify through a channel you choose.** Call the person on the number you already have (contract, vendor master, HR file, company
   directory). Do not use numbers, links or addresses from the message.
3. **Second approver.** Every payment has at least one approver who did not prepare it; larger or unusual payments have two.
4. **Record it.** Upload the request to ScamPause and record the verification — this protects you as well as the Company.
5. **Report.** Tell the Finance Lead about every suspicious request, even if you did not pay. Rejected requests help us block the accounts
   and domains used by fraudsters.

## If you think you already paid a fraudster

Call the bank immediately and ask for a recall; inform the Owner and the Finance Lead; keep the original e-mail; report the incident to the
police or the national cybercrime reporting service. Speed matters: banks can sometimes freeze funds if they are told within hours.
