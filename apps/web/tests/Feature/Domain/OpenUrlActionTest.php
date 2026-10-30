<?php

declare(strict_types=1);

use App\Domain\Reports\OpenUrlAction;
use App\Enums\WorkspaceRole;

it('accepts only app-relative links to domain pages', function (mixed $url, ?string $expected): void {
    expect(OpenUrlAction::safe($url))->toBe($expected);
})->with([
    ['/payment-requests/42', '/payment-requests/42'],
    ['/vendors/7', '/vendors/7'],
    ['/reports?preset=30d', '/reports?preset=30d'],
    ['/approvals', '/approvals'],
    ['https://evil.test/payment-requests/1', null],
    ['//evil.test/vendors', null],
    ['/settings/billing', null],
    ['/payment-requests/1"><script>', null],
    ['/vendorsx', null],
    [null, null],
]);

it('answers the open_url assistant action with the checked link', function (): void {
    $this->signInMember(WorkspaceRole::Member);

    $this->postJson(route('assistant.actions'), ['type' => 'open_url', 'payload' => ['url' => '/payment-requests/12']])
        ->assertOk()
        ->assertJson(['ok' => true, 'message' => '/payment-requests/12']);

    $this->postJson(route('assistant.actions'), ['type' => 'open_url', 'payload' => ['url' => 'https://example.test']])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);
});
