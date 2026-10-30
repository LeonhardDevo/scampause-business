<?php

return [
    'meta' => [
        'title' => 'ScamPause Business — check suspicious payments before you pay',
        'description' => 'Forward a suspicious invoice or e-mail. ScamPause finds changed bank details, look-alike senders and pressure tactics, routes the payment to a second approver and keeps proof of who checked what.',
    ],

    'brand' => [
        'name' => 'ScamPause Business',
    ],

    'nav' => [
        'label' => 'Main',
        'home' => 'ScamPause Business home',
        'product' => 'Product',
        'features' => 'Product',
        'how' => 'How it works',
        'pricing' => 'Pricing',
        'security' => 'Security',
        'faq' => 'FAQ',
        'login' => 'Sign in',
        'get_started' => 'Start free',
        'dashboard' => 'Open dashboard',
        'open_menu' => 'Open menu',
        'menu' => 'Menu',
    ],

    'auth' => [
        'eyebrow' => 'Payment verification console',
        'headline' => 'Stop. Call. Confirm.',
        'subhead' => 'Every payment that looks different gets a pause, a call to a number you already know and a second pair of eyes.',
        'preview_label' => 'Example of a ScamPause case file',
        'steps' => [
            'stop' => [
                'title' => 'Stop',
                'body' => 'Changed bank details and look-alike senders are flagged before anyone opens the banking app.',
            ],
            'call' => [
                'title' => 'Call',
                'body' => 'Use the official number from the contract — never the one in the message.',
            ],
            'confirm' => [
                'title' => 'Confirm',
                'body' => 'A second approver decides in Telegram or the web, and the record is kept.',
            ],
        ],
        'disclaimer' => 'ScamPause shows risk signals; it is not a guarantee.',
    ],

    'mock' => [
        'label' => 'Demo case file with sample data',
        'reference' => 'SP-00042',
        'vendor' => 'Blue Ridge Packaging',
        'amount' => 'Amount',
        'due' => 'Due today',
        'exhibit_sender' => 'Exhibit A — Sender',
        'exhibit_bank' => 'Exhibit B — Bank details',
        'exhibit_score' => 'Risk signals',
        'exhibit_approval' => 'Exhibit D — Approvals',
        'from' => 'From',
        'reply_to' => 'Reply-To',
        'subject' => 'Subject',
        'sender_name' => 'Karen Whitfield',
        'sender_domain' => 'blueridge-pack.com',
        'sender_domain_lead' => 'blueridge',
        'sender_domain_odd' => '-',
        'sender_domain_tail' => 'pack.com',
        'reply_to_value' => 'billing@blueridge-pack.co',
        'subject_value' => 'Updated banking details — invoice INV-2291',
        'body_line' => 'Please send today’s payment to our new account below.',
        'body_pressure' => 'It’s urgent — I’m in meetings all day, no need to call.',
        'lookalike_note' => 'Looks like blueridgepack.com · one character added',
        'scanning' => 'Checking the request',
        'checked' => 'Checked against vendor history',
        'bank' => [
            'account' => 'Account',
            'bank' => 'Bank',
            'country' => 'Country',
            'account_old' => '•••• 4417',
            'account_new' => '•••• 8839',
            'account_old_sr' => 'account ending 4417',
            'account_new_sr' => 'account ending 8839',
            'bank_old' => 'First Citizens',
            'bank_new' => 'Northbeam Digital',
            'country_old' => 'United States',
            'country_new' => 'United States',
        ],
        'signals' => [
            'lookalike' => 'Look-alike domain',
            'reply_to' => 'Reply-To mismatch',
            'dont_call' => '“Don’t call” pressure',
            'new_account' => 'New bank account',
            'urgency' => 'Same-day urgency',
            'amount' => 'Amount above usual',
        ],
        'advice' => 'Stop. Call Karen Whitfield on the number from your contract.',
        'official_number' => 'Official number · Supply Agreement §9.2',
        'telegram' => [
            'bot' => 'ScamPause',
            'title' => 'Approval needed · SP-00042',
            'body' => 'Blue Ridge Packaging · $18,400.00 · bank details changed',
            'verified' => 'Callback confirmed by Maria',
            'approve' => 'Approve',
            'reject' => 'Reject',
            'web_only' => 'Bank details changed — review in the web case file',
            'open' => 'Open case file',
        ],
        'approvals' => [
            'owner' => 'Owner',
            'finance' => 'Finance',
            'approved' => 'Approved',
            'waiting' => 'Waiting',
            'policy' => '2 of 2 approvals · owner required',
        ],
        'evidence' => [
            'created' => 'Request created',
            'verified' => 'Callback verified',
            'approved' => 'Approved by owner',
            'integrity' => 'Chain verified 03:00',
        ],
    ],

    'hero' => [
        'eyebrow' => 'For small businesses and their bookkeepers',
        'title' => 'Stop. Call. Confirm.',
        'title_tail' => 'Before the money leaves.',
        'subtitle' => 'Forward a suspicious invoice or e-mail. ScamPause finds changed bank details, look-alike senders and pressure tactics, routes the payment to a second approver in Telegram, and keeps proof of who checked what.',
        'cta_primary' => 'Check an invoice free',
        'cta_secondary' => 'See how it works',
        'micro' => [
            'bank' => 'Works with any bank',
            'languages' => 'RU / EN',
            'money' => 'We never move your money',
        ],
    ],

    'scams' => [
        'eyebrow' => 'The problem',
        'title' => 'Four ways small companies lose money',
        'subtitle' => 'None of them needs malware. They need one busy person and one convincing message.',
        'items' => [
            'invoice' => [
                'title' => 'Fake invoice',
                'example' => '“Attached is the overdue invoice for March, please settle today.”',
            ],
            'vendor' => [
                'title' => 'Vendor impersonation',
                'example' => '“We changed banks — please use the new account from now on.”',
            ],
            'payroll' => [
                'title' => 'Payroll diversion',
                'example' => '“Hi, it’s Jordan. Can you send my salary to my new card this month?”',
            ],
            'ceo' => [
                'title' => '“Urgent” CEO wire and QR invoices',
                'example' => '“I’m boarding, wire it now and keep it quiet.” · “Scan the QR to pay the new contract.”',
            ],
        ],
        'source' => 'Business e-mail compromise is among the costliest cybercrimes reported to the FBI.',
    ],

    'how' => [
        'eyebrow' => 'How it works',
        'title' => 'Forward → Check → Call & approve',
        'subtitle' => 'A five-minute routine that fits between meetings — and leaves a record your auditor can read.',
        'steps' => [
            'forward' => [
                'label' => '01 · Forward',
                'title' => 'Send it in',
                'body' => 'Upload the PDF, photo or .eml — or type it in.',
            ],
            'check' => [
                'label' => '02 · Check',
                'title' => 'Compare with what you know',
                'body' => 'Rules compare it with the vendor’s history: bank details, domain, amount, urgency.',
            ],
            'approve' => [
                'label' => '03 · Call & approve',
                'title' => 'Verify independently',
                'body' => 'Call the official number from your contract, record the result, and let a second person approve — in Telegram or the web.',
            ],
        ],
        'upload' => [
            'drop' => 'Drop the invoice or forwarded e-mail',
            'types' => 'PDF · PNG · JPG · WEBP · EML',
            'file' => 'invoice-INV-2291.pdf',
            'extracted' => 'Extracted: amount, payee, account, due date',
        ],
        'pipeline' => [
            'extract' => 'Extract',
            'domains' => 'Domains',
            'bank' => 'Bank details',
            'score' => 'Score',
        ],
        'callback' => [
            'title' => 'Callback',
            'contact' => 'Karen Whitfield · Accounts',
            'number' => '+1 (828) •••-0142',
            'number_sr' => 'phone number ending 0142',
            'source' => 'From the Supply Agreement, not from the e-mail',
            'outcome' => 'Vendor did not request a change',
            'do_not_use' => 'Do not use numbers from the message',
        ],
    ],

    'bento' => [
        'eyebrow' => 'Product',
        'title' => 'Evidence first. Verdicts never.',
        'subtitle' => 'Every screen shows what looks different, why it matters and what to do next.',
        'diff' => [
            'title' => 'See exactly what changed',
            'body' => 'The request is compared with the verified account on record. Masked by default, revealed only with permission — and every reveal is logged.',
            'reveal' => 'Full numbers: permission required · every reveal logged',
            'history' => 'Account history',
            'history_verified' => 'Verified by callback · March 2025',
            'history_rejected' => 'Rejected attempt · SP-00017',
        ],
        'sender' => [
            'title' => 'Sender forensics',
            'body' => 'Look-alike domains, Reply-To tricks, fresh domains.',
            'auth' => 'SPF · DKIM · DMARC',
            'auth_fail' => 'DMARC fail',
            'age' => 'Domain first seen 6 days ago',
        ],
        'telegram' => [
            'title' => 'Telegram approvals',
            'body' => 'Two taps for routine payments, full review for risky ones.',
        ],
        'policy' => [
            'title' => 'Your policy, executable',
            'body' => 'Type an amount and see what your rules require.',
            'amount_label' => 'Payment amount, USD',
            'result_label' => 'Your rules require',
            'approvers' => '{1} :count approver|[2,*] :count approvers',
            'owner' => 'owner required',
            'callback' => 'callback verification',
            'none' => 'no extra checks',
            'rule' => 'If amount ≥ :amount → :approvers',
        ],
        'evidence' => [
            'title' => 'Evidence you can export',
            'body' => 'Hash-chained log and PDF packets.',
        ],
        'assistant' => [
            'title' => 'Assistant over your documents',
            'body' => 'Answers come from your contracts and policies — with sources.',
            'question' => '“What’s the official number in the Blue Ridge contract?”',
            'answer' => 'Call Accounts on the number in §9.2 of the Supply Agreement.',
            'source' => 'Supply Agreement BRP-2025-014 · §9.2',
        ],
        'prevented' => [
            'title' => 'Prevented, measured',
            'body' => 'Track caught bank changes and amounts that never left — with sample data here.',
            'chart_label' => 'Sample chart: prevented amount by month rising from 0 to 18,400 dollars over six months',
        ],
    ],

    'ledger' => [
        'eyebrow' => 'Not a black box',
        'title' => 'Rules you can read. Signals you can explain.',
        'subtitle' => 'Each signal has a name, a reason and a weight. The score is their sum — nothing hidden.',
        'statement' => 'We never tell you a payment is safe. We show what looks different and who confirmed it.',
        'columns' => [
            'code' => 'Code',
            'signal' => 'Signal',
            'why' => 'Why it matters',
            'points' => 'Points',
        ],
        'rows' => [
            [
                'code' => 'BANK_CHANGED',
                'signal' => 'Bank account differs from the verified one',
                'why' => 'The most common way money is redirected',
                'points' => '35',
            ],
            [
                'code' => 'DOMAIN_LOOKALIKE',
                'signal' => 'Sender domain looks like a known vendor',
                'why' => 'One added or swapped character is easy to miss',
                'points' => '30',
            ],
            [
                'code' => 'REPLY_TO_MISMATCH',
                'signal' => 'Replies go to a different domain',
                'why' => 'Your answer reaches someone else',
                'points' => '20',
            ],
            [
                'code' => 'NO_CALL_LANGUAGE',
                'signal' => '“Don’t call”, “keep it quiet”',
                'why' => 'Pressure against verification is a red flag on its own',
                'points' => '20',
            ],
            [
                'code' => 'DOMAIN_YOUNG',
                'signal' => 'Domain registered days ago',
                'why' => 'Fraud domains are usually fresh',
                'points' => '15',
            ],
            [
                'code' => 'AMOUNT_ANOMALY',
                'signal' => 'Amount far above this vendor’s usual',
                'why' => 'Unusual size deserves a second look',
                'points' => '10',
            ],
        ],
    ],

    'bookkeepers' => [
        'eyebrow' => 'For bookkeepers',
        'title' => 'One login, every client’s payment checks',
        'subtitle' => 'Prepare and verify payments for every client from one place. Each client approves in their own workspace, so responsibility stays clear.',
        'roles' => [
            'prepare' => [
                'title' => 'You prepare and verify',
                'body' => 'Upload requests, run the callback, attach the evidence.',
            ],
            'approve' => [
                'title' => 'Your client approves',
                'body' => 'In Telegram or the web, with the evidence in front of them.',
            ],
            'record' => [
                'title' => 'Everyone keeps the record',
                'body' => 'Who prepared, who called, who approved — with time and method.',
            ],
        ],
        'switcher' => 'Client workspaces',
        'clients' => [
            ['name' => 'Northwind Coffee Roasters', 'meta' => '3 awaiting approval'],
            ['name' => 'Harbor Dental Studio', 'meta' => '1 needs a callback'],
            ['name' => 'Pine & Oak Joinery', 'meta' => 'All decided'],
        ],
        'queue' => 'Today’s queue',
    ],

    'security' => [
        'eyebrow' => 'Security & privacy',
        'title' => 'Built for the data you would never e-mail',
        'subtitle' => 'Bank details, contracts and approvals are handled like evidence: encrypted, masked and accounted for.',
        'items' => [
            'encryption' => [
                'title' => 'Encrypted bank data',
                'body' => 'Account numbers are encrypted at rest, masked by default, and every reveal is audited.',
            ],
            'trackers' => [
                'title' => 'No trackers, no third-party scripts',
                'body' => 'Only strictly necessary cookies. Fonts and icons ship with the app.',
            ],
            'chain' => [
                'title' => 'Tamper-evident evidence',
                'body' => 'Verifications and approvals are written to a hash-chained ledger checked every night.',
            ],
            'residency' => [
                'title' => 'Data residency options',
                'body' => 'Data residency options on request, including a deployment in Russia.',
            ],
        ],
        'cta' => 'Read how we protect your data',
        'disclaimer' => 'Risk disclaimer',
    ],

    'pricing_teaser' => [
        'eyebrow' => 'Pricing',
        'title' => 'Cheaper than one wrong transfer',
        'subtitle' => 'Start free. Upgrade when you check more payments or need more approvers.',
        'safety' => 'Limits never block a verification or a rejection.',
        'cta' => 'Compare all plans',
    ],

    'faq' => [
        'eyebrow' => 'FAQ',
        'title' => 'Straight answers',
        'items' => [
            [
                'q' => 'Does ScamPause block payments?',
                'a' => 'No. ScamPause never moves or holds money — you pay in your own bank. It pauses the decision: risky requests need a callback and a second approver before your team marks them approved.',
            ],
            [
                'q' => 'Is this a guarantee against fraud?',
                'a' => 'No. ScamPause shows risk signals and records how the request was verified. A low score means few signals were found, not that a payment is safe. Independent verification is always required.',
            ],
            [
                'q' => 'Do we need Telegram?',
                'a' => 'No. Everything works in the web app and by e-mail. Telegram is optional for people who want two-tap approvals on their phone.',
            ],
            [
                'q' => 'We are a two-person company. Does this work for us?',
                'a' => 'Yes. Solo mode keeps the checklist and the evidence even when one person runs payments, and you can invite a second approver (a partner or your bookkeeper) whenever you are ready.',
            ],
            [
                'q' => 'Which languages are supported?',
                'a' => 'Russian and English everywhere: the interface, e-mails, Telegram messages, PDF evidence packets and assistant answers. Each person picks their own language.',
            ],
            [
                'q' => 'Where is our data stored?',
                'a' => 'In the region of your deployment. Bank details are encrypted, and data residency options — including Russia — are available on request.',
            ],
        ],
    ],

    'final_cta' => [
        'title' => 'Your next “urgent” invoice deserves five minutes.',
        'subtitle' => 'Set up in fifteen minutes: add your vendors, their official numbers and who approves what.',
        'secondary' => 'See pricing',
    ],

    'footer' => [
        'legal' => 'Legal',
        'all_legal' => 'All documents',
        'contact' => 'Contact',
        'product' => 'Product',
        'legal' => 'Legal',
        'trust' => 'Trust',
        'privacy' => 'Privacy Policy',
        'terms' => 'Terms of Service',
        'security' => 'Security',
        'tagline' => 'Stop. Call. Confirm. — before every payment that looks different.',
        'disclaimer' => 'ScamPause shows risk signals; it is not a guarantee.',
        'copyright' => '© :year :name',
        'made_for' => 'Made for small businesses and their bookkeepers.',
        'preferences' => 'Preferences',
    ],

    'cookie' => [
        'text' => 'We only use strictly necessary cookies: session, security, language and theme. No tracking.',
        'link' => 'Cookie Policy',
        'dismiss' => 'Got it',
        'label' => 'Cookie notice',
    ],

    'pricing' => [
        'meta_title' => 'Pricing',
        'meta_description' => 'Plans for small businesses and bookkeepers: start free, upgrade for more payment checks, approvers and Telegram approvals.',
        'eyebrow' => 'Pricing',
        'title' => 'Pay for checks, not for panic',
        'subtitle' => 'Start free. Every plan keeps the Stop. Call. Confirm. routine and the evidence trail.',
        'plans_label' => 'Plans',
        'unavailable' => 'Plans are loading — please refresh the page in a moment.',
        'note' => 'Prices exclude taxes. Cancel or switch plans any time.',
        'payments' => 'Card payments via Stripe. ScamPause bills only its subscription and never processes your payments.',
        'compare_title' => 'Compare plans',
        'feature' => 'Feature',
        'safety' => 'Limits never block a verification, an approval or a rejection.',
        'faq_title' => 'Billing questions',
        'faq' => [
            [
                'q' => 'Is there a free trial of paid plans?',
                'a' => 'Paid plans start with a free trial. You are not charged until the trial ends, and you can cancel before then.',
            ],
            [
                'q' => 'How does yearly billing work?',
                'a' => 'Pay for 12 months up front at a discount. You can switch between monthly and yearly at any time; changes are prorated.',
            ],
            [
                'q' => 'What happens if we hit a plan limit?',
                'a' => 'New checks may pause until you upgrade, but verifications, approvals and rejections of requests already in progress always work.',
            ],
            [
                'q' => 'Is ScamPause a guarantee against fraud?',
                'a' => 'No. It shows risk signals and records independent verification. The decision to pay always stays with your team.',
            ],
            [
                'q' => 'Can we get an invoice for our company?',
                'a' => 'Yes. Invoices and receipts are available in the billing portal for every payment.',
            ],
        ],
    ],

    'security_page' => [
        'meta_title' => 'Security',
        'meta_description' => 'How ScamPause Business protects bank details, approvals and evidence: encryption, masking, audited reveals, four-eyes approvals, a hash-chained ledger and no third-party scripts.',
        'eyebrow' => 'Security',
        'title' => 'Handled like evidence',
        'subtitle' => 'We ask you to verify every payment that looks different. Here is how we protect the data you trust us with.',
        'pillars_label' => 'Security controls',
        'pillars' => [
            'data' => [
                'title' => 'Bank data protection',
                'body' => 'Account numbers and secrets are encrypted and shown only when needed.',
                'points' => [
                    ['text' => 'Encrypted at rest, masked to the last four digits by default'],
                    ['text' => 'Every reveal of a full number is recorded in the audit log'],
                    ['text' => 'Uploaded files are private, with expiring download links'],
                ],
            ],
            'access' => [
                'title' => 'Access control',
                'body' => 'Every person gets exactly the access their role needs.',
                'points' => [
                    ['text' => 'Two-factor authentication and passkeys'],
                    ['text' => 'Roles per workspace; the person who verifies cannot be the only approver'],
                    ['text' => 'Rate-limited sign-in and password confirmation for sensitive areas'],
                ],
            ],
            'payments' => [
                'title' => 'Payment workflow',
                'body' => 'Controls at the moments where fraud usually happens.',
                'points' => [
                    ['text' => 'Callback verification uses only official numbers on record'],
                    ['text' => 'Four-eyes approval for large, new or changed payments'],
                    ['text' => 'ScamPause never moves money and has no access to your bank'],
                ],
            ],
            'ai' => [
                'title' => 'Responsible assistant',
                'body' => 'The assistant answers from your approved documents and refuses rather than guesses.',
                'points' => [
                    ['text' => 'Answers cite their sources; low confidence means a refusal'],
                    ['text' => 'It can never mark a request as verified or approved'],
                    ['text' => 'Retrieved text is treated as data, never as instructions'],
                    ['text' => 'External model providers can be switched off per workspace'],
                ],
            ],
            'infra' => [
                'title' => 'Infrastructure',
                'body' => 'Defense in depth between every service.',
                'points' => [
                    ['text' => 'Signed, time-limited requests between internal services'],
                    ['text' => 'Verified, idempotent webhooks'],
                    ['text' => 'Strict Content Security Policy — no CDNs, trackers or remote fonts'],
                ],
            ],
            'audit' => [
                'title' => 'Evidence & accountability',
                'body' => 'You can always prove who did what, and when.',
                'points' => [
                    ['text' => 'Hash-chained evidence ledger with a nightly integrity check'],
                    ['text' => 'Append-only audit log with before/after values'],
                    ['text' => 'PDF evidence packets for auditors and insurers'],
                ],
            ],
        ],
        'disclaimer_title' => 'Risk disclaimer',
        'disclaimer_body' => 'ScamPause shows risk signals found in a payment request and records how it was verified. It does not guarantee that a payment is legitimate or safe; independent verification through a channel you already trust is always required.',
        'disclosure_title' => 'Found a vulnerability?',
        'disclosure_body' => 'We appreciate responsible disclosure. Report security issues privately using the contact details in our Privacy Policy and give us reasonable time to fix them before going public.',
    ],

    'legal' => [
        'eyebrow' => 'Legal',
        'toc' => 'On this page',
        'index_title' => 'Legal & privacy',
        'index_description' => 'The terms that govern the service and how we protect the data you entrust to us. Every document is versioned; earlier versions stay available.',
        'meta_description' => ':title — current version, effective date and earlier versions.',
        'all_documents' => 'All legal documents',
        'effective' => 'Effective',
        'version' => 'Version',
        'versions' => 'Version history',
        'version_current' => ':version (current, :date)',
        'version_option' => ':version (:date)',
        'archived' => 'You are viewing an earlier version. The current version is :version.',
        'view_current' => 'View the current version',
        'print' => 'Print or save as PDF',
        'template_note' => 'Template — must be reviewed by a qualified lawyer for your jurisdiction before production use.',
        'security_hint' => 'Looking for how we protect your data or how to report a vulnerability? See',
        'blurbs' => [
            'terms' => 'Accounts, workspaces, subscriptions, acceptable use and liability.',
            'privacy' => 'What personal data we process, why, for how long and your rights.',
            'cookies' => 'The strictly necessary cookies we use — no tracking.',
            'dpa' => 'How we process your workspace data on your behalf (GDPR Art. 28).',
            'subprocessors' => 'Service providers that may process data and where.',
            'aup' => 'What you may not do with the service.',
            'ai' => 'What is sent to AI models, and how to keep processing local.',
            'consent_ru' => 'Consent to personal data processing under Russian Federal Law 152-FZ.',
            'risk_disclaimer' => 'What the risk score is and is not: signals, not a guarantee. Independent verification is required.',
            'data_subject_notice' => 'What is recorded about employees, vendor contacts and e-mail senders, and how they can object.',
            'dpa_annex' => 'Data subjects, data categories, purposes and retention of payment checks.',
            'dpia_template' => 'A data protection impact assessment template for customers.',
        ],
    ],
];
