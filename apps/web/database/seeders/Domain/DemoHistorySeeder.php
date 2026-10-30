<?php

declare(strict_types=1);

namespace Database\Seeders\Domain;

use App\Domain\Approvals\ApprovalGate;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Approvals\Enums\DecisionChannel;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Verification\Enums\VerificationOutcome;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Domain\Support\DemoWorkspace;
use Database\Seeders\Domain\Support\InvoiceFixture;

final class DemoHistorySeeder
{
    public const int SEED = 20261022;

    public const array PLAN = [
        1 => 'blue_ridge', 2 => 'cleansweep', 3 => 'andes', 4 => 'summit', 5 => 'harbor', 6 => 'metro', 7 => 'blue_ridge', 8 => 'northstar',
        9 => 'harbor', 10 => 'summit', 11 => 'cleansweep', 13 => 'blue_ridge', 14 => 'harbor', 15 => 'andes', 16 => 'metro', 17 => 'summit',
        18 => 'blue_ridge', 19 => 'harbor', 20 => 'cleansweep', 21 => 'northstar', 22 => 'blue_ridge', 23 => 'summit', 24 => 'harbor', 25 => 'metro',
        26 => 'blue_ridge', 28 => 'andes', 29 => 'harbor', 30 => 'summit', 32 => 'blue_ridge', 33 => 'cleansweep', 34 => 'harbor', 35 => 'blue_ridge',
        37 => 'summit',
    ];

    public const array MEDIUM = [19 => 520000, 23 => 210000, 26 => 1550000, 33 => 360000];

    public const array HIGH = [29 => [1240000, 'HLG Freight Services Ltd'], 30 => [395000, 'SOS Supplies Distribution LLC']];

    public const array DESCRIPTIONS = [
        'blue_ridge' => 'Kraft valve bags and shipping cartons',
        'cleansweep' => 'Monthly roastery and cafe cleaning service',
        'andes' => 'Green coffee, washed arabica, 4 bags x 69 kg',
        'summit' => 'Office and packing supplies',
        'harbor' => 'Inbound freight, container delivery',
        'metro' => 'Electricity and gas, roastery meter',
        'northstar' => 'Roaster lease installment, lease NEL-2023-118',
    ];

