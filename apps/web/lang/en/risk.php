<?php

declare(strict_types=1);

return [
    'disclaimer' => 'The score shows risk signals found. It does not mean the payment is safe. Independent verification is required.',

    'signals' => [
        'BANK_DETAILS_CHANGED' => [
            'title' => 'Bank details differ from the confirmed account',
            'explanation' => 'The payee account ending :new_last4 is not the account ending :previous_last4 that your team confirmed for this vendor.',
            'advice' => 'Call the vendor on the official number from your records, never on a number from this message, and confirm the change.',
        ],
        'BANK_ACCOUNT_BLOCKLISTED' => [
            'title' => 'Account is on your blocked list',
            'explanation' => 'The account ending :last4 was blocked earlier in your workspace.',
            'advice' => 'Do not pay. Reject or cancel the request and inform the person who sent it to you.',
        ],
        'BANK_ACCOUNT_SEEN_OTHER_VENDOR' => [
            'title' => 'Account belongs to a different payee',
            'explanation' => 'The account ending :last4 is confirmed for another vendor in your records.',
            'advice' => 'Check which vendor the account really belongs to before going further.',
        ],
        'BANK_ACCOUNT_UNVERIFIED' => [
            'title' => 'Account has not been confirmed yet',
            'explanation' => 'The account ending :last4 is in your records but nobody has confirmed it by callback.',
            'advice' => 'Confirm the account with the vendor on the official number.',
        ],
        'BANK_ACCOUNT_FIRST_USE' => [
            'title' => 'First payment to this account',
            'explanation' => 'This vendor has no confirmed account yet; this would be the first payment to the account ending :last4.',
            'advice' => 'For larger amounts confirm the account by callback before the first payment.',
        ],
        'BANK_COUNTRY_CHANGED' => [
            'title' => 'Bank country changed',
            'explanation' => 'The bank country changed from :previous_country to :new_country.',
            'advice' => 'Ask the vendor why the bank moved to another country.',
        ],
        'BANK_HOLDER_MISMATCH' => [
            'title' => 'Account holder does not match the vendor',
            'explanation' => 'The account holder name is only :similarity% similar to the vendor name.',
            'advice' => 'Make sure the money goes to the vendor itself and not to a third party.',
        ],
        'BANK_RECENT_CHANGE' => [
            'title' => 'Bank details changed recently',
            'explanation' => 'The vendor account was changed only :days_ago days ago.',
            'advice' => 'Repeated changes in a short time deserve an extra callback.',
        ],
        'INVALID_ACCOUNT_CHECKSUM' => [
            'title' => 'Account number fails validation',
            'explanation' => 'The check digits of the account ending :last4 are not valid.',
            'advice' => 'The number may be mistyped or invented. Compare it with the original document.',
        ],
        'PAYROLL_ACCOUNT_CHANGE' => [
            'title' => 'Payroll account change',
            'explanation' => 'Requests to change a salary account are a common way to divert wages.',
            'advice' => 'Confirm the change with the employee in person or on their known number.',
        ],
        'EMPLOYEE_DENIED_CHANGE' => [
            'title' => 'Employee says they did not ask for this',
            'explanation' => 'The employee answered that they did not request the payroll change.',
            'advice' => 'Do not change the account. Reject the request and alert the employee.',
        ],
        'SENDER_DOMAIN_LOOKALIKE' => [
            'title' => 'Sender domain imitates a known domain',
            'explanation' => 'The domain :domain resembles :resembles (technique: :technique).',
            'advice' => 'Treat the message as an impersonation attempt until confirmed by phone.',
        ],
        'SENDER_DOMAIN_UNKNOWN_FOR_VENDOR' => [
            'title' => 'Unusual sender domain for this vendor',
            'explanation' => 'The message came from :domain, which is not one of the domains on file for this vendor.',
            'advice' => 'Check whether the vendor really uses this address.',
        ],
        'REPLY_TO_MISMATCH' => [
            'title' => 'Replies go to a different domain',
            'explanation' => 'The message is from :from_domain but replies go to :reply_to_domain.',
            'advice' => 'Do not reply to the message. Contact the vendor through known channels.',
        ],
        'FREE_EMAIL_PROVIDER' => [
            'title' => 'Sent from a free e-mail service',
            'explanation' => 'The address uses :domain, a free mailbox anyone can register.',
            'advice' => 'Businesses rarely send payment instructions from free mailboxes. Confirm by phone.',
        ],
        'DISPLAY_NAME_IMPERSONATION' => [
            'title' => 'Sender name imitates a manager',
            'explanation' => 'The display name ":display_name" matches a manager in your company, but the address is external (:domain).',
            'advice' => 'Ask the manager directly whether they sent this request.',
        ],
        'EMAIL_AUTH_FAILED' => [
            'title' => 'E-mail authentication failed',
            'explanation' => 'Authentication results: SPF :spf, DKIM :dkim, DMARC :dmarc.',
            'advice' => 'The message may be forged. Confirm through a known channel.',
        ],
        'PUNYCODE_DOMAIN' => [
            'title' => 'Domain uses look-alike characters',
            'explanation' => 'The domains :domains are written with international characters that can imitate Latin letters.',
            'advice' => 'Compare the address letter by letter with the vendor domain on file.',
        ],
        'DOMAIN_RECENTLY_REGISTERED' => [
            'title' => 'Domain registered very recently',
            'explanation' => 'The domain :domain was registered :age_days days ago.',
            'advice' => 'New domains are often created for fraud. Confirm by phone.',
        ],
        'DOMAIN_YOUNG' => [
            'title' => 'Domain is less than six months old',
            'explanation' => 'The domain :domain was registered :age_days days ago.',
            'advice' => 'Check that the vendor really moved to this domain.',
        ],
        'DOMAIN_NO_DMARC' => [
            'title' => 'Domain has no DMARC protection',
            'explanation' => 'The domain :domain publishes no DMARC policy, so forged mail is easier.',
            'advice' => 'Rely on the callback rather than on the sender address.',
        ],
        'DOMAIN_INTEL_UNAVAILABLE' => [
            'title' => 'Domain facts unavailable',
            'explanation' => 'Registration date and mail protection of :domain could not be checked.',
            'advice' => 'Missing facts are not a good sign either way; rely on the callback.',
        ],
        'URGENCY_LANGUAGE' => [
            'title' => 'Pressure to act fast',
            'explanation' => 'The message pushes for speed: :phrases.',
            'advice' => 'Urgency is a classic fraud lever. Follow your normal process.',
        ],
        'SECRECY_LANGUAGE' => [
            'title' => 'Request for secrecy',
            'explanation' => 'The message asks to keep things quiet: :phrases.',
            'advice' => 'Legitimate payments do not need secrecy. Involve a colleague.',
        ],
        'CALLBACK_DISCOURAGED' => [
            'title' => 'Discourages a phone call',
            'explanation' => 'The message tries to avoid a call: :phrases.',
            'advice' => 'Call anyway, on the official number from your records.',
        ],
        'AUTHORITY_PRESSURE' => [
            'title' => 'Refers to a manager to add pressure',
            'explanation' => 'The message invokes authority: :phrases.',
            'advice' => 'Check with the named manager directly.',
        ],
        'CHANGE_REQUEST_LANGUAGE' => [
            'title' => 'Announces new bank details',
            'explanation' => 'The text talks about changed payment details: :phrases.',
            'advice' => 'Any change of bank details needs a callback on the official number.',
        ],
        'UNUSUAL_PAYMENT_METHOD' => [
            'title' => 'Unusual payment method',
            'explanation' => 'The text mentions an unusual way to pay: :phrases.',
            'advice' => 'Gift cards, crypto and personal cards are typical for fraud.',
        ],
        'PHONE_NOT_IN_MASTER' => [
            'title' => 'Phone numbers in the message are not in your records',
            'explanation' => 'The message contains numbers ending :phones_last4 that do not belong to this vendor.',
            'advice' => 'Do not use these numbers. Call only the official number from your records.',
        ],
        'NEW_VENDOR' => [
            'title' => 'New or unknown vendor',
            'explanation' => 'The payee is not in your records or was added recently without any paid request.',
            'advice' => 'Check the vendor through independent sources before the first payment.',
        ],
        'DUPLICATE_INVOICE' => [
            'title' => 'Invoice number already used',
            'explanation' => 'The same vendor already has request :reference with this invoice number.',
            'advice' => 'Make sure this invoice is not paid twice.',
        ],
        'DUPLICATE_SUSPECT' => [
            'title' => 'Possible duplicate',
            'explanation' => 'Request :reference has the same vendor and amount and was created recently.',
            'advice' => 'Compare both requests before going further.',
        ],
        'AMOUNT_ANOMALY' => [
            'title' => 'Amount is higher than usual',
            'explanation' => 'The amount is more than twice the typical payment to this vendor.',
            'advice' => 'Check that the amount matches what was ordered.',
        ],
        'AMOUNT_ANOMALY_SEVERE' => [
            'title' => 'Amount is far higher than usual',
            'explanation' => 'The amount is more than five times the typical payment to this vendor.',
            'advice' => 'Confirm the amount with the vendor and with whoever ordered the goods or services.',
        ],
        'ROUND_AMOUNT_WIRE' => [
            'title' => 'Round amount for an urgent transfer',
            'explanation' => 'Urgent transfers with round amounts are typical for fraud.',
            'advice' => 'Ask what exactly the payment is for.',
        ],
        'CURRENCY_CHANGED' => [
            'title' => 'Unusual currency',
            'explanation' => 'This vendor usually invoices in :expected, but this request is in :actual.',
            'advice' => 'Ask the vendor why the currency changed.',
        ],
        'DUE_DATE_PRESSURE' => [
            'title' => 'Very short payment deadline',
            'explanation' => 'Only :business_days business days remain until the due date.',
            'advice' => 'A short deadline is no reason to skip the checks.',
        ],
        'QR_PAYEE_MISMATCH' => [
            'title' => 'QR code details differ from the text',
            'explanation' => 'The payment details in the QR code do not match the details printed in the document.',
            'advice' => 'Pay only after confirming which details are correct.',
        ],
        'QR_ONLY_DETAILS' => [
            'title' => 'Details only in a QR code',
            'explanation' => 'The bank details were found only in a QR code and could not be cross-checked with the text.',
            'advice' => 'Compare the details with your vendor records.',
        ],
        'FX_RATE_MISSING' => [
            'title' => 'No exchange rate',
            'explanation' => 'There is no exchange rate for :currency, so the strictest approval rules apply.',
            'advice' => 'Add an exchange rate in the policy settings.',
        ],
        'EXTRACTION_LOW_CONFIDENCE' => [
            'title' => 'Some fields were hard to read',
            'explanation' => 'Fields read with low confidence: :fields.',
            'advice' => 'Check these fields against the original document.',
        ],
        'CALLBACK_CONTACT_RECENTLY_CHANGED' => [
            'title' => 'Callback number changed recently',
            'explanation' => 'The vendor callback number ending :last4 was added or changed on :changed_at.',
            'advice' => 'A recently changed number cannot confirm a bank details change. Use another known contact.',
        ],
        'CALLBACK_PHONE_SHARED' => [
            'title' => 'Callback number shared with other vendors',
            'explanation' => 'The callback number ending :last4 is also used by :other_vendors other vendors.',
            'advice' => 'Check that the number really belongs to this vendor.',
        ],
        'PIPELINE_INCOMPLETE' => [
            'title' => 'Not all checks finished',
            'explanation' => 'Some automatic checks did not complete, so a callback is required.',
            'advice' => 'Review the details manually and confirm by phone.',
        ],
        'OCR_LANGUAGE_MISSING' => [
            'title' => 'Russian text recognition was unavailable',
            'explanation' => 'Scanned text was read without the Russian language pack and may be incomplete.',
            'advice' => 'Check all fields against the original document.',
        ],
    ],
];
