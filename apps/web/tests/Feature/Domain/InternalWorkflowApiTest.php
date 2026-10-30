<?php

declare(strict_types=1);

use App\Domain\Approvals\Enums\ApprovalStatus;
use App\Domain\Approvals\Models\Approval;
use App\Domain\Approvals\Models\ApprovalRoute;
use App\Domain\Approvals\StepTokens;
use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Notifications\Models\SpNotification;
use App\Domain\Policy\PolicyService;
use App\Domain\Reports\ExportService;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Vendors\ContactService;
use App\Domain\Vendors\Enums\ContactSource;
use App\Domain\Verification\Models\EmployeeAlert;
use App\Domain\Workflow\SubmissionService;
use App\Enums\WorkspaceRole;
use App\Events\DeliveryReported;
use App\Internal\InternalKeyring;
use App\Internal\InternalSignature;
use App\Models\Delivery;
use App\Models\OutboxMessage;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Workspaces\MemberService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Domain\Scenario;

beforeEach(function (): void {
    Storage::fake('local');
});

function linkTelegram(User $user, int $workspaceId, int $telegramId): TelegramAccount
{
    return TelegramAccount::query()->create([
        'user_id' => $user->id, 'workspace_id' => $workspaceId, 'telegram_user_id' => $telegramId, 'chat_id' => $telegramId, 'linked_at' => now(),
    ]);
}

/**
 * @return array{0: Scenario, 1: PaymentRequest, 2: Approval}
 */
function submittedScenario(bool $telegram = true): array
{
    config(['billing.plans.free.entitlements.telegram_approvals' => true]);
    $s = Scenario::make();

    if ($telegram) {
        linkTelegram($s->user('owner'), $s->workspace->id, 900001);
    }

    $vendor = $s->vendor();
    $s->verifiedAccount($vendor);
    $request = $s->request('bookkeeper', ['vendorId' => (int) $vendor->getKey()]);
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));
    $approval = Approval::query()->withoutGlobalScopes()->where('payment_request_id', $request->getKey())->firstOrFail();

    return [$s, $request->refresh(), $approval];
}

function stepToken(Approval $approval): string
{
    $token = StepTokens::issue();
    $approval->forceFill(['telegram_nonce_hash' => $token['hash']])->save();

    return $token['token'];
}

it('publishes approval.requested with Telegram actions and an e-mail', function (): void {
    [, $request, $approval] = submittedScenario();
    $message = OutboxMessage::query()->where('topic', 'approval.requested')->firstOrFail();
    $payload = $message->payload;

    expect($payload['approval_id'])->toBe($approval->id)
        ->and($payload['payment_request_id'])->toBe($request->id)
        ->and($payload['recipient']['telegram_user_id'])->toBe(900001)
        ->and($payload['telegram']['template'])->toBe('sp.approval_requested')
        ->and(array_column($payload['telegram']['actions'], 'type'))->toBe(['sp.apv', 'sp.rej'])
        ->and($payload['telegram']['params']['url'])->toEndWith('/payment-requests/'.$request->id.'?tab=approvals')
        ->and($payload['email']['subject'])->toContain($request->getAttribute('reference'))
        ->and($payload['email']['html'])->not->toContain('<script')
        ->and(array_keys($payload['sp_notification_ids']))->toEqualCanonicalizing(['telegram', 'email']);
});

it('approves in Telegram in two steps and refuses stale buttons', function (): void {
    [$s, $request, $approval] = submittedScenario();
    $token = stepToken($approval);
    $base = ['subject_id' => $approval->id, 'workspace_id' => $s->workspace->id, 'telegram_user_id' => 900001, 'chat_id' => 900001, 'message_id' => 5, 'language_code' => 'en'];

    $first = $this->internal('POST', '/internal/v1/scampause/telegram/callback', [...$base, 'action_type' => 'sp.apv', 'step_token' => $token])->assertOk();
    expect($first->json('edit.template'))->toBe('sp.approve_confirm');
    $confirmToken = $first->json('edit.actions.0.step_token');

    $this->internal('POST', '/internal/v1/scampause/telegram/callback', [...$base, 'action_type' => 'sp.apv_confirm', 'step_token' => $token])
        ->assertOk()->assertJsonPath('answer.text', 'This button is no longer valid.');
    $this->internal('POST', '/internal/v1/scampause/telegram/callback', [...$base, 'action_type' => 'sp.apv_confirm', 'step_token' => $confirmToken])
        ->assertOk()->assertJsonPath('edit.template', 'sp.approved_ack')->assertJsonPath('edit.params.remaining', 0);

    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::Approved)
        ->and($approval->refresh()->getAttribute('channel')?->value)->toBe('telegram')
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('event', 'approval.approved')->value('channel')?->value)->toBe('telegram');
});

