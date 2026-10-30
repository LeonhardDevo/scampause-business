<?php

declare(strict_types=1);

use App\Domain\Notifications\DomainNotifier;
use App\Domain\Workflow\ReassessmentService;
use Tests\Support\Domain\Scenario;

it('lists each verification reason once and names the official callback number', function (): void {
    $s = Scenario::make();
    $vendor = $s->vendor();
    $s->callbackContact($vendor);
    $request = $s->request('bookkeeper', ['vendorId' => (int) $vendor->getKey()]);
    $request->forceFill(['verification_reasons' => ['rule:1', 'rule:2', 'rule:4', 'kind:bank_details_change']])->save();

    $params = DomainNotifier::resolveExtra(ReassessmentService::verificationExtra($request->refresh()), 'en');

    expect(substr_count((string) $params['reasons'], 'Required by an approval rule'))->toBe(1)
        ->and($params['callback_contact'])->toBe('Karen Whitfield, +18285550142')
        ->and($params['source_ref'])->toBe('Supply Agreement BRP-2025-014, §9.2');
});

it('omits the callback number when the vendor has no verified callback contact', function (): void {
    $s = Scenario::make();
    $vendor = $s->vendor();
    $request = $s->request('bookkeeper', ['vendorId' => (int) $vendor->getKey()]);
    $request->forceFill(['verification_reasons' => ['rule:1']])->save();

    $params = DomainNotifier::resolveExtra(ReassessmentService::verificationExtra($request->refresh()), 'ru');

    expect($params)->not->toHaveKey('callback_contact')
        ->and($params['reasons'])->not->toBe('');
});
