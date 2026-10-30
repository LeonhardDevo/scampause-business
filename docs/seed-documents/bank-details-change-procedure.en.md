---
title: "Procedure: New or Changed Vendor Bank Details (Stop. Call. Confirm.)"
family: bank-details-change-procedure
locale: en
department: procedure
roles: []
tags: ["lang:en", "family:bank-details-change-procedure", "bank-details", "verification", "callback", "procedure"]
version: "1.3"
effective_date: 2026-06-15
workspaces: ["northwind", "kedr"]
---

# Procedure: new or changed vendor bank details

**Applies to:** everyone who prepares, verifies or approves payments for the Company, including external bookkeepers.
**Rule of thumb:** a change of bank details is the single most common way small companies lose money to fraud. Every change is treated
as suspicious until it is confirmed through a channel the requester does not control.

## When this procedure applies

- A vendor (or someone writing on behalf of a vendor) asks to pay to a new or "updated" account.
- An invoice shows bank details that differ from the ones in the vendor master.
- A payment would go to an account we have never paid this vendor before (including the first payment to a new vendor).
- A QR code on an invoice contains payment details that differ from the text of the invoice.
- ScamPause shows a bank-class signal: "Bank details changed", "Bank country changed", "Account used by another vendor", "Account on the block list".

## The three steps: Stop. Call. Confirm.

### 1. Stop
- Do not pay, do not change anything in the online bank, do not reply to the e-mail or message.
- Forward the e-mail as an attachment (.eml) or upload the invoice to ScamPause so the request is recorded.
- Do not click links or open "updated invoice" attachments you were not expecting.
- Urgency, secrecy and "I can't talk right now" are warning signs, not reasons to hurry.

### 2. Call
- Call the vendor using **only** the official number recorded in ScamPause (vendor → Callback contacts). These numbers come from the signed
  contract, the vendor's website reached by typing the address yourself, or an in-person meeting.
- **Never** use a phone number, e-mail address or link from the request itself, from the e-mail signature, or from the new invoice.
  ScamPause marks such numbers as "Do not use".
- If there is no official number on record, stop and ask the Finance Lead to add one from the contract before going further.
- You place the call. A call you receive "from the vendor" does not count.

### 3. Confirm
Ask the person you reach to confirm, without reading the details to them first:
1. that their company really changed its bank details, and why;
2. the **last four digits** of the new account and the bank name and country;
3. the last four digits of the **old** account they used before;
4. the invoice number and amount;
5. the name and position of the person who sent the request.

If anything does not match, or the vendor says they did not request a change, treat the request as fraud.

## Record the result in ScamPause

Open the request → **Verification** → choose the official contact you called → method "Callback to a known number" → outcome
(Confirmed / Denied / Unreachable / Inconclusive) → tick the checklist items you confirmed → name of the person you spoke to → time of the call.
If the new account replaces the old one, select "Replaces account …". The record cannot be edited later and becomes part of the evidence packet.

- **Unreachable:** try again later; do not pay. The request waits in "Awaiting verification".
- **Denied:** choose "Fraud confirmed" or "Details mismatch". The request is rejected and the account is added to the block list.

## Approvals

After a confirmed callback the request goes to approval. A bank-detail change always needs **two approvers**, one of them the Owner or the
Finance Lead. Approvers must open the case file in the web app; bank-detail changes cannot be approved from Telegram.

## If money was already sent

1. Call the Company's bank immediately and ask for a recall of the transfer — the first hours matter.
2. Tell the Owner and the Finance Lead.
3. Report the incident to the police or the national cybercrime reporting service, and keep the original e-mail (.eml).
4. Record everything in ScamPause and reject the request with reason "Fraud confirmed".

## Notes

- A small "test payment" does not prove who owns an account; it is not a substitute for the callback.
- Changes become effective only after verification and approval. Until then the old verified account stays in use.
- ScamPause shows risk signals and keeps evidence; it does not guarantee that a vendor's request is genuine. The callback is what protects us.