it('blocks Telegram approval on plans without it but still allows rejection', function (): void {
    [$s, $request, $approval] = submittedScenario();
    config(['billing.plans.free.entitlements.telegram_approvals' => false]);
    $token = stepToken($approval);
    $base = ['subject_id' => $approval->id, 'workspace_id' => $s->workspace->id, 'telegram_user_id' => 900001, 'language_code' => 'en'];

    $blocked = $this->internal('POST', '/internal/v1/scampause/telegram/callback', [...$base, 'action_type' => 'sp.apv', 'step_token' => $token])->assertOk();
    expect($blocked->json('answer.show_alert'))->toBeTrue()
        ->and($blocked->json('answer.text'))->toContain('your plan does not include Telegram approvals')
        ->and(array_column($blocked->json('edit.actions'), 'type'))->toBe(['sp.rej'])
        ->and($blocked->json('edit.params.url'))->toEndWith('?tab=approvals');

    $rejectToken = $blocked->json('edit.actions.0.step_token');
    $reasons = $this->internal('POST', '/internal/v1/scampause/telegram/callback', [...$base, 'action_type' => 'sp.rej', 'step_token' => $rejectToken])->assertOk();
    $reasonToken = $reasons->json('edit.actions.0.step_token');
    $this->internal('POST', '/internal/v1/scampause/telegram/callback', [...$base, 'action_type' => 'sp.rej_reason', 'reason' => 'duplicate', 'step_token' => $reasonToken])
        ->assertOk()->assertJsonPath('edit.template', 'sp.rejected_ack');

    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::Rejected)
        ->and($request->getAttribute('prevented_category')?->value)->toBe('duplicate');
});

it('answers ex-members and unlinked users without deciding', function (): void {
    [$s, $request, $approval] = submittedScenario();
    $token = stepToken($approval);
    $s->workspace->members()->detach($s->user('owner')->id);

    $this->internal('POST', '/internal/v1/scampause/telegram/callback', ['action_type' => 'sp.apv', 'subject_id' => $approval->id, 'workspace_id' => $s->workspace->id, 'telegram_user_id' => 900001, 'step_token' => $token])
        ->assertOk()->assertJsonPath('answer.show_alert', true);
    $this->internal('POST', '/internal/v1/scampause/telegram/callback', ['action_type' => 'sp.apv', 'subject_id' => $approval->id, 'workspace_id' => $s->workspace->id, 'telegram_user_id' => 1, 'step_token' => $token])
        ->assertOk()->assertJsonPath('answer.text', 'Link your ScamPause account first.');

    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::AwaitingApproval);
});

it('lists pending approvals across workspaces and resolves references', function (): void {
    [$s, $request] = submittedScenario();

    $this->internal('GET', '/internal/v1/scampause/telegram/pending?telegram_user_id=900001')
        ->assertOk()->assertJsonPath('items.0.reference', $request->getAttribute('reference'))->assertJsonPath('items.0.workspace.id', $s->workspace->id);
    $this->internal('GET', '/internal/v1/scampause/telegram/request?telegram_user_id=900001&reference='.$request->getAttribute('reference'))
        ->assertOk()->assertJsonPath('template', 'sp.request_status');
    $this->internal('GET', '/internal/v1/scampause/telegram/request?telegram_user_id=900001&reference=SP-99999')->assertNotFound();
    $this->internal('POST', '/internal/v1/scampause/telegram/workspace', ['telegram_user_id' => 900001])
        ->assertOk()->assertJsonPath('workspaces.0.current', true);
});

