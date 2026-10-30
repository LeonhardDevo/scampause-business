<?php

declare(strict_types=1);

namespace App\Http\Controllers\Domain;

use App\Domain\Intake\Presenters\Refs;
use App\Domain\Vendors\BlocklistService;
use App\Domain\Vendors\Models\BlockedBankAccount;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Domain\Concerns\RunsWorkflow;
use App\Http\Requests\Domain\BankAccountRequest;
use App\Support\Paginated;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BlockedAccountController extends Controller
{
    use RunsWorkflow;

    public function index(CurrentWorkspace $current): Response
    {
        $this->allow($current, 'vendors.view');
        $workspace = $this->workspace($current);
        $page = BlockedBankAccount::query()->forWorkspace($workspace)
            ->with(['creator:id,name,deleted_at', 'sourcePaymentRequest:id,reference'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();
        $manage = $current->allows('blocklist.manage');

        return Inertia::render('blocked-accounts/Index', [
            'items' => Paginated::from($page, static fn (BlockedBankAccount $block): array => [
                'id' => (int) $block->getKey(),
                'scheme' => (string) Refs::enum($block->getAttribute('scheme')),
                'last4' => (string) $block->getAttribute('last4'),
                'bank_name' => is_string($n = $block->getAttribute('bank_name')) ? $n : null,
                'bank_country' => is_string($c = $block->getAttribute('bank_country')) ? $c : null,
                'reason' => (string) Refs::enum($block->getAttribute('reason')),
                'created_by' => Refs::user($block->creator) ?? ['id' => (int) $block->getAttribute('created_by'), 'name' => '', 'avatar' => null],
                'created_at' => (string) Refs::iso($block->getAttribute('created_at')),
                'request' => $block->sourcePaymentRequest !== null ? ['id' => (int) $block->sourcePaymentRequest->getKey(), 'reference' => (string) $block->sourcePaymentRequest->getAttribute('reference')] : null,
                'note' => $manage && is_string($note = $block->getAttribute('note')) ? $note : null,
            ]),
            'abilities' => ['manage' => $manage],
        ]);
    }

    public function store(BankAccountRequest $request, CurrentWorkspace $current, BlocklistService $blocklist): RedirectResponse
    {
        $this->allow($current, 'blocklist.manage');
        $workspace = $this->workspace($current);
        $user = $this->user($request);
        $data = $request->payload();
        $note = is_string($n = $request->validated('note')) ? $n : null;

        return $this->run(fn () => $blocklist->addManual($workspace, $user, $data['scheme'], $data['account_number'], $data['routing_code'], $note), 'domain.flash.blocked');
    }

    public function destroy(Request $request, int $blocked, CurrentWorkspace $current, BlocklistService $blocklist): RedirectResponse
    {
        $this->allow($current, 'blocklist.manage');
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $model = BlockedBankAccount::query()->whereKey($blocked)->first();
        abort_if($model === null, 404);
        $user = $this->user($request);

        return $this->run(fn () => $blocklist->unblock($model, $user, (string) $validated['reason']), 'domain.flash.unblocked');
    }
}
