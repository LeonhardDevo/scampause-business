<?php

declare(strict_types=1);

use App\Http\Controllers\Domain\ApprovalController;
use App\Http\Controllers\Domain\BlockedAccountController;
use App\Http\Controllers\Domain\DataSubjectController;
use App\Http\Controllers\Domain\PaymentRequestController;
use App\Http\Controllers\Domain\PaymentRequestFileController;
use App\Http\Controllers\Domain\PaymentRequestWorkflowController;
use App\Http\Controllers\Domain\PolicyController;
use App\Http\Controllers\Domain\ReportController;
use App\Http\Controllers\Domain\VendorController;
use App\Http\Controllers\Domain\VendorMasterDataController;
use Illuminate\Support\Facades\Route;

Route::get('payment-requests', [PaymentRequestController::class, 'index'])->name('payment-requests.index');
Route::get('payment-requests/create', [PaymentRequestController::class, 'create'])->name('payment-requests.create');
Route::post('payment-requests', [PaymentRequestController::class, 'store'])
    ->middleware(['throttle:sp-uploads', 'plan.limit:payment_requests_monthly'])
    ->name('payment-requests.store');
Route::post('payment-requests/manual', [PaymentRequestController::class, 'manual'])
    ->middleware(['throttle:sp-uploads', 'plan.limit:payment_requests_monthly'])
    ->name('payment-requests.manual');
Route::get('payment-requests/{paymentRequest}', [PaymentRequestController::class, 'show'])
    ->whereNumber('paymentRequest')
    ->name('payment-requests.show');
Route::get('payment-requests/{paymentRequest}/status', [PaymentRequestController::class, 'status'])
    ->whereNumber('paymentRequest')
    ->name('payment-requests.status');
Route::patch('payment-requests/{paymentRequest}', [PaymentRequestController::class, 'update'])
    ->whereNumber('paymentRequest')
    ->name('payment-requests.update');
Route::put('payment-requests/{paymentRequest}/vendor', [PaymentRequestController::class, 'vendor'])
    ->whereNumber('paymentRequest')
    ->name('payment-requests.vendor');
Route::post('payment-requests/{paymentRequest}/reassess', [PaymentRequestController::class, 'reassess'])
    ->whereNumber('paymentRequest')
    ->name('payment-requests.reassess');
Route::get('payment-requests/{paymentRequest}/files/{file}', [PaymentRequestFileController::class, 'download'])
    ->whereNumber(['paymentRequest', 'file'])
    ->middleware('signed')
    ->name('payment-requests.files.download');
Route::get('payment-requests/{paymentRequest}/files/{file}/preview/{page}', [PaymentRequestFileController::class, 'preview'])
    ->whereNumber(['paymentRequest', 'file', 'page'])
    ->middleware('signed')
    ->name('payment-requests.files.preview');