it('stores Telegram message ids, sends reminders and escalates once', function (): void {
    [$s, , $approval] = submittedScenario();
    $route = ApprovalRoute::query()->withoutGlobalScopes()->findOrFail($approval->getAttribute('approval_route_id'));
    $ws = $s->workspace->id;

    $this->internal('POST', "/internal/v1/scampause/approvals/{$approval->id}/telegram-message", ['workspace_id' => $ws, 'chat_id' => 900001, 'message_id' => 77])->assertOk();
    $this->internal('GET', "/internal/v1/scampause/approvals/{$approval->id}/telegram-message?workspace_id={$ws}")->assertOk()->assertJsonPath('message.message_id', 77);

    $this->travelTo(now()->setTime(12, 0));
    $reminder = $this->internal('POST', "/internal/v1/scampause/approvals/{$approval->id}/reminders", ['workspace_id' => $ws, 'sequence' => 2, 'total' => 2])->assertOk();
    expect($reminder->json('send'))->toBeTrue()
        ->and($reminder->json('telegram.template'))->toBe('sp.approval_reminder')
        ->and($reminder->json('telegram.reply_to_message_id'))->toBe(77)
        ->and($reminder->json('email.subject'))->toContain('Reminder')
        ->and($approval->refresh()->getAttribute('reminders_sent'))->toBe(1);

    $this->internal('POST', "/internal/v1/scampause/approval-routes/{$route->id}/escalate", ['workspace_id' => $ws])->assertOk()->assertJsonPath('escalated', true);
    $this->internal('POST', "/internal/v1/scampause/approval-routes/{$route->id}/escalate", ['workspace_id' => $ws])->assertOk()->assertJsonPath('escalated', false);
    $this->internal('POST', "/internal/v1/scampause/approval-routes/{$route->id}/expire", ['workspace_id' => $ws])->assertOk()->assertJsonPath('expired', false);
    $this->internal('POST', "/internal/v1/scampause/approvals/{$approval->id}/reminders", ['workspace_id' => $ws + 1000, 'sequence' => 1])->assertNotFound();
});

it('reschedules reminders that fall in quiet hours', function (): void {
    [$s, , $approval] = submittedScenario();
    $this->travelTo(now()->setTime(23, 30));

    $this->internal('POST', "/internal/v1/scampause/approvals/{$approval->id}/reminders", ['workspace_id' => $s->workspace->id, 'sequence' => 1, 'total' => 2])
        ->assertOk()->assertJson(['send' => false, 'reason' => 'quiet_hours_rescheduled']);
});

it('sweeps expired routes across workspaces', function (): void {
    [, $request, $approval] = submittedScenario();
    ApprovalRoute::query()->withoutGlobalScopes()->whereKey($approval->getAttribute('approval_route_id'))->update(['expires_at' => now()->subHour()]);

    $this->internal('POST', '/internal/v1/scampause/approvals/sweep', [])->assertOk()->assertJsonPath('expired_route_ids.0', $approval->getAttribute('approval_route_id'));
    expect($request->refresh()->getAttribute('status'))->toBe(PaymentRequestStatus::Expired)
        ->and($approval->refresh()->getAttribute('status'))->toBe(ApprovalStatus::Expired);
});

it('serves assistant tools only within the member permissions', function (): void {
    [$s, $request] = submittedScenario(false);
    $ws = $s->workspace->id;
    $vendorId = (int) $request->getAttribute('vendor_id');

    $this->internal('POST', '/internal/v1/scampause/policy/evaluate', ['workspace_id' => $ws, 'user_id' => $s->user('bookkeeper')->id, 'amount' => '4000'], 'ai')
        ->assertOk()->assertJsonPath('approvals_required', 1)->assertJsonPath('amount_base.formatted', '$4,000.00');
    $this->internal('POST', '/internal/v1/scampause/policy/evaluate', ['workspace_id' => $ws, 'user_id' => $s->user('member')->id, 'amount' => '4000'], 'ai')->assertForbidden();
    $this->internal('GET', "/internal/v1/scampause/vendors/search?workspace_id={$ws}&user_id={$s->user('approver')->id}&q=Blue%20Ridge", null, 'ai')
        ->assertOk()->assertJsonPath('vendors.0.id', $vendorId);
    $this->internal('GET', "/internal/v1/scampause/vendors/{$vendorId}/contacts?workspace_id={$ws}&user_id={$s->user('approver')->id}", null, 'ai')
        ->assertOk()->assertJsonPath('callback_contacts.0.phone_e164', '+18285550142')->assertJsonPath('callback_contacts.0.source_ref', 'Supply Agreement BRP-2025-014, §9.2');
    $this->internal('GET', "/internal/v1/scampause/vendors/{$vendorId}/bank-history?workspace_id={$ws}&user_id={$s->user('member')->id}", null, 'ai')->assertForbidden();
    $this->internal('GET', "/internal/v1/scampause/vendors/{$vendorId}/bank-history?workspace_id={$ws}&user_id={$s->user('approver')->id}", null, 'ai')
        ->assertOk()->assertJsonPath('accounts.0.last4', '4417')->assertJsonMissingPath('accounts.0.account_number');
    $this->internal('GET', "/internal/v1/scampause/payment-requests/by-reference/{$request->getAttribute('reference')}?workspace_id={$ws}&user_id={$s->user('member')->id}", null, 'ai')->assertForbidden();
    $this->internal('GET', "/internal/v1/scampause/payment-requests/by-reference/{$request->getAttribute('reference')}?workspace_id={$ws}&user_id={$s->user('bookkeeper')->id}", null, 'ai')
        ->assertOk()->assertJsonPath('status', 'awaiting_approval')->assertJsonPath('title.untrusted_text', $request->getAttribute('title'));
    $this->internal('GET', "/internal/v1/scampause/approvals/pending?workspace_id={$ws}&user_id={$s->user('owner')->id}", null, 'ai')->assertOk()->assertJsonCount(1, 'items');
    $this->internal('GET', "/internal/v1/scampause/approvals/pending?workspace_id={$ws}&user_id={$s->user('owner')->id}")
        ->assertForbidden()->assertExactJson(['error' => 'caller_not_allowed']);
    $this->internal('POST', '/internal/v1/scampause/pipeline/sweep', [], 'ai')->assertForbidden();
});

