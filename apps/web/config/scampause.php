<?php

declare(strict_types=1);

return [
    'rules_version' => '2026.10.2',

    'lexicon_version' => 'lex-2026.10.2',

    'fingerprint_key' => env('SCAMPAUSE_FINGERPRINT_KEY'),

    'file_key' => env('SCAMPAUSE_FILE_KEY'),

    'file_key_id' => env('SCAMPAUSE_FILE_KEY_ID', 'k1'),

    'previous_file_keys' => array_filter(explode(',', (string) env('SCAMPAUSE_PREVIOUS_FILE_KEYS', ''))),

    'domain_intel_driver' => env('DOMAIN_INTEL_DRIVER', 'fake'),

    'seed_documents_path' => env('SEED_DOCUMENTS_PATH'),

    'demo_workspace_slug' => env('DEMO_WORKSPACE_SLUG'),

    'demo_seed' => (bool) env('SCAMPAUSE_DEMO_SEED', env('APP_ENV') !== 'testing'),

    'dev_routes' => (bool) env('DEV_ROUTES', false),

    'disk' => env('SCAMPAUSE_DISK', 'local'),

    'currencies' => [
        'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'RUB' => 2, 'KZT' => 2, 'CHF' => 2, 'JPY' => 0, 'CNY' => 2,
        'CAD' => 2, 'AUD' => 2, 'PLN' => 2, 'CZK' => 2, 'SEK' => 2, 'NOK' => 2, 'DKK' => 2, 'TRY' => 2,
        'AED' => 2, 'UAH' => 2, 'BYN' => 2, 'GEL' => 2, 'AMD' => 2, 'UZS' => 2, 'INR' => 2, 'ILS' => 2,
    ],

    'money_format' => [
        'en' => [
            'default' => ['symbol' => null, 'position' => 'prefix', 'group' => ',', 'decimal' => '.', 'space_before_symbol' => false],
            'USD' => ['symbol' => '$', 'position' => 'prefix', 'group' => ',', 'decimal' => '.', 'space_before_symbol' => false],
            'EUR' => ['symbol' => '€', 'position' => 'prefix', 'group' => ',', 'decimal' => '.', 'space_before_symbol' => false],
            'GBP' => ['symbol' => '£', 'position' => 'prefix', 'group' => ',', 'decimal' => '.', 'space_before_symbol' => false],
            'JPY' => ['symbol' => '¥', 'position' => 'prefix', 'group' => ',', 'decimal' => '.', 'space_before_symbol' => false],
        ],
        'ru' => [
            'default' => ['symbol' => null, 'position' => 'suffix', 'group' => "\u{00A0}", 'decimal' => ',', 'space_before_symbol' => true],
            'USD' => ['symbol' => '$', 'position' => 'suffix', 'group' => "\u{00A0}", 'decimal' => ',', 'space_before_symbol' => true],
            'EUR' => ['symbol' => '€', 'position' => 'suffix', 'group' => "\u{00A0}", 'decimal' => ',', 'space_before_symbol' => true],
            'GBP' => ['symbol' => '£', 'position' => 'suffix', 'group' => "\u{00A0}", 'decimal' => ',', 'space_before_symbol' => true],
            'RUB' => ['symbol' => '₽', 'position' => 'suffix', 'group' => "\u{00A0}", 'decimal' => ',', 'space_before_symbol' => true],
            'JPY' => ['symbol' => '¥', 'position' => 'suffix', 'group' => "\u{00A0}", 'decimal' => ',', 'space_before_symbol' => true],
            'KZT' => ['symbol' => '₸', 'position' => 'suffix', 'group' => "\u{00A0}", 'decimal' => ',', 'space_before_symbol' => true],
        ],
    ],

    'free_mail_domains' => [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com', 'icloud.com',
        'me.com', 'aol.com', 'proton.me', 'protonmail.com', 'gmx.com', 'gmx.de', 'zoho.com', 'mail.ru', 'inbox.ru',
        'list.ru', 'bk.ru', 'yandex.ru', 'ya.ru', 'yandex.com', 'rambler.ru',
    ],

    'uploads' => [
        'max_files' => 5,
        'max_file_kb' => 15360,
        'max_total_kb' => 40960,
        'mimes' => ['application/pdf', 'image/png', 'image/jpeg', 'image/webp', 'message/rfc822'],
        'extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'eml'],
        'preview_max_pages' => 10,
        'preview_max_kb' => 4096,
        'attachment_max_count' => 10,
    ],

    'intake' => [
        'pipeline_timeout_minutes' => 10,
        'pipeline_max_republish' => 2,
        'extraction_text_max_chars' => 200000,
        'source_excerpt_chars' => 4000,
        'download_ttl_minutes' => 5,
    ],

    'risk' => [
        'weights' => [
            'BANK_DETAILS_CHANGED' => 40,
            'BANK_ACCOUNT_BLOCKLISTED' => 60,
            'BANK_ACCOUNT_SEEN_OTHER_VENDOR' => 30,
            'BANK_ACCOUNT_UNVERIFIED' => 20,
            'BANK_ACCOUNT_FIRST_USE' => 15,
            'BANK_COUNTRY_CHANGED' => 15,
            'BANK_HOLDER_MISMATCH' => 20,
            'BANK_RECENT_CHANGE' => 10,
            'INVALID_ACCOUNT_CHECKSUM' => 20,
            'PAYROLL_ACCOUNT_CHANGE' => 35,
            'SENDER_DOMAIN_LOOKALIKE' => 35,
            'SENDER_DOMAIN_UNKNOWN_FOR_VENDOR' => 15,
            'REPLY_TO_MISMATCH' => 20,
            'FREE_EMAIL_PROVIDER' => 10,
            'DISPLAY_NAME_IMPERSONATION' => 25,
            'EMAIL_AUTH_FAILED' => 25,
            'PUNYCODE_DOMAIN' => 15,
            'DOMAIN_RECENTLY_REGISTERED' => 25,
            'DOMAIN_YOUNG' => 10,
            'DOMAIN_NO_DMARC' => 5,
            'URGENCY_LANGUAGE' => 10,
            'SECRECY_LANGUAGE' => 20,
            'CALLBACK_DISCOURAGED' => 20,
            'AUTHORITY_PRESSURE' => 10,
            'CHANGE_REQUEST_LANGUAGE' => 15,
            'UNUSUAL_PAYMENT_METHOD' => 15,
            'PHONE_NOT_IN_MASTER' => 10,
            'NEW_VENDOR' => 15,
            'DUPLICATE_INVOICE' => 25,
            'DUPLICATE_SUSPECT' => 10,
            'AMOUNT_ANOMALY' => 15,
            'AMOUNT_ANOMALY_SEVERE' => 25,
            'ROUND_AMOUNT_WIRE' => 5,
            'CURRENCY_CHANGED' => 10,
            'DUE_DATE_PRESSURE' => 10,
            'QR_PAYEE_MISMATCH' => 30,
            'QR_ONLY_DETAILS' => 10,
            'FX_RATE_MISSING' => 0,
            'EXTRACTION_LOW_CONFIDENCE' => 0,
            'DOMAIN_INTEL_UNAVAILABLE' => 0,
            'EMPLOYEE_DENIED_CHANGE' => 50,
            'CALLBACK_CONTACT_RECENTLY_CHANGED' => 15,
            'CALLBACK_PHONE_SHARED' => 20,
            'PIPELINE_INCOMPLETE' => 0,
            'OCR_LANGUAGE_MISSING' => 0,
        ],
        'content_cap' => 40,
        'floors' => [
            ['score' => 50, 'any' => ['BANK_DETAILS_CHANGED', 'PAYROLL_ACCOUNT_CHANGE', 'QR_PAYEE_MISMATCH', 'BANK_ACCOUNT_SEEN_OTHER_VENDOR']],
            ['score' => 75, 'all_of_groups' => [['SENDER_DOMAIN_LOOKALIKE'], ['CHANGE_REQUEST_LANGUAGE', 'BANK_DETAILS_CHANGED']]],
            ['score' => 90, 'any' => ['BANK_ACCOUNT_BLOCKLISTED', 'EMPLOYEE_DENIED_CHANGE']],
        ],
        'verification_signals' => [
            'BANK_DETAILS_CHANGED', 'BANK_ACCOUNT_UNVERIFIED', 'PAYROLL_ACCOUNT_CHANGE', 'QR_PAYEE_MISMATCH',
            'BANK_ACCOUNT_SEEN_OTHER_VENDOR', 'EMPLOYEE_DENIED_CHANGE',
        ],
        'verification_forcing_signals' => ['PIPELINE_INCOMPLETE', 'OCR_LANGUAGE_MISSING'],
        'verification_kinds' => ['bank_details_change', 'payroll_change', 'urgent_wire'],
        'holder_similarity_threshold' => 70,
        'recent_change_days' => 30,
        'new_vendor_days' => 30,
        'duplicate_invoice_days' => 365,
        'duplicate_suspect_days' => 14,
        'anomaly_history_days' => 365,
        'anomaly_min_history' => 3,
        'anomaly_sample' => 6,
        'domain_recent_days' => 30,
        'domain_young_days' => 180,
        'low_confidence' => 60,
        'due_pressure_business_days' => 2,
    ],

    'bank_change_signals' => [
        'BANK_DETAILS_CHANGED', 'PAYROLL_ACCOUNT_CHANGE', 'QR_PAYEE_MISMATCH', 'BANK_ACCOUNT_SEEN_OTHER_VENDOR',
        'BANK_ACCOUNT_BLOCKLISTED', 'EMPLOYEE_DENIED_CHANGE',
    ],

    'web_only_signals' => [
        'BANK_DETAILS_CHANGED', 'PAYROLL_ACCOUNT_CHANGE', 'QR_PAYEE_MISMATCH', 'BANK_ACCOUNT_SEEN_OTHER_VENDOR',
        'BANK_ACCOUNT_BLOCKLISTED', 'EMPLOYEE_DENIED_CHANGE', 'BANK_ACCOUNT_FIRST_USE', 'BANK_ACCOUNT_UNVERIFIED',
        'BANK_HOLDER_MISMATCH',
    ],

    'vendor_matching' => [
        'tax_id' => 95,
        'sender_domain' => 90,
        'name_min' => 60,
        'name_cap' => 85,
        'bank_verified' => 80,
        'bank_pending' => 60,
        'auto_match_min' => 70,
        'runner_up_margin' => 15,
    ],

    'countries' => [
        'US', 'CA', 'GB', 'IE', 'DE', 'FR', 'ES', 'IT', 'NL', 'BE', 'AT', 'CH', 'SE', 'NO', 'DK', 'FI', 'PL', 'CZ', 'PT', 'LT',
        'LV', 'EE', 'CY', 'GR', 'TR', 'AE', 'IL', 'RU', 'KZ', 'BY', 'AM', 'GE', 'UZ', 'UA', 'CN', 'JP', 'IN', 'AU',
    ],

    'seed' => [
        'demo' => (bool) env('SCAMPAUSE_SEED_DEMO', true),
    ],

    'copy' => [
        'forbidden' => [
            'en' => [
                'safe to pay', 'payment is safe', 'looks safe', 'is legitimate', 'verified as genuine', 'guaranteed',
                'we guarantee', '100% protection', 'fully protected',
            ],
            'ru' => [
                'безопасно оплачивать', 'платёж безопасен', 'безопасный платёж', 'можно смело платить', 'гарантируем',
                'гарантированно', '100% защита', 'полная защита',
            ],
        ],
        'context_required' => [
            'en' => ['on hold', 'stopped', 'before it happens'],
            'ru' => ['на удержании', 'остановлен'],
        ],
        'negations' => ['not', 'no', 'never', "doesn't", 'does not', "isn't", 'не', 'нет', 'никогда'],
        'negation_window' => 40,
    ],
];
