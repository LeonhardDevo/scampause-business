<?php

declare(strict_types=1);

namespace App\Domain\Intake;

use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Policy\PaymentSettings;
use App\Models\OutboxMessage;
use App\Models\Workspace;
use App\Outbox\Outbox;

final readonly class IntakePublisher
{
    public const string TOPIC = 'intake.received';

    public function __construct(private Outbox $outbox) {}

    /**
     * @param  list<int>  $fileIds
     */
    public function received(PaymentRequest $request, Workspace $workspace, array $fileIds, bool $skipExtract): OutboxMessage
    {
        return $this->outbox->publish(self::TOPIC, [
            'payment_request_id' => (int) $request->getKey(),
            'workspace_id' => $workspace->id,
            'revision' => (int) $request->getAttribute('revision'),
            'file_ids' => array_map('intval', $fileIds),
            'locale' => $workspace->locale === 'ru' ? 'ru' : 'en',
            'country' => PaymentSettings::country($workspace),
            'skip_extract' => $skipExtract,
        ], $workspace->id);
    }
}