it('hands evidence packet data to the worker and stores the rendered file', function (): void {
    [$s, $request] = submittedScenario(false);
    $export = app(ExportService::class)->requestPacket($request, $s->user('owner'));
    $ws = $s->workspace->id;

    $data = $this->internal('GET', "/internal/v1/scampause/exports/{$export->id}/data?workspace_id={$ws}")->assertOk();
    expect($data->json('request.reference'))->toBe($request->getAttribute('reference'))
        ->and($data->json('entries.0.material'))->toContain('"seq":')
        ->and(hash('sha256', $data->json('entries.0.prev_hash')."\n".$data->json('entries.0.material')))->toBe($data->json('entries.0.hash'))
        ->and($data->json('disclaimer'))->not->toBeEmpty();

    $bytes = '%PDF-1.7 fake packet';
    $file = UploadedFile::fake()->createWithContent('packet.pdf', $bytes);
    $uri = "/internal/v1/scampause/exports/{$export->id}/file";
    $keyring = app(InternalKeyring::class);
    $signature = (new InternalSignature($keyring->secretFor('worker') ?? $keyring->fallbackSecret()))->sign('POST', $uri, '');
    $server = $this->transformHeadersToServerVars(['X-Service' => 'worker', 'X-Signature' => $signature, 'X-Content-Sha256' => hash('sha256', $bytes), 'Accept' => 'application/json']);
    $this->call('POST', $uri, ['workspace_id' => $ws, 'sha256' => hash('sha256', $bytes), 'chain_verified' => '1'], [], ['file' => $file], $server)->assertOk();

    expect(app(ExportService::class)->read($export->refresh()))->toBe($bytes)
        ->and($export->getAttribute('chain_verified'))->toBeTrue();

    $this->internal('POST', "/internal/v1/scampause/exports/{$export->id}/failed", ['workspace_id' => $ws, 'error' => 'boom'])->assertOk();
    expect(ReportExport::query()->withoutGlobalScopes()->find($export->id)?->getAttribute('status')?->value)->toBe('failed');
});

it('maps delivery reports onto domain notification rows with evidence', function (): void {
    [, $request] = submittedScenario();
    $row = SpNotification::query()->withoutGlobalScopes()->where('channel', 'telegram')->where('payment_request_id', $request->id)->firstOrFail();
    $delivery = Delivery::query()->create(['reference_type' => 'sp_notification', 'reference_id' => (string) $row->id, 'channel' => 'telegram', 'status' => 'sent', 'provider_message_id' => '55']);
    DeliveryReported::dispatch($delivery);

    expect($row->refresh()->getAttribute('status'))->toBe('sent')
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('event', 'notification.delivered')->where('payment_request_id', $request->id)->exists())->toBeTrue();
});

it('verifies every chain and purges retention categories', function (): void {
    submittedScenario(false);

    $this->internal('POST', '/internal/v1/scampause/evidence/verify-all', [])->assertOk()->assertJson(['broken' => 0]);
    $this->internal('POST', '/internal/v1/scampause/retention/purge', [])->assertOk()->assertJsonStructure(['scampause_files', 'scampause_evidence', 'scampause_exports', 'scampause_notifications', 'scampause_domain_intel']);
});

it('rejects unsigned internal calls', function (): void {
    $this->postJson('/internal/v1/scampause/approvals/sweep', [])->assertUnauthorized();
});