Route::post('payment-requests/{paymentRequest}/submit', [PaymentRequestWorkflowController::class, 'submit'])->whereNumber('paymentRequest')->name('payment-requests.submit');
Route::post('payment-requests/{paymentRequest}/verifications', [PaymentRequestWorkflowController::class, 'verify'])->whereNumber('paymentRequest')->name('payment-requests.verifications.store');
Route::post('payment-requests/{paymentRequest}/reject', [PaymentRequestWorkflowController::class, 'reject'])->whereNumber('paymentRequest')->name('payment-requests.reject');
Route::post('payment-requests/{paymentRequest}/cancel', [PaymentRequestWorkflowController::class, 'cancel'])->whereNumber('paymentRequest')->name('payment-requests.cancel');
Route::post('payment-requests/{paymentRequest}/reopen', [PaymentRequestWorkflowController::class, 'reopen'])->whereNumber('paymentRequest')->name('payment-requests.reopen');
Route::post('payment-requests/{paymentRequest}/revoke', [PaymentRequestWorkflowController::class, 'revoke'])->whereNumber('paymentRequest')->name('payment-requests.revoke');
Route::post('payment-requests/{paymentRequest}/mark-paid', [PaymentRequestWorkflowController::class, 'markPaid'])->whereNumber('paymentRequest')->name('payment-requests.mark-paid');
Route::post('payment-requests/{paymentRequest}/reveal', [PaymentRequestWorkflowController::class, 'reveal'])->whereNumber('paymentRequest')->middleware('throttle:sp-reveal')->name('payment-requests.reveal');
Route::post('payment-requests/{paymentRequest}/evidence-packet', [PaymentRequestWorkflowController::class, 'evidencePacket'])->whereNumber('paymentRequest')->name('payment-requests.evidence-packet');
Route::post('payment-requests/{paymentRequest}/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->whereNumber(['paymentRequest', 'approval'])->name('payment-requests.approvals.approve');
Route::post('payment-requests/{paymentRequest}/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->whereNumber(['paymentRequest', 'approval'])->name('payment-requests.approvals.reject');
Route::post('payment-requests/{paymentRequest}/approvals/{approval}/reassign', [ApprovalController::class, 'reassign'])->whereNumber(['paymentRequest', 'approval'])->name('payment-requests.approvals.reassign');

Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');

Route::get('vendors', [VendorController::class, 'index'])->name('vendors.index');
Route::get('vendors/create', [VendorController::class, 'create'])->name('vendors.create');
Route::post('vendors', [VendorController::class, 'store'])->middleware('plan.limit:vendors')->name('vendors.store');
Route::get('vendors/{vendor}', [VendorController::class, 'show'])->whereNumber('vendor')->name('vendors.show');
Route::get('vendors/{vendor}/edit', [VendorController::class, 'edit'])->whereNumber('vendor')->name('vendors.edit');
Route::patch('vendors/{vendor}', [VendorController::class, 'update'])->whereNumber('vendor')->name('vendors.update');
Route::delete('vendors/{vendor}', [VendorController::class, 'destroy'])->whereNumber('vendor')->name('vendors.destroy');
Route::post('vendors/{vendor}/contacts', [VendorMasterDataController::class, 'storeContact'])->whereNumber('vendor')->name('vendors.contacts.store');
Route::patch('vendors/{vendor}/contacts/{contact}', [VendorMasterDataController::class, 'updateContact'])->whereNumber(['vendor', 'contact'])->name('vendors.contacts.update');
Route::delete('vendors/{vendor}/contacts/{contact}', [VendorMasterDataController::class, 'destroyContact'])->whereNumber(['vendor', 'contact'])->name('vendors.contacts.destroy');
Route::post('vendors/{vendor}/contacts/{contact}/verify', [VendorMasterDataController::class, 'verifyContact'])->whereNumber(['vendor', 'contact'])->name('vendors.contacts.verify');
Route::post('vendors/{vendor}/domains', [VendorMasterDataController::class, 'storeDomain'])->whereNumber('vendor')->name('vendors.domains.store');
Route::delete('vendors/{vendor}/domains/{vendorDomain}', [VendorMasterDataController::class, 'destroyDomain'])->whereNumber(['vendor', 'vendorDomain'])->name('vendors.domains.destroy');
Route::post('vendors/{vendor}/bank-accounts', [VendorMasterDataController::class, 'storeAccount'])->whereNumber('vendor')->name('vendors.bank-accounts.store');
Route::post('vendors/{vendor}/bank-accounts/{account}/verify', [VendorMasterDataController::class, 'verifyAccount'])->whereNumber(['vendor', 'account'])->name('vendors.bank-accounts.verify');
Route::post('vendors/{vendor}/bank-accounts/{account}/retire', [VendorMasterDataController::class, 'retireAccount'])->whereNumber(['vendor', 'account'])->name('vendors.bank-accounts.retire');
Route::post('vendors/{vendor}/bank-accounts/{account}/reveal', [VendorMasterDataController::class, 'revealAccount'])->whereNumber(['vendor', 'account'])->middleware('throttle:sp-reveal')->name('vendors.bank-accounts.reveal');
Route::post('vendors/{vendor}/data-subject', DataSubjectController::class)->whereNumber('vendor')->name('vendors.data-subject');

Route::get('blocked-accounts', [BlockedAccountController::class, 'index'])->name('blocked-accounts.index');
Route::post('blocked-accounts', [BlockedAccountController::class, 'store'])->name('blocked-accounts.store');
Route::delete('blocked-accounts/{blocked}', [BlockedAccountController::class, 'destroy'])->whereNumber('blocked')->name('blocked-accounts.destroy');

Route::get('policies', [PolicyController::class, 'index'])->name('policies.index');
Route::post('policies/rules', [PolicyController::class, 'storeRule'])->middleware('plan.limit:approval_rules')->name('policies.rules.store');
Route::post('policies/rules/reorder', [PolicyController::class, 'reorder'])->name('policies.rules.reorder');
Route::patch('policies/rules/{rule}', [PolicyController::class, 'updateRule'])->whereNumber('rule')->name('policies.rules.update');
Route::delete('policies/rules/{rule}', [PolicyController::class, 'destroyRule'])->whereNumber('rule')->name('policies.rules.destroy');
Route::put('policies/limits/{user}', [PolicyController::class, 'limits'])->whereNumber('user')->name('policies.limits.update');
Route::put('policies/settings', [PolicyController::class, 'settings'])->name('policies.settings.update');
Route::post('policies/exchange-rates', [PolicyController::class, 'storeRate'])->name('policies.exchange-rates.store');
Route::delete('policies/exchange-rates/{rate}', [PolicyController::class, 'destroyRate'])->whereNumber('rate')->name('policies.exchange-rates.destroy');
Route::post('policies/simulate', [PolicyController::class, 'simulate'])->middleware('throttle:60,1')->name('policies.simulate');

Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
Route::post('reports/exports', [ReportController::class, 'storeExport'])->name('reports.exports.store');
Route::post('reports/exports/{export}/retry', [ReportController::class, 'retryExport'])->whereNumber('export')->name('reports.exports.retry');
Route::get('reports/exports/{export}/download', [ReportController::class, 'download'])->whereNumber('export')->middleware('signed')->name('reports.exports.download');
Route::post('evidence/verify', [ReportController::class, 'verifyChain'])->middleware('throttle:10,1')->name('evidence.verify');
