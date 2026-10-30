<?php

declare(strict_types=1);

namespace App\Domain\Intake\Presenters;

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Risk\Models\RiskSignal;
use App\Domain\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Support\Collection;

final class PaymentRequestRows
{
    /**
     * @param  iterable<PaymentRequest>  $requests
     * @return list<array<string, mixed>>
     */
    public static function many(iterable $requests, ?User $viewer): array
    {
        $items = $requests instanceof Collection ? $requests : new Collection($requests);

        if ($items->isEmpty()) {
            return [];
        }

        $assessmentIds = $items->pluck('risk_assessment_id')->filter()->unique()->values()->all();
        $topSignals = [];

        if ($assessmentIds !== []) {
            $signals = RiskSignal::query()->withoutGlobalScopes()
                ->whereIn('risk_assessment_id', $assessmentIds)
                ->where('points', '>', 0)
                ->orderByDesc('points')
                ->orderBy('code')
                ->get(['risk_assessment_id', 'code']);

            foreach ($signals as $signal) {
                $topSignals[(int) $signal->getAttribute('risk_assessment_id')] ??= (string) $signal->getAttribute('code');
            }
        }

        $pending = [];

        if ($viewer !== null) {
            $approvals = Approval::query()->withoutGlobalScopes()
                ->whereIn('payment_request_id', $items->pluck('id')->all())
                ->where('approver_id', $viewer->id)
                ->where('status', ApprovalStatus::Pending->value)
                ->get(['id', 'payment_request_id']);

            foreach ($approvals as $approval) {
                $pending[(int) $approval->getAttribute('payment_request_id')] = (int) $approval->getKey();
            }
        }

        $vendorIds = $items->pluck('vendor_id')->filter()->unique()->values()->all();
        $vendors = $vendorIds === [] ? collect() : Vendor::query()->withoutGlobalScopes()->withTrashed()->whereIn('id', $vendorIds)->get()->keyBy('id');
        $userIds = $items->pluck('requested_by')->filter()->unique()->values()->all();
        $users = User::query()->whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');

        return array_values($items->map(fn (PaymentRequest $request): array => self::row(
            $request,
            $vendors->get($request->getAttribute('vendor_id')),
            $users->get($request->getAttribute('requested_by')),
            $topSignals[(int) $request->getAttribute('risk_assessment_id')] ?? null,
            $pending[(int) $request->getKey()] ?? null,
        ))->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function one(PaymentRequest $request, ?User $viewer): array
    {
        return self::many(collect([$request]), $viewer)[0];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(PaymentRequest $request, ?Vendor $vendor, ?User $requester, ?string $topSignal, ?int $myPendingApproval): array
    {
        $score = $request->getAttribute('risk_score');
        $level = Refs::enum($request->getAttribute('risk_level'));

        return [
            'id' => (int) $request->getKey(),
            'reference' => (string) $request->getAttribute('reference'),
            'title' => (string) $request->getAttribute('title'),
            'kind' => Refs::enum($request->getAttribute('kind')),
            'source' => Refs::enum($request->getAttribute('source')),
            'status' => Refs::enum($request->getAttribute('status')),
            'vendor' => Refs::vendor($vendor),
            'amount' => Refs::money($request->getAttribute('amount_minor'), $request->getAttribute('currency')),
            'risk' => is_int($score) && $level !== null ? ['score' => $score, 'level' => $level, 'top_signal' => $topSignal] : null,
            'verification_required' => (bool) $request->getAttribute('verification_required'),
            'due_date' => Refs::date($request->getAttribute('due_date')),
            'expires_at' => Refs::iso($request->getAttribute('expires_at')),
            'requested_by' => Refs::user($requester) ?? ['id' => (int) $request->getAttribute('requested_by'), 'name' => '', 'avatar' => null],
            'created_at' => Refs::iso($request->getAttribute('created_at')),
            'my_pending_approval_id' => $myPendingApproval,
        ];
    }
}
