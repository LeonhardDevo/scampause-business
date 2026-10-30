<?php

declare(strict_types=1);

use App\Billing\StripeSignature;
use App\Enums\SubscriptionStatus;
use App\Enums\WorkspaceRole;
use App\Models\Subscription;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

function loadDevDomainRoutes(): void
{
    if (! Route::has('dev.billing.simulate')) {
        Route::group([], base_path('routes/dev-domain.php'));
        app('router')->getRoutes()->refreshNameLookups();
    }
}

function fakeSubscription(int $workspaceId): Subscription
{
    return Subscription::query()->create([
        'workspace_id' => $workspaceId,
        'plan' => 'starter',
        'status' => SubscriptionStatus::Active,
        'interval' => 'month',
        'stripe_customer_id' => 'cus_fake_sim',
        'stripe_subscription_id' => 'sub_fake_sim',
        'current_period_end' => now()->addMonth(),
        'cancel_at_period_end' => false,
        'last_event_at' => time(),
    ]);
}

it('posts a signed subscription event for each simulated lifecycle step to the worker webhook', function (string $event, string $type, string $status, bool $cancelAtPeriodEnd): void {
    loadDevDomainRoutes();
    Http::fake(['worker.test/webhooks/stripe' => Http::response(['received' => true])]);
    [, $workspace] = $this->signInMember(WorkspaceRole::Owner);
    fakeSubscription($workspace->id);

    $this->postJson(route('dev.billing.simulate', $event))->assertOk();

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(1);
    /** @var HttpRequest $request */
    $request = $recorded[0][0];
    $payload = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

    expect(StripeSignature::verify($request->body(), $request->header('Stripe-Signature')[0], 'whsec_test_secret'))->toBeTrue()
        ->and($payload['type'])->toBe($type)
        ->and($payload['data']['object']['id'])->toBe('sub_fake_sim')
        ->and($payload['data']['object']['status'])->toBe($status)
        ->and($payload['data']['object']['cancel_at_period_end'])->toBe($cancelAtPeriodEnd)
        ->and($payload['data']['object']['metadata']['workspace_id'])->toBe((string) $workspace->id)
        ->and($payload['created'])->toBeGreaterThan(time() - 5);
})->with([
    'trial' => ['trial', 'customer.subscription.updated', 'trialing', false],
    'invoice paid' => ['invoice-paid', 'customer.subscription.updated', 'active', false],
    'cancel at period end' => ['cancel-at-period-end', 'customer.subscription.updated', 'active', true],
    'payment failed' => ['payment-failed', 'customer.subscription.updated', 'past_due', false],
    'period end' => ['period-end', 'customer.subscription.deleted', 'canceled', false],
]);

it('refuses members without billing rights and workspaces without a subscription', function (): void {
    loadDevDomainRoutes();
    Http::fake();
    [, $workspace] = $this->signInMember(WorkspaceRole::Member);
    fakeSubscription($workspace->id);
    $this->postJson(route('dev.billing.simulate', 'payment-failed'))->assertForbidden();

    $this->signInMember(WorkspaceRole::Owner);
    $this->postJson(route('dev.billing.simulate', 'payment-failed'))->assertStatus(409);
    Http::assertNothingSent();
});

it('is not registered unless dev routes are enabled', function (): void {
    expect(Route::has('dev.billing.simulate'))->toBeFalse();
});
