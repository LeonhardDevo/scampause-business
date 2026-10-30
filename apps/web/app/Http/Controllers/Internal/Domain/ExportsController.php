<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal\Domain;

use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Privacy\RetentionPurger;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\EvidencePacketBuilder;
use App\Domain\Reports\ExportService;
use App\Domain\Reports\Models\ReportExport;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Internal\Domain\Concerns\InternalResponses;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class ExportsController extends Controller
{
    use InternalResponses;

    public function data(Request $request, int $export, EvidencePacketBuilder $builder): JsonResponse
    {
        $model = $this->export($request, $export);

        if ($model === null) {
            return self::error('not_found', 404);
        }

        if ($model->getAttribute('status') === ExportStatus::Queued) {
            $model->forceFill(['status' => ExportStatus::Generating])->save();
        }

        return response()->json($builder->data($model, max(1, (int) $request->query('page', '1'))));
    }

    public function file(Request $request, int $export, ExportService $exports): JsonResponse
    {
        $request->validate([
            'workspace_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:51200'],
            'sha256' => ['required', 'string', 'size:64'],
            'chain_verified' => ['required'],
        ]);
        $model = $this->export($request, $export);
        $file = $request->file('file');

        if ($model === null || ! $file instanceof UploadedFile) {
            return self::error('not_found', 404);
        }

        return $this->guard(function () use ($exports, $model, $file, $request): JsonResponse {
            $exports->storeFile($model, (string) $file->get(), (string) $request->input('sha256'), filter_var($request->input('chain_verified'), FILTER_VALIDATE_BOOLEAN));

            return response()->json(['ok' => true]);
        });
    }

    public function failed(Request $request, int $export, ExportService $exports): JsonResponse
    {
        $validated = $request->validate(['workspace_id' => ['required', 'integer'], 'error' => ['required', 'string', 'max:2000']]);
        $model = $this->export($request, $export);

        if ($model === null) {
            return self::error('not_found', 404);
        }

        $exports->fail($model, (string) $validated['error']);

        return response()->json(['ok' => true]);
    }

    public function verifyAll(EvidenceLedger $ledger, DomainNotifier $notifier): JsonResponse
    {
        $count = 0;
        $broken = 0;

        foreach (Workspace::query()->orderBy('id')->cursor() as $workspace) {
            $count++;
            $result = $ledger->verify($workspace->id);

            if (! $result['ok']) {
                $broken++;
                $notifier->notify('evidence_chain_broken', DomainNotifier::membersWithRoles($workspace, [WorkspaceRole::Owner]), $workspace->id, null, [DomainNotifier::DATABASE, DomainNotifier::TELEGRAM, DomainNotifier::EMAIL], [
                    'seq' => (int) $result['first_broken_seq'],
                ], 'danger', '/reports');
            }
        }

        return response()->json(['workspaces' => $count, 'broken' => $broken]);
    }

    public function purge(RetentionPurger $purger): JsonResponse
    {
        return response()->json($purger->purge());
    }

    private function export(Request $request, int $export): ?ReportExport
    {
        $workspace = $this->workspace($request);

        return $workspace === null ? null : ReportExport::query()->forWorkspace($workspace)->find($export);
    }
}