it('requires verification again when an employee denies a payroll change', function (): void {
    $s = Scenario::make();
    $employee = $s->vendor('Jordan Lee', ['kind' => 'employee', 'linked_user_id' => $s->user('member')->id], '');
    linkTelegram($s->user('member'), $s->workspace->id, 900005);
    $request = $s->request('admin', ['vendorId' => (int) $employee->getKey(), 'kind' => PaymentRequestKind::PayrollChange, 'amountMinor' => 215000, 'payeeAccountNumber' => '7700457310', 'payeeRoutingCode' => '121000248']);
    $alert = EmployeeAlert::query()->withoutGlobalScopes()->where('payment_request_id', $request->id)->firstOrFail();
    $token = StepTokens::issue();
    $alert->forceFill(['nonce_hash' => $token['hash']])->save();

    expect($alert->getAttribute('channel'))->toBe('telegram');

    $this->internal('POST', '/internal/v1/scampause/telegram/callback', ['action_type' => 'sp.pay_not_me', 'subject_id' => $alert->id, 'workspace_id' => $s->workspace->id, 'telegram_user_id' => 900005, 'step_token' => $token['token']])
        ->assertOk()->assertJsonPath('edit.template', 'sp.payroll_answered');

    $request->refresh();
    expect($request->getAttribute('employee_response')?->value)->toBe('not_me')
        ->and($request->getAttribute('risk_score'))->toBeGreaterThanOrEqual(90)
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('event', 'employee.responded')->exists())->toBeTrue();
});

it('serves the e-mail employee page read-only and accepts one answer', function (): void {
    config(['inertia.testing.ensure_pages_exist' => false]);
    $s = Scenario::make();
    $employee = $s->vendor('Sam Worker', ['kind' => 'employee'], '');
    app(ContactService::class)->create($employee, $s->user('admin'), ['name' => 'Sam Worker', 'email' => 'sam@example.test', 'is_callback' => false, 'source' => ContactSource::InPerson]);
    $request = $s->request('admin', ['vendorId' => (int) $employee->getKey(), 'kind' => PaymentRequestKind::PayrollChange, 'amountMinor' => 215000, 'payeeAccountNumber' => '7700457310', 'payeeRoutingCode' => '121000248']);
    $email = OutboxMessage::query()->where('topic', 'email.send')->get()->first(fn ($m) => ($m->payload['to'] ?? null) === 'sam@example.test');
    expect($email)->not->toBeNull()
        ->and($email->payload['html'])->not->toContain('unsubscribe');

    preg_match('#(https?://[^"\s]+/employee-response/[^"\s]+)#', html_entity_decode($email->payload['html']), $m);
    $url = $m[1];
    $path = parse_url($url, PHP_URL_PATH);
    $token = basename((string) $path);

    $this->get($url)->assertOk();
    $this->get($url)->assertOk();
    expect($request->refresh()->getAttribute('employee_response'))->toBeNull();
    $this->get('/employee-response/'.$token)->assertForbidden();

    $this->post('/employee-response/'.$token, ['response' => 'it_was_me'])->assertOk();
    $this->post('/employee-response/'.$token, ['response' => 'not_me'])->assertOk();
    expect($request->refresh()->getAttribute('employee_response')?->value)->toBe('it_was_me');
});

it('revokes pending approvals when an approver loses the role and refills the slot', function (): void {
    config(['billing.plans.free.entitlements.telegram_approvals' => true]);
    $s = Scenario::make();
    app(PolicyService::class)->createRule($s->workspace, $s->user('owner'), [
        'name' => 'Dual control', 'is_active' => true, 'approvals_required' => 2, 'required_roles' => [], 'approver_roles' => ['owner', 'admin', 'approver'], 'verification_required' => false,
    ]);
    $vendor = $s->vendor();
    $s->verifiedAccount($vendor);
    $request = $s->request('bookkeeper', ['vendorId' => (int) $vendor->getKey()]);
    app(SubmissionService::class)->submit($request, $s->user('bookkeeper'));
    $before = Approval::query()->withoutGlobalScopes()->where('payment_request_id', $request->id)->pluck('approver_id')->all();
    expect($before)->toBe([$s->user('owner')->id, $s->user('admin')->id]);

    app(MemberService::class)->changeRole($s->workspace, $s->user('owner'), $s->user('admin'), WorkspaceRole::Bookkeeper);

    $live = Approval::query()->withoutGlobalScopes()->where('payment_request_id', $request->id)->where('status', 'pending')->orderBy('slot')->pluck('approver_id')->all();
    expect($live)->toBe([$s->user('owner')->id, $s->user('approver')->id])
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('event', 'approval.revoked_membership')->exists())->toBeTrue()
        ->and(EvidenceEntry::query()->withoutGlobalScopes()->where('event', 'member.role_changed')->whereNull('payment_request_id')->exists())->toBeTrue();
});
