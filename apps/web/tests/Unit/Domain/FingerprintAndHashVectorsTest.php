<?php

declare(strict_types=1);

use App\Domain\Evidence\CanonicalJson;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Support\Fingerprinter;
use App\Domain\Vendors\Enums\BankScheme;
use Carbon\CarbonImmutable;

it('computes the shared fingerprint vectors', function (string $kind, string $expected): void {
    config(['scampause.fingerprint_key' => base64_encode('test-fingerprint-key')]);
    $fingerprinter = Fingerprinter::fromConfig();

    $actual = match ($kind) {
        'iban' => $fingerprinter->bank(1, BankScheme::Iban, 'DE89 3704 0044 0532 0130 00'),
        'ach' => $fingerprinter->bank(1, BankScheme::UsAch, '000123456789', '021000021'),
        'phone' => $fingerprinter->phone(1, '+18285550142'),
    };

    expect($actual)->toBe($expected);
})->with([
    ['iban', 'bdd8be580a9ebf4c83d55b5fde68035efeae6a300c391e2a924820e513c61cba'],
    ['ach', 'bfd3ba5c1f5618de6f68c7a1b937a7864992370d8c3788e70df9f81d5ca49846'],
    ['phone', 'fa29992fe5b8433ea2e9e646ae19f8c76bd0332b5075bfe91758293580f07ada'],
]);

it('salts fingerprints per workspace', function (): void {
    $fingerprinter = new Fingerprinter('test-fingerprint-key');

    expect($fingerprinter->bank(1, BankScheme::Iban, 'DE89370400440532013000'))
        ->not->toBe($fingerprinter->bank(2, BankScheme::Iban, 'DE89370400440532013000'));
});

it('builds the documented bank fingerprint body', function (): void {
    $fingerprinter = new Fingerprinter('k');

    expect($fingerprinter->bankBody(BankScheme::Iban, 'iban de89 3704 0044 0532 0130 00'))->toBe('iban::DE89370400440532013000')
        ->and($fingerprinter->bankBody(BankScheme::UsAch, '0001-2345-6789', '021 000 021'))->toBe('us_ach:021000021:000123456789');
});

it('canonicalizes JSON per the shared vectors', function (): void {
    expect(CanonicalJson::encode(new stdClass))->toBe('{}')
        ->and(CanonicalJson::encode(['b' => "Ж\u{2028}", 'a' => [], '9' => 1, '10' => 2]))->toBe("{\"10\":2,\"9\":1,\"a\":[],\"b\":\"Ж\u{2028}\"}")
        ->and(CanonicalJson::encode(['z' => [[3, 1], ['b' => 1, 'a' => 2]]]))->toBe('{"z":[[3,1],{"a":2,"b":1}]}');
});

it('rejects floats in canonical JSON', function (): void {
    CanonicalJson::encode(['amount' => 1.5]);
})->throws(InvalidArgumentException::class);

it('reproduces the shared evidence chain vectors', function (): void {
    $first = EvidenceLedger::material(1, 1, 42, 'payment_request', 42, 'verification.recorded', 7, 'web',
        CarbonImmutable::parse('2026-10-22T10:15:30.123456Z'),
        ['account_last4' => '4417', 'method' => 'callback_known_number', 'outcome' => 'confirmed', 'phone_last4' => '0142']);
    $h1 = EvidenceLedger::hash(EvidenceLedger::GENESIS, $first);

    $second = EvidenceLedger::material(2, 1, 42, 'approval', 9, 'approval.approved', 3, 'telegram',
        CarbonImmutable::parse('2026-10-22T10:20:00Z'), ['risk_level' => 'high', 'slot' => 2]);
    $h2 = EvidenceLedger::hash($h1, $second);

    $third = EvidenceLedger::material(3, 1, 42, 'payment_request', 42, 'request.approved', 3, 'web',
        CarbonImmutable::parse('2026-10-22T10:20:00Z'), []);
    $h3 = EvidenceLedger::hash($h2, $third);

    expect($first)->toBe('{"actor_user_id":7,"channel":"web","event":"verification.recorded","occurred_at":"2026-10-22T10:15:30.123456Z","payload":{"account_last4":"4417","method":"callback_known_number","outcome":"confirmed","phone_last4":"0142"},"payment_request_id":42,"seq":1,"subject_id":42,"subject_type":"payment_request","workspace_id":1}')
        ->and($h1)->toBe('271e30a76af16b068108233f3c28782c6a45a3f0dcde05d4f49578d2b4f3e650')
        ->and($h2)->toBe('ea8327b274a4ac0dc7f0d0830c835f31ee1f18ab41e2a162b3e4a1cfab877d47')
        ->and($h3)->toBe('10a53e28c6fd822a322d6312d063a295e87f5c76215e718d1ea697a5ae844ef1');
});
