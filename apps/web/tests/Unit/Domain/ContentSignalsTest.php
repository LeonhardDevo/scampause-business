<?php

declare(strict_types=1);

use App\Domain\Risk\Content\ContentSignals;
use App\Domain\Risk\SignalHit;

/**
 * @param  list<SignalHit>  $hits
 * @return array<string, list<string>>
 */
function spContentMap(array $hits): array
{
    $map = [];

    foreach ($hits as $hit) {
        $phrases = $hit->evidence['phrases'] ?? [];
        $map[$hit->code] = is_array($phrases) ? array_values(array_map('strval', $phrases)) : [];
    }

    return $map;
}

it('detects each message category in English and Russian', function (string $text, string $code): void {
    expect(array_keys(spContentMap(ContentSignals::detect($text, null))))->toContain($code);
})->with([
    ['Please pay this URGENTLY, by end of day.', 'URGENCY_LANGUAGE'],
    ['Оплатите срочно, до конца дня.', 'URGENCY_LANGUAGE'],
    ['Keep this between us for now.', 'SECRECY_LANGUAGE'],
    ['Пожалуйста, никому не говорите.', 'SECRECY_LANGUAGE'],
    ["I'm in a meeting, don’t call me.", 'CALLBACK_DISCOURAGED'],
    ['Не звоните, телефон не работает.', 'CALLBACK_DISCOURAGED'],
    ['The CEO asked me to arrange this.', 'AUTHORITY_PRESSURE'],
    ['Генеральный директор просит оплатить сегодня.', 'AUTHORITY_PRESSURE'],
]);

it('detects document categories with prefix stems', function (string $text, string $code): void {
    expect(array_keys(spContentMap(ContentSignals::detect(null, $text))))->toContain($code);
})->with([
    ['Please note our new bank details below.', 'CHANGE_REQUEST_LANGUAGE'],
    ['У нас изменились реквизиты, новый расчётный счёт ниже.', 'CHANGE_REQUEST_LANGUAGE'],
    ['Pay with gift cards or cryptocurrency.', 'UNUSUAL_PAYMENT_METHOD'],
    ['Можно оплатить подарочными картами или криптовалютой.', 'UNUSUAL_PAYMENT_METHOD'],
]);

it('does not run message categories on document text', function (): void {
    expect(spContentMap(ContentSignals::detect(null, 'URGENT: pay immediately')))->toBe([]);
});

it('uses unicode word boundaries', function (): void {
    expect(spContentMap(ContentSignals::detect('Ultra-urgentness is not a word; asapx neither.', null)))->toBe([])
        ->and(spContentMap(ContentSignals::detect('Несрочно и безсрочно.', null)))->toBe([]);
});

it('caps evidence at three distinct phrases', function (): void {
    $map = spContentMap(ContentSignals::detect('Urgent! Immediately! ASAP! Right away! Pay today!', null));

    expect($map['URGENCY_LANGUAGE'])->toHaveCount(3)
        ->and($map['URGENCY_LANGUAGE'][0])->toBe('urgent');
});

it('ignores quoted replies, signatures and disclaimers', function (): void {
    $message = "Hi team, the invoice is attached.\n\n> URGENT please pay immediately\n\nThis e-mail and any attachments are strictly confidential and intended solely for the addressee. Do not discuss its contents.\n\n--\nAlex\nkeep this between us";

    expect(spContentMap(ContentSignals::detect($message, null)))->toBe([]);
});

it('scores zero content points on the negative fixtures', function (string $file): void {
    $text = (string) file_get_contents(base_path('tests/Fixtures/Domain/'.$file));

    expect(spContentMap(ContentSignals::detect($text, $text)))->toBe([]);
})->with(['negative-1c-invoice.txt', 'negative-us-invoice-email.txt']);
