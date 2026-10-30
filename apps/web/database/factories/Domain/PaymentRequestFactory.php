<?php

declare(strict_types=1);

namespace Database\Factories\Domain;

use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestSource;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\PaymentRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentRequest>
 */
class PaymentRequestFactory extends Factory
{
    protected $model = PaymentRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => 'SP-'.strtoupper(Str::random(5)),
            'kind' => PaymentRequestKind::Invoice,
            'source' => PaymentRequestSource::Manual,
            'status' => PaymentRequestStatus::Review,
            'title' => 'Invoice '.fake()->numerify('####'),
            'base_currency' => 'USD',
            'revision' => 1,
            'pipeline' => [],
        ];
    }
}