    public static function schedule(DemoWorkspace $demo): void
    {
        mt_srand(self::SEED);
        $catalog = NorthwindCatalog::vendors();
        $refs = array_keys(self::PLAN);
        $count = count($refs);
        $counters = [];

        foreach ($refs as $index => $ref) {
            $vendorKey = self::PLAN[$ref];
            $spec = $catalog[$vendorKey];
            $daysAgo = 84 - (int) round($index * 67 / ($count - 1));
            $minute = 5 + mt_rand(0, 50);
            $created = $demo->day($daysAgo, sprintf('%02d:%02d', 9 + mt_rand(0, 2), $minute));
            $counters[$vendorKey] = ($counters[$vendorKey] ?? 0) + 1;
            $amount = self::amount($ref, $vendorKey);
            $currency = (string) $spec['vendor']['default_currency'];
            $invoiceNumber = self::invoiceNumber($vendorKey, $ref, $counters[$vendorKey]);
            $pressure = isset(self::MEDIUM[$ref]) || isset(self::HIGH[$ref]);
            $holder = isset(self::HIGH[$ref]) ? self::HIGH[$ref][1] : null;
            $requester = $ref % 5 === 0 ? 'maria' : 'irina';
            $submitter = $requester;
            $payDays = 1 + ($ref % 3);
            $telegram = mt_rand(1, 100) <= 40;

            $demo->at($created, 'history SP-'.$ref.' intake', function () use ($demo, $ref, $spec, $vendorKey, $invoiceNumber, $amount, $currency, $created, $pressure, $holder, $requester): void {
                $local = $created->setTimezone($demo->timezone())->startOfDay();
                $fixture = InvoiceFixture::build(
                    (string) $spec['vendor']['name'],
                    isset($spec['vendor']['tax_id']) ? (string) $spec['vendor']['tax_id'] : null,
                    $invoiceNumber,
                    $amount,
                    $currency,
                    $local,
                    $pressure ? $local->addDay() : $local->addDays((int) ($spec['vendor']['payment_terms_days'] ?? 30)),
                    $spec['accounts'][0],
                    self::DESCRIPTIONS[$vendorKey],
                    $holder,
                );
                $demo->upload($ref, $requester, $fixture['files'], $fixture['extraction']);
            });

            $demo->at($created->addMinutes(20), 'history SP-'.$ref.' submit', fn (): mixed => $demo->submit($ref, $submitter));

            $demo->at($created->addMinutes(70), 'history SP-'.$ref.' verification', function () use ($demo, $ref, $vendorKey, $requester): void {
                if ($demo->request($ref)->getAttribute('status') !== PaymentRequestStatus::AwaitingVerification) {
                    return;
                }

                $demo->verify($ref, $requester === 'maria' ? 'alex' : 'maria', $vendorKey, VerificationOutcome::Confirmed);
            });

            foreach ([3, 5] as $offset) {
                $demo->at($created->addHours($offset), 'history SP-'.$ref.' approval', function () use ($demo, $ref, $telegram): void {
                    $pending = $demo->pending($ref);

                    if ($pending === []) {
                        return;
                    }

                    $approval = $pending[0];
                    $approver = User::query()->findOrFail((int) $approval->getAttribute('approver_id'));
                    $request = $demo->request($ref);
                    $channel = $telegram && $approver->telegramAccount !== null && app(ApprovalGate::class)->telegramAllowed($request, $demo->workspace)
                        ? DecisionChannel::Telegram
                        : DecisionChannel::Web;
                    app(ApprovalService::class)->approve($approval, $approver, $channel);
                });
            }

            $demo->at($created->addHours(7), 'history SP-'.$ref.' remaining approvals', fn () => $demo->approveAll($ref));

            $demo->at($demo->day($daysAgo - $payDays, '11:15'), 'history SP-'.$ref.' paid', function () use ($demo, $ref, $vendorKey): void {
                if ($demo->request($ref)->getAttribute('status') === PaymentRequestStatus::Approved) {
                    $demo->pay($ref, 'irina', ($vendorKey === 'andes' ? 'SEPA ' : 'ACH ').CarbonImmutable::now()->format('md').'-'.str_pad((string) $ref, 3, '0', STR_PAD_LEFT));
                }
            });
        }
    }

    private static function amount(int $ref, string $vendorKey): int
    {
        if (isset(self::MEDIUM[$ref])) {
            return self::MEDIUM[$ref];
        }

        if (isset(self::HIGH[$ref])) {
            return self::HIGH[$ref][0];
        }

        return match ($vendorKey) {
            'andes' => mt_rand(1100, 1450) * 1000,
            'blue_ridge' => mt_rand(620, 790) * 1000 + mt_rand(0, 3) * 250,
            'cleansweep' => 145000,
            'metro' => mt_rand(90000, 130000),
            'summit' => mt_rand(18000, 90000),
            'harbor' => mt_rand(150, 260) * 1000 + mt_rand(0, 9) * 50,
            'northstar' => 396000,
            default => 100000,
        };
    }

    private static function invoiceNumber(string $vendorKey, int $ref, int $sequence): string
    {
        return match ($vendorKey) {
            'andes' => 'AG-2026-0'.(4 + $sequence).'1'.$sequence,
            'blue_ridge' => (string) (88100 + $ref * 7),
            'cleansweep' => 'CS-'.(2400 + $ref),
            'metro' => 'MPG-'.(771000 + $ref * 13),
            'summit' => 'SO-'.(54800 + $ref * 9),
            'harbor' => 'HL-'.(7300 + $ref * 11),
            'northstar' => 'NEL-2023-118-'.str_pad((string) (6 + $sequence), 2, '0', STR_PAD_LEFT),
            default => 'INV-'.$ref,
        };
    }
}
