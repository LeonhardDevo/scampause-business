<?php

declare(strict_types=1);

use App\Assistant\Actions\EscalateAction;
use App\Domain\Privacy\DomainExportProvider;
use App\Domain\Reports\OpenUrlAction;

return [
    'slug' => env('PRODUCT_SLUG', 'scampause-business'),

    'tagline' => env('PRODUCT_TAGLINE', 'Stop. Call. Confirm. — before every payment that looks different.'),

    'support_email' => env('PRODUCT_SUPPORT_EMAIL', 'support@example.com'),

    'telegram' => [
        'bot_username' => env('TELEGRAM_BOT_USERNAME') ?: null,
        'link_ttl_minutes' => (int) env('TELEGRAM_LINK_TTL_MINUTES', 15),
    ],

    'assistant' => [
        'actions' => [
            'escalate' => EscalateAction::class,
            'open_url' => OpenUrlAction::class,
        ],
    ],

    'privacy' => [
        'export_contributors' => [DomainExportProvider::class],
    ],

    'email' => [
        'primary' => env('PRODUCT_EMAIL_PRIMARY', '#0369A1'),
        'ink' => env('PRODUCT_EMAIL_INK', '#0A0F1A'),
        'accent' => env('PRODUCT_EMAIL_ACCENT', '#F59E0B'),
        'background' => env('PRODUCT_EMAIL_BACKGROUND', '#F8FAFC'),
        'background_dark' => env('PRODUCT_EMAIL_BACKGROUND_DARK', '#0A0F1A'),
    ],

    'workspace' => [
        'default_timezone' => env('PRODUCT_DEFAULT_TIMEZONE', 'UTC'),
        'default_currency' => env('PRODUCT_DEFAULT_CURRENCY', 'USD'),
        'currencies' => ['USD', 'EUR', 'GBP', 'RUB', 'KZT', 'UAH', 'TRY', 'AED', 'GEL', 'AMD', 'UZS', 'CNY'],
    ],

    'assistant_suggestions' => [
        'en' => [
            'Do we need a second approver for $4,000?',
            "What is Blue Ridge Packaging's official phone number in the contract?",
            'What bank details did Northstar Equipment Leasing have before?',
            'What is the procedure for a new vendor bank account?',
        ],
        'ru' => [
            'Нужен ли второй согласующий для суммы $4 000?',
            'Какой официальный номер Blue Ridge Packaging указан в договоре?',
            'Какие реквизиты были у Northstar Equipment Leasing раньше?',
            'Какая процедура для нового банковского счёта поставщика?',
        ],
    ],
];
