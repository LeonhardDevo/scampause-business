<?php

declare(strict_types=1);

namespace App\Policies\Domain;

use App\Domain\Intake\Models\PaymentRequest;
use App\Models\User;
use App\Tenancy\CurrentWorkspace;

final readonly class PaymentRequestPolicy
{
    public function __construct(private CurrentWorkspace $current) {}

    public function viewAny(User $user): bool
    {
        return $this->current->allows('payment_requests.view_all') || $this->current->allows('payment_requests.create');
    }

    public function view(User $user, PaymentRequest $request): bool
    {
        if (! $this->sameWorkspace($request)) {
            return false;
        }

        return $this->current->allows('payment_requests.view_all')
            || ((int) $request->getAttribute('requested_by') === $user->id && $this->current->allows('payment_requests.create'));
    }

    public function create(User $user): bool
    {
        return $this->current->allows('payment_requests.create');
    }

    public function review(User $user, PaymentRequest $request): bool
    {
        return $this->sameWorkspace($request) && $this->current->allows('payment_requests.review');
    }

    public function reveal(User $user, PaymentRequest $request): bool
    {
        return $this->sameWorkspace($request) && $this->current->allows('bank_details.reveal');
    }

    private function sameWorkspace(PaymentRequest $request): bool
    {
        return (int) $request->getAttribute('workspace_id') === $this->current->id();
    }
}
