<?php

declare(strict_types=1);

return [
    'driver' => env('BILLING_DRIVER', 'fake'),

    'allow_fake_in_production' => (bool) env('BILLING_ALLOW_FAKE_IN_PRODUCTION', false),

    'currency' => env('BILLING_CURRENCY', 'USD'),

    'default_plan' => 'free',

    'active_statuses' => ['trialing', 'active', 'past_due'],

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    'trial_ending_notice_days' => 3,

    'gateways' => [
        'stripe' => 'App\Billing\StripeGateway',
        'yookassa' => 'App\Billing\YooKassa\YooKassaGateway',
        'fake' => 'App\Billing\Fake\FakeGateway',
    ],

    'gateway_requirements' => [
        'yookassa' => ['billing.yookassa.shop_id', 'billing.yookassa.secret_key'],
    ],

    'markets' => [
        'default_country' => env('BILLING_DEFAULT_COUNTRY', 'US'),
        'default_gateway' => 'stripe',
        'currency_gateways' => [
            'RUB' => 'yookassa',
        ],
        'country_gateways' => [
            'RU' => 'yookassa',
        ],
        'gateway_currencies' => [
            'stripe' => ['USD', 'EUR'],
            'yookassa' => ['RUB'],
            'fake' => ['USD', 'EUR', 'RUB'],
        ],
        'country_currencies' => [
            'RU' => 'RUB',
            'AT' => 'EUR', 'BE' => 'EUR', 'HR' => 'EUR', 'CY' => 'EUR', 'EE' => 'EUR', 'FI' => 'EUR',
            'FR' => 'EUR', 'DE' => 'EUR', 'GR' => 'EUR', 'IE' => 'EUR', 'IT' => 'EUR', 'LV' => 'EUR',
            'LT' => 'EUR', 'LU' => 'EUR', 'MT' => 'EUR', 'NL' => 'EUR', 'PT' => 'EUR', 'SK' => 'EUR',
            'SI' => 'EUR', 'ES' => 'EUR',
        ],
        'locale_currencies' => [
            'ru' => 'RUB',
        ],
        'countries' => [
            'US', 'GB', 'CA', 'AU', 'DE', 'FR', 'ES', 'IT', 'NL', 'BE', 'AT', 'IE', 'PT', 'FI', 'EE',
            'LV', 'LT', 'PL', 'CZ', 'SE', 'NO', 'DK', 'CH', 'RU', 'KZ', 'AM', 'GE', 'UZ', 'AE', 'TR',
            'IL', 'IN', 'SG', 'JP', 'BR', 'MX',
        ],
    ],

    'plans' => [
        'free' => [
            'highlighted' => false,
            'features' => ['seats', 'documents', 'assistant', 'telegram'],
            'limits' => [
                'seats' => 2,
                'documents' => 3,
                'ai_queries_monthly' => 50,
                'storage_mb' => 100,
                'payment_requests_monthly' => 15,
                'vendors' => 25,
                'approval_rules' => 2,
            ],
            'entitlements' => [
                'telegram_approvals' => false,
                'evidence_exports' => false,
                'domain_intel_live' => false,
                'file_retention_days' => 90,
            ],
            'prices' => [
                'USD' => ['month' => 0, 'year' => 0],
                'EUR' => ['month' => 0, 'year' => 0],
                'RUB' => ['month' => 0, 'year' => 0],
            ],
        ],
        'starter' => [
            'highlighted' => false,
            'trial_days' => 14,
            'features' => ['seats', 'documents', 'assistant', 'telegram', 'audit'],
            'limits' => [
                'seats' => 5,
                'documents' => 20,
                'ai_queries_monthly' => 300,
                'storage_mb' => 1024,
                'payment_requests_monthly' => 150,
                'vendors' => 150,
                'approval_rules' => 6,
            ],
            'entitlements' => [
                'telegram_approvals' => true,
                'evidence_exports' => true,
                'domain_intel_live' => false,
                'file_retention_days' => 730,
            ],
            'prices' => [
                'USD' => ['month' => 2900, 'year' => 29000],
                'EUR' => ['month' => 2700, 'year' => 27000],
                'RUB' => ['month' => 249000, 'year' => 2490000],
            ],
        ],
        'pro' => [
            'highlighted' => true,
            'trial_days' => 14,
            'features' => ['seats', 'documents', 'assistant', 'telegram', 'audit', 'priority_support'],
            'limits' => [
                'seats' => 15,
                'documents' => 100,
                'ai_queries_monthly' => 1500,
                'storage_mb' => 5120,
                'payment_requests_monthly' => 600,
                'vendors' => 1000,
                'approval_rules' => 25,
            ],
            'entitlements' => [
                'telegram_approvals' => true,
                'evidence_exports' => true,
                'domain_intel_live' => true,
                'file_retention_days' => 2555,
            ],
            'prices' => [
                'USD' => ['month' => 7900, 'year' => 79000],
                'EUR' => ['month' => 7500, 'year' => 75000],
                'RUB' => ['month' => 679000, 'year' => 6790000],
            ],
        ],
        'business' => [
            'highlighted' => false,
            'trial_days' => 14,
            'features' => ['seats', 'documents', 'assistant', 'telegram', 'audit', 'priority_support', 'sso'],
            'limits' => [
                'seats' => 50,
                'documents' => 500,
                'ai_queries_monthly' => 5000,
                'storage_mb' => 20480,
                'payment_requests_monthly' => 3000,
                'vendors' => null,
                'approval_rules' => null,
            ],
            'entitlements' => [
                'telegram_approvals' => true,
                'evidence_exports' => true,
                'domain_intel_live' => true,
                'file_retention_days' => 2555,
            ],
            'prices' => [
                'USD' => ['month' => 19900, 'year' => 199000],
                'EUR' => ['month' => 18900, 'year' => 189000],
                'RUB' => ['month' => 1699000, 'year' => 16990000],
            ],
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET') ?: null,
        'dev_webhook_secret' => 'whsec_test_local',
        'api_base' => env('STRIPE_API_BASE') ?: null,
        'catalog' => env('STRIPE_CATALOG') ?: 'sp',
        'automatic_tax' => (bool) env('STRIPE_AUTOMATIC_TAX', false),
        'tax_id_collection' => (bool) env('STRIPE_TAX_ID_COLLECTION', true),
        'price_cache_seconds' => 600,
        'max_network_retries' => 2,
    ],

    'yookassa' => [
        'shop_id' => env('YOOKASSA_SHOP_ID') ?: null,
        'secret_key' => env('YOOKASSA_SECRET_KEY') ?: null,
        'api_base' => env('YOOKASSA_API_BASE') ?: 'https://api.yookassa.ru/v3',
        'receipts' => (bool) env('YOOKASSA_RECEIPTS', false),
        'vat_code' => (int) env('YOOKASSA_VAT_CODE', 1),
        'tax_system_code' => is_numeric(env('YOOKASSA_TAX_SYSTEM_CODE')) ? (int) env('YOOKASSA_TAX_SYSTEM_CODE') : null,
        'timeout_seconds' => 15,
        'retry_hours' => (int) env('YOOKASSA_RETRY_HOURS', 24),
        'grace_days' => (int) env('YOOKASSA_GRACE_DAYS', 7),
    ],

    'fake' => [
        'session_ttl_minutes' => 60,
    ],
];
