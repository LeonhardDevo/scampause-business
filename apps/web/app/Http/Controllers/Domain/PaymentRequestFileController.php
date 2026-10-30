<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain;

use App\Audit\Auditor;
use App\Domain\Intake\Enums\FileRole;
use App\Domain\Intake\Files\FileVault;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Models\PaymentRequestFile;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

final class PaymentRequestFileController extends Controller
{
    public function download(PaymentRequest $paymentRequest, int $file, FileVault $vault, Auditor $auditor): Response
    {
        $this->authorize('view', $paymentRequest);
        $model = $this->find($paymentRequest, $file);
        abort_if($model->getAttribute('role') === FileRole::Preview, 404);
        $bytes = $vault->read($model);
        abort_if($bytes === null, 404);

        $auditor->record('payment_request.file_downloaded', $paymentRequest, metadata: ['file_id' => $model->getKey()], subjectLabel: (string) $paymentRequest->getAttribute('reference'));
        $name = str_replace(['"', '\\'], '_', (string) $model->getAttribute('original_name'));

        return response($bytes, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function preview(PaymentRequest $paymentRequest, int $file, int $page, FileVault $vault): Response
    {
        $this->authorize('view', $paymentRequest);
        $model = $this->find($paymentRequest, $file);
        abort_unless($model->getAttribute('role') === FileRole::Preview, 404);
        $bytes = $vault->read($model);
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    private function find(PaymentRequest $paymentRequest, int $file): PaymentRequestFile
    {
        $model = PaymentRequestFile::query()
            ->where('payment_request_id', $paymentRequest->getKey())
            ->whereKey($file)
            ->first();
        abort_if($model === null, 404);

        return $model;
    }
}
