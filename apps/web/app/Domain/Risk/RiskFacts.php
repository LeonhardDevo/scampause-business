<?php

declare(strict_types=1);

namespace App\Domain\Risk;

use App\Domain\Intake\Enums\BankComparisonOutcome;
use App\Domain\Intake\Enums\EmployeeResponse;
use App\Domain\Intake\Enums\PaymentRequestKind;

final readonly class RiskFacts
{
    /**
     * @param  array<string, mixed>  $comparisonDetails
     * @param  list<SignalHit>  $senderHits
     * @param  list<SignalHit>  $contentHits
     * @param  list<string>  $messagePhoneLast4
     * @param  list<int>  $historyBaseAmounts
     * @param  array<string, string|int|bool|null|list<string>|list<int>>  $qrMismatchEvidence
     * @param  list<string>  $lowConfidenceFields
     * @param  array{last4: string, changed_at: string}|null  $callbackRecentlyChanged
     * @param  array{last4: string, other_vendors: int}|null  $callbackPhoneShared
     * @param  list<string>  $policyReasons
     * @param  array<string, string|int|bool|null>  $requestDigest
     */
    public function __construct(
        public PaymentRequestKind $kind = PaymentRequestKind::Invoice,
        public ?BankComparisonOutcome $comparisonOutcome = null,
        public array $comparisonDetails = [],
        public bool $payeeHolderPresent = false,
        public ?bool $payeeChecksumValid = null,
        public ?EmployeeResponse $employeeResponse = null,
        public array $senderHits = [],
        public array $contentHits = [],
        public array $messagePhoneLast4 = [],
        public bool $messagePhoneInMaster = false,
        public bool $vendorMatched = false,
        public ?int $vendorAgeDays = null,
        public bool $vendorHasPaid = false,
        public ?string $vendorDefaultCurrency = null,
        public ?string $duplicateInvoiceOf = null,
        public ?string $duplicateSuspectOf = null,
        public int $historyCount = 0,
        public array $historyBaseAmounts = [],
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?int $amountBaseMinor = null,
        public bool $amountMultipleOfThousand = false,
        public ?int $dueBusinessDays = null,
        public bool $qrMismatch = false,
        public array $qrMismatchEvidence = [],
        public bool $qrOnly = false,
        public bool $fxMissing = false,
        public array $lowConfidenceFields = [],
        public bool $pipelineIncomplete = false,
        public bool $ocrLanguageMissing = false,
        public ?array $callbackRecentlyChanged = null,
        public ?array $callbackPhoneShared = null,
        public int $verificationThresholdMinor = 100000,
        public bool $policyRequiresVerification = false,
        public array $policyReasons = [],
        public array $requestDigest = [],
    ) {}

    /**
     * @param  list<string>  $reasons
     */
    public function withPolicy(bool $required, array $reasons): self
    {
        $arguments = get_object_vars($this);
        $arguments['policyRequiresVerification'] = $required;
        $arguments['policyReasons'] = $reasons;

        return new self(...$arguments);
    }
}
