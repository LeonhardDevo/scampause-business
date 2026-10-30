<?php

declare(strict_types=1);

namespace App\Domain\Intake\Events;

use App\Domain\Intake\Models\PaymentRequest;
use Illuminate\Foundation\Events\Dispatchable;

final class IntakeAssessed
{
    use Dispatchable;

    public function __construct(public readonly PaymentRequest $request) {}
}
