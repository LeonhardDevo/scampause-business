<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Verification\EmployeeAlertService;
use App\Enums\WorkspaceRole;
use App\Models\Workspace;

final readonly class AssessmentNotifier
{
    public function __construct(private DomainNotifier $notifier, private EmployeeAlertService $alerts) {}

    public function afterIntake(PaymentRequest $request): void
    {
        $workspace = Workspace::query()->findOrFail((int) $request->getAttribute('workspace_id'));
        $level = $request->getAttribute('risk_level');
        $requester = DomainNotifier::users([(int) $request->getAttribute('requested_by')]);

        if ($level instanceof RiskLevel && $level->atLeast(RiskLevel::High)) {
            $reviewers = DomainNotifier::membersWithRoles($workspace, [WorkspaceRole::Bookkeeper, WorkspaceRole::Admin]);
            $owners = DomainNotifier::membersWithRoles($workspace, [WorkspaceRole::Owner]);
            $this->notifier->notify('risk_detected', [...$requester, ...$reviewers, ...$owners], $workspace->id, $request, [DomainNotifier::DATABASE, DomainNotifier::TELEGRAM, DomainNotifier::EMAIL], [], 'danger');
        } else {
            $this->notifier->notify('review_ready', $requester, $workspace->id, $request, [DomainNotifier::DATABASE, DomainNotifier::TELEGRAM], [], 'info');
        }

        $this->alerts->sendIfNeeded($request);
    }
}
