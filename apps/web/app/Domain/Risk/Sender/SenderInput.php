<?php

declare(strict_types=1);

namespace App\Domain\Risk\Sender;

use App\Domain\Intake\Enums\AuthResult;
use Carbon\CarbonImmutable;

final readonly class SenderInput
{
    /**
     * @param  list<string>  $textEmails
     * @param  list<string>  $urls
     * @param  list<string>  $textDomains
     * @param  list<string>  $vendorDomains
     * @param  list<string>  $workspaceVendorDomains
     * @param  list<string>  $ownDomains
     * @param  list<string>  $privilegedMemberNames
     * @param  array<string, DomainFacts>  $facts
     * @param  list<string>  $freeMailDomains
     */
    public function __construct(
        public ?string $fromName,
        public ?string $fromEmail,
        public ?string $replyTo,
        public ?string $returnPath,
        public array $textEmails,
        public array $urls,
        public array $textDomains,
        public bool $vendorMatched,
        public array $vendorDomains,
        public array $workspaceVendorDomains,
        public array $ownDomains,
        public array $privilegedMemberNames,
        public ?AuthResult $spf,
        public ?AuthResult $dkim,
        public ?AuthResult $dmarc,
        public array $facts,
        public array $freeMailDomains,
        public bool $vendorIsEmployee,
        public bool $payrollChange,
        public CarbonImmutable $today,
        public bool $intelSkippedByPlan = false,
    ) {}
}
