<?php

declare(strict_types=1);

namespace App\Domain\Workflow;

use App\Domain\Intake\Models\PaymentRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class RequestLock
{
    public static function lock(int $workspaceId, int $requestId): PaymentRequest
    {
        $request = PaymentRequest::query()->forWorkspace($workspaceId)->whereKey($requestId)->lockForUpdate()->first();

        if ($request === null) {
            throw new ModelNotFoundException;
        }

        return $request;
    }
}
