<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\Domain\ApprovalsController;
use App\Http\Controllers\Internal\Domain\AssistantToolsController;
use App\Http\Controllers\Internal\Domain\ExportsController;
use App\Http\Controllers\Internal\Domain\IntakeController;
use App\Http\Controllers\Internal\Domain\TelegramDomainController;
use Illuminate\Support\Facades\Route;

Route::middleware('internal.caller:worker')->prefix('scampause')->name('scampause.')->group(function (): void {
    Route::get('payment-requests/{paymentRequest}/intake', [IntakeController::class, 'intake'])->whereNumber('paymentRequest')->name('intake');
    Route::get('files/{file}', [IntakeController::class, 'file'])->whereNumber('file')->name('files.show');
    Route::post('payment-requests/{paymentRequest}/previews', [IntakeController::class, 'storePreview'])->whereNumber('paymentRequest')->name('previews.store');
    Route::post('payment-requests/{paymentRequest}/files', [IntakeController::class, 'storeFile'])->whereNumber('paymentRequest')->name('files.store');
    Route::post('payment-requests/{paymentRequest}/pipeline', [IntakeController::class, 'pipeline'])->whereNumber('paymentRequest')->name('pipeline');
    Route::post('payment-requests/{paymentRequest}/extraction', [IntakeController::class, 'extraction'])->whereNumber('paymentRequest')->name('extraction');
    Route::post('payment-requests/{paymentRequest}/bank-comparison', [IntakeController::class, 'bankComparison'])->whereNumber('paymentRequest')->name('bank-comparison');
    Route::post('payment-requests/{paymentRequest}/assess', [IntakeController::class, 'assess'])->whereNumber('paymentRequest')->name('assess');
    Route::get('domain-intel', [IntakeController::class, 'domainIntel'])->name('domain-intel.index');
    Route::post('domain-intel', [IntakeController::class, 'storeDomainIntel'])->name('domain-intel.store');
    Route::post('pipeline/sweep', [IntakeController::class, 'sweep'])->name('pipeline.sweep');

    Route::post('approvals/sweep', [ApprovalsController::class, 'sweep'])->name('approvals.sweep');
    Route::post('approvals/{approval}/telegram-message', [ApprovalsController::class, 'storeTelegramMessage'])->whereNumber('approval')->name('approvals.telegram-message.store');
    Route::get('approvals/{approval}/telegram-message', [ApprovalsController::class, 'showTelegramMessage'])->whereNumber('approval')->name('approvals.telegram-message.show');
    Route::post('approvals/{approval}/reminders', [ApprovalsController::class, 'reminders'])->whereNumber('approval')->name('approvals.reminders');
    Route::post('approval-routes/{route}/escalate', [ApprovalsController::class, 'escalate'])->whereNumber('route')->name('approval-routes.escalate');
    Route::post('approval-routes/{route}/expire', [ApprovalsController::class, 'expire'])->whereNumber('route')->name('approval-routes.expire');

    Route::post('telegram/callback', [TelegramDomainController::class, 'callback'])->name('telegram.callback');
    Route::post('telegram/workspace', [TelegramDomainController::class, 'workspace'])->name('telegram.workspace');
    Route::get('telegram/pending', [TelegramDomainController::class, 'pending'])->name('telegram.pending');
    Route::get('telegram/request', [TelegramDomainController::class, 'request'])->name('telegram.request');

    Route::get('exports/{export}/data', [ExportsController::class, 'data'])->whereNumber('export')->name('exports.data');
    Route::post('exports/{export}/file', [ExportsController::class, 'file'])->whereNumber('export')->name('exports.file');
    Route::post('exports/{export}/failed', [ExportsController::class, 'failed'])->whereNumber('export')->name('exports.failed');
    Route::post('evidence/verify-all', [ExportsController::class, 'verifyAll'])->name('evidence.verify-all');
    Route::post('retention/purge', [ExportsController::class, 'purge'])->name('retention.purge');
});

Route::middleware('internal.caller:ai')->prefix('scampause')->name('scampause.')->group(function (): void {
    Route::get('approvals/pending', [AssistantToolsController::class, 'pendingApprovals'])->name('approvals.pending');
    Route::post('policy/evaluate', [AssistantToolsController::class, 'evaluatePolicy'])->name('policy.evaluate');
    Route::get('vendors/search', [AssistantToolsController::class, 'searchVendors'])->name('vendors.search');
    Route::get('vendors/{vendor}/contacts', [AssistantToolsController::class, 'contacts'])->whereNumber('vendor')->name('vendors.contacts');
    Route::get('vendors/{vendor}/bank-history', [AssistantToolsController::class, 'bankHistory'])->whereNumber('vendor')->name('vendors.bank-history');
    Route::get('payment-requests/by-reference/{reference}', [AssistantToolsController::class, 'byReference'])->where('reference', 'SP-\d{5}')->name('payment-requests.by-reference');
});
