<?php

declare(strict_types=1);

namespace App\Domain\Vendors\Bank;

use App\Domain\Intake\Enums\BankComparisonOutcome;

final readonly class ComparisonResult
{
    /**
     * @param  array{holder_similarity: int|null, account_edit_distance: int|null, same_bank: bool|null, same_country: bool|null, previous_last4: string|null, new_last4: string|null, previous_country: string|null, new_country: string|null, other_vendor_id: int|null, verified_change_days_ago: int|null}  $details
     */
    public function __construct(
        public BankComparisonOutcome $outcome,
        public ?int $previousAccountId,
        public ?int $matchedAccountId,
        public array $details,
    ) {}
}
