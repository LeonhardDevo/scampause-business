<?php

declare(strict_types=1);

use App\Http\Controllers\Domain\Dev\ApprovalDevController;
use App\Http\Controllers\Domain\Dev\BillingSimulationController;
use App\Http\Controllers\Domain\Dev\InlinePipelineController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified', 'workspace'])->prefix('dev/scampause')->name('dev.scampause.')->group(function (): void {
    Route::post('payment-requests/{paymentRequest}/inline-pipeline', InlinePipelineController::class)
        ->whereNumber('paymentRequest')
        ->name('inline-pipeline');
    Route::post('routes/{route}/expire-now', [ApprovalDevController::class, 'expireNow'])
        ->whereNumber('route')
        ->name('routes.expire-now');
    Route::post('approvals/{approval}/remind-now', [ApprovalDevController::class, 'remindNow'])
        ->whereNumber('approval')
        ->name('approvals.remind-now');
});

Route::middleware(['web', 'auth', 'verified', 'workspace', 'fake.billing'])->prefix('dev/billing')->name('dev.billing.')->group(function (): void {
    Route::post('simulate/{event}', BillingSimulationController::class)
        ->whereIn('event', BillingSimulationController::EVENTS)
        ->name('simulate');
});
