<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\AiQueriesController;
use App\Http\Controllers\Internal\DeliveriesController;
use App\Http\Controllers\Internal\DocumentsController;
use App\Http\Controllers\Internal\PingController;
use App\Http\Controllers\Internal\PrivacyController;
use App\Http\Controllers\Internal\StripeEventsController;
use App\Http\Controllers\Internal\TelegramController;
use App\Http\Controllers\Internal\YooKassaController;
use Illuminate\Support\Facades\Route;

Route::get('ping', PingController::class)->name('ping');

Route::middleware('internal.caller:worker')->group(function (): void {
    Route::post('telegram/link', [TelegramController::class, 'link'])->name('telegram.link');
    Route::get('telegram/context', [TelegramController::class, 'context'])->name('telegram.context');
    Route::post('telegram/actions', [TelegramController::class, 'action'])->name('telegram.actions');
    Route::post('telegram/unlink', [TelegramController::class, 'unlink'])->name('telegram.unlink');

    Route::post('privacy/purge', [PrivacyController::class, 'purge'])->name('privacy.purge');
    Route::post('privacy/exports/{export}/build', [PrivacyController::class, 'buildExport'])
        ->whereNumber('export')
        ->name('privacy.exports.build');

    Route::post('deliveries', DeliveriesController::class)->name('deliveries');

    Route::post('stripe/events', StripeEventsController::class)->name('stripe.events');
    Route::post('yookassa/notifications', [YooKassaController::class, 'notification'])->name('yookassa.notifications');
    Route::post('billing/renewals', [YooKassaController::class, 'renewals'])->name('billing.renewals');

});

Route::get('documents/{document}/file', [DocumentsController::class, 'file'])
    ->middleware('internal.caller:ai')
    ->whereNumber('document')
    ->name('documents.file');

Route::post('documents/{document}/status', [DocumentsController::class, 'status'])
    ->middleware('internal.caller:ai,worker')
    ->whereNumber('document')
    ->name('documents.status');

Route::post('ai-queries', AiQueriesController::class)
    ->middleware('internal.caller:ai,worker')
    ->name('ai-queries');

require __DIR__.'/internal-domain.php';
