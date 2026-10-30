<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain\Dev;

use App\Billing\BillingException;
use App\Billing\Fake\FakeStripeEvents;
use App\Billing\Fake\FakeWebhookDispatcher;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class BillingSimulationController extends Controller
{
    public const array EVENTS = ['trial', 'invoice-paid', 'cancel-at-period-end', 'payment-failed', 'period-end'];

    public function __invoke(string $event, CurrentWorkspace $current, FakeWebhookDispatcher $dispatcher): JsonResponse
    {
        abort_unless($current->allows('billing.manage'), 403);
        abort_unless(in_array($event, self::EVENTS, true), 404);

        $workspace = $current->get();
        abort_if($workspace === null, 404);

        $subscription = Subscription::query()->where('workspace_id', $workspace->id)->first();
        abort_if($subscription === null || $subscription->stripe_subscription_id === null, 409, 'no_subscription');

        $created = max(time(), (int) $subscription->last_event_at + 1);

        try {
            $dispatcher->dispatch([$this->event($event, $subscription, $workspace->id, $created)]);
        } catch (BillingException $exception) {
            return response()->json(['error' => $exception->getMessage()], 502);
        }

        $subscription->refresh();

        return response()->json([
            'status' => $subscription->status->value,
            'plan' => $subscription->plan,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601ZuluString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $event, Subscription $subscription, int $workspaceId, int $created): array
    {
        $periodEnd = $subscription->current_period_end?->getTimestamp() ?? strtotime('+1 month', $created);
        [$type, $status, $cancelAtPeriodEnd, $trialEnd] = match ($event) {
            'trial' => ['customer.subscription.updated', SubscriptionStatus::Trialing, false, strtotime('+14 days', $created)],
            'invoice-paid' => ['customer.subscription.updated', SubscriptionStatus::Active, false, null],
            'cancel-at-period-end' => ['customer.subscription.updated', $subscription->status, true, $subscription->trial_ends_at?->getTimestamp()],
            'payment-failed' => ['customer.subscription.updated', SubscriptionStatus::PastDue, $subscription->cancel_at_period_end, null],
            default => ['customer.subscription.deleted', SubscriptionStatus::Canceled, false, null],
        };

        return [
            'id' => 'evt_fake_'.Str::random(24),
            'object' => 'event',
            'api_version' => FakeStripeEvents::API_VERSION,
            'created' => $created,
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null, 'idempotency_key' => null],
            'type' => $type,
            'data' => ['object' => [
                'id' => $subscription->stripe_subscription_id,
                'object' => 'subscription',
                'customer' => $subscription->stripe_customer_id,
                'status' => $status->value,
                'cancel_at_period_end' => $cancelAtPeriodEnd,
                'canceled_at' => $status === SubscriptionStatus::Canceled ? $created : null,
                'trial_end' => $trialEnd,
                'current_period_start' => $created,
                'current_period_end' => $periodEnd,
                'livemode' => false,
                'metadata' => [
                    'workspace_id' => (string) $workspaceId,
                    'plan' => $subscription->plan,
                    'interval' => $subscription->interval,
                ],
                'items' => ['object' => 'list', 'data' => [[
                    'object' => 'subscription_item',
                    'current_period_end' => $periodEnd,
                    'price' => [
                        'id' => $subscription->stripe_price_id ?? 'price_fake_'.$subscription->plan.'_'.$subscription->interval,
                        'object' => 'price',
                        'recurring' => ['interval' => $subscription->interval, 'interval_count' => 1],
                    ],
                ]]],
            ]],
        ];
    }
}
