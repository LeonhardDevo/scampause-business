<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Approvals\Models\Approval;
use App\Domain\Evidence\EvidenceLedger;
use App\Domain\Evidence\Models\EvidenceEntry;
use App\Domain\Intake\Enums\PaymentRequestKind;
use App\Domain\Intake\Enums\PaymentRequestStatus;
use App\Domain\Intake\Models\PaymentRequest;
use App\Domain\Intake\Presenters\CaseFilePresenter;
use App\Domain\Intake\Presenters\Refs;
use App\Domain\Money\Money;
use App\Domain\Notifications\DomainNotifier;
use App\Domain\Reports\Enums\ExportKind;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Risk\Enums\RiskLevel;
use App\Domain\Risk\Models\RiskSignal;
use App\Domain\Risk\SignalPresenter;
use App\Domain\Support\Masking;
use App\Domain\Vendors\Enums\ContactSource;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Verification\Models\Verification;
use App\Locale\WithLocale;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Psr\Clock\ClockInterface;

final readonly class EvidencePacketBuilder
{
    public const int PAGE_SIZE = 1000;

    public const array GOVERNANCE_EVENTS = [
        'policy.rule_created', 'policy.rule_updated', 'policy.rule_deactivated', 'policy.limit_changed', 'policy.settings_changed',
        'member.role_changed', 'block.removed', 'bank_account.revealed',
    ];

    public function __construct(private CaseFilePresenter $presenter, private ClockInterface $clock) {}

    /**
     * @return array<string, mixed>
     */
    public function data(ReportExport $export, int $page = 1): array
    {
        $locale = (string) $export->getAttribute('locale') === 'ru' ? 'ru' : 'en';

        return WithLocale::run($locale, fn (): array => $export->getAttribute('kind') === ExportKind::EvidencePacket
            ? $this->packet($export, $locale)
            : $this->journal($export, max(1, $page), $locale));
    }

    /**
     * @return array<string, mixed>
     */
    private function packet(ReportExport $export, string $locale): array
    {
        $workspace = Workspace::query()->findOrFail((int) $export->getAttribute('workspace_id'));
        $request = PaymentRequest::query()->forWorkspace($workspace)->findOrFail((int) $export->getAttribute('payment_request_id'));
        $timezone = $workspace->timezone ?: 'UTC';
        $vendor = is_int($v = $request->getAttribute('vendor_id')) ? Vendor::query()->forWorkspace($workspace)->withTrashed()->find($v) : null;
        $level = $request->getAttribute('risk_level');
        $signals = is_int($a = $request->getAttribute('risk_assessment_id'))
            ? RiskSignal::query()->forWorkspace($workspace)->where('risk_assessment_id', $a)->orderByDesc('points')->orderBy('code')->get()
            : collect();
        $diff = $this->presenter->bankDiff($request, $workspace->id);
        $entries = EvidenceEntry::query()->forWorkspace($workspace)->where('payment_request_id', $request->getKey())->with('actor:id,name,deleted_at')->orderBy('seq')->get();
        $created = CarbonImmutable::instance($request->getAttribute('created_at'));
        $governance = EvidenceEntry::query()->forWorkspace($workspace)->whereNull('payment_request_id')->whereIn('event', self::GOVERNANCE_EVENTS)
            ->where('occurred_at', '>=', $created)->with('actor:id,name,deleted_at')->orderBy('seq')->get();
        /** @var PaymentRequestKind $kind */
        $kind = $request->getAttribute('kind');
        /** @var PaymentRequestStatus $status */
        $status = $request->getAttribute('status');
        $amount = $request->getAttribute('amount_minor');
        $currency = $request->getAttribute('currency');

        return [
            'workspace' => ['name' => $workspace->name, 'timezone' => $timezone],
            'locale' => $locale,
            'generated_at' => CarbonImmutable::instance($this->clock->now())->utc()->toIso8601ZuluString(),
            'request' => [
                'reference' => (string) $request->getAttribute('reference'),
                'title' => (string) $request->getAttribute('title'),
                'kind_label' => $kind->label($locale),
                'status_label' => $status->label($locale),
                'amount_formatted' => is_int($amount) && is_string($currency) ? Money::of($amount, $currency)->format($locale) : '—',
                'vendor_name' => $vendor !== null ? (string) $vendor->getAttribute('name') : null,
                'invoice_number' => is_string($n = $request->getAttribute('invoice_number')) ? $n : null,
                'due_date' => Refs::date($request->getAttribute('due_date')),
                'payee_masked' => DomainNotifier::payeeMasked($request),
                'risk' => [
                    'score' => (int) $request->getAttribute('risk_score'),
                    'level_label' => $level instanceof RiskLevel ? $level->label($locale) : '—',
                    'signals' => array_values($signals->map(static fn (RiskSignal $signal): array => [
                        'title' => SignalPresenter::title((string) $signal->getAttribute('code')),
                        'points' => (int) $signal->getAttribute('points'),
                        'explanation' => SignalPresenter::text((string) $signal->getAttribute('code'), 'explanation', is_array($e = $signal->getAttribute('evidence')) ? $e : []),
                    ])->all()),
                ],
            ],
            'bank_diff' => ['rows' => $diff['rows'] ?? []],
            'verifications' => $this->verifications($request, $workspace),
            'approvals' => $this->approvals($request, $workspace),
            'entries' => array_values($entries->map(fn (EvidenceEntry $entry): array => $this->entry($entry, $timezone))->all()),
            'controls_changed' => array_values($governance->map(fn (EvidenceEntry $entry): array => $this->entry($entry, $timezone))->all()),
            'disclaimer' => (string) __('domain.packet.disclaimer'),
            'labels' => $this->labels(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function journal(ReportExport $export, int $page, string $locale): array
    {
        $workspace = Workspace::query()->findOrFail((int) $export->getAttribute('workspace_id'));
        $timezone = $workspace->timezone ?: 'UTC';
        $from = $export->getAttribute('period_from');
        $to = $export->getAttribute('period_to');
        $start = $from instanceof \DateTimeInterface ? CarbonImmutable::parse($from->format('Y-m-d'), $timezone)->startOfDay()->utc() : CarbonImmutable::createFromTimestamp(0);
        $end = $to instanceof \DateTimeInterface ? CarbonImmutable::parse($to->format('Y-m-d'), $timezone)->endOfDay()->utc() : CarbonImmutable::instance($this->clock->now());

        if ($export->getAttribute('kind') === ExportKind::PreventedRisk) {
            $query = PaymentRequest::query()->forWorkspace($workspace)->where('status', PaymentRequestStatus::Rejected->value)
                ->whereNotNull('prevented_category')->whereBetween('decided_at', [$start, $end])->orderBy('decided_at')->orderBy('id');
            $total = (clone $query)->count();
            $rows = $query->forPage($page, self::PAGE_SIZE)->get();
            $entries = array_values($rows->map(static function (PaymentRequest $request) use ($timezone, $locale): array {
                $amount = $request->getAttribute('prevented_amount_base_minor');

                return [
                    'reference' => (string) $request->getAttribute('reference'),
                    'title' => (string) $request->getAttribute('title'),
                    'decided_at_local' => CarbonImmutable::instance($request->getAttribute('decided_at'))->setTimezone($timezone)->format('Y-m-d H:i'),
                    'reason' => (string) $request->getAttribute('decision_reason'),
                    'category' => (string) $request->getAttribute('prevented_category')?->value,
                    'amount_formatted' => is_int($amount) ? Money::of($amount, (string) $request->getAttribute('base_currency'))->format($locale) : '—',
                    'amount_minor' => is_int($amount) ? $amount : null,
                ];
            })->all());
        } else {
            $query = EvidenceEntry::query()->forWorkspace($workspace)->whereBetween('occurred_at', [$start, $end])->with('actor:id,name,deleted_at')->orderBy('seq');
            $total = (clone $query)->count();
            $entries = array_values($query->forPage($page, self::PAGE_SIZE)->get()->map(fn (EvidenceEntry $entry): array => $this->entry($entry, $timezone))->all());
        }

        return [
            'workspace' => ['name' => $workspace->name, 'timezone' => $timezone],
            'locale' => $locale,
            'kind' => $export->getAttribute('kind')?->value,
            'format' => $export->getAttribute('format')?->value,
            'period' => ['from' => $start->setTimezone($timezone)->toDateString(), 'to' => $end->setTimezone($timezone)->toDateString()],
            'entries' => $entries,
            'next_page' => $page * self::PAGE_SIZE < $total ? $page + 1 : null,
            'labels' => $this->labels(),
            'disclaimer' => (string) __('domain.packet.disclaimer'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(EvidenceEntry $entry, string $timezone): array
    {
        $event = (string) $entry->getAttribute('event');
        $payload = $entry->getAttribute('payload');
        $payload = is_array($payload) ? $payload : [];
        $occurred = CarbonImmutable::parse((string) $entry->getRawOriginal('occurred_at'))->utc();
        $material = EvidenceLedger::material(
            (int) $entry->getAttribute('seq'),
            (int) $entry->getAttribute('workspace_id'),
            is_int($p = $entry->getAttribute('payment_request_id')) ? $p : null,
            (string) $entry->getAttribute('subject_type'),
            (int) $entry->getAttribute('subject_id'),
            $event,
            is_int($a = $entry->getAttribute('actor_user_id')) ? $a : null,
            (string) $entry->getAttribute('channel')?->value,
            $occurred,
            $payload,
        );
        $label = __('domain.events.'.str_replace('.', '_', $event));

        return [
            'seq' => (int) $entry->getAttribute('seq'),
            'event' => $event,
            'event_label' => is_string($label) && ! str_starts_with($label, 'domain.events.') ? $label : $event,
            'actor' => $entry->actor instanceof User ? Refs::user($entry->actor)['name'] ?? null : null,
            'channel' => (string) $entry->getAttribute('channel')?->value,
            'occurred_at' => EvidenceLedger::timestamp($occurred),
            'occurred_at_local' => $occurred->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'payload' => $payload === [] ? new \stdClass : $payload,
            'prev_hash' => (string) $entry->getAttribute('prev_hash'),
            'hash' => (string) $entry->getAttribute('hash'),
            'material' => $material,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function verifications(PaymentRequest $request, Workspace $workspace): array
    {
        $timezone = $workspace->timezone ?: 'UTC';

        return array_values(Verification::query()->forWorkspace($workspace)->where('payment_request_id', $request->getKey())->with(['performer:id,name,deleted_at', 'contact'])->orderBy('occurred_at')->get()
            ->map(static function (Verification $verification) use ($timezone): array {
                $checklist = is_array($c = $verification->getAttribute('checklist')) ? $c : [];
                $source = $verification->getAttribute('phone_source');

                return [
                    'method_label' => (string) $verification->getAttribute('method')?->label(),
                    'outcome_label' => (string) $verification->getAttribute('outcome')?->label(),
                    'occurred_at_local' => CarbonImmutable::instance($verification->getAttribute('occurred_at'))->setTimezone($timezone)->format('Y-m-d H:i'),
                    'performed_by' => Refs::user($verification->performer)['name'] ?? '',
                    'spoken_to_name' => is_string($s = $verification->getAttribute('spoken_to_name')) ? $s : null,
                    'contact_name' => $verification->contact !== null ? (string) $verification->contact->getAttribute('name') : null,
                    'phone_masked' => Masking::phone(is_string($l = $verification->getAttribute('phone_dialed_last4')) ? $l : null),
                    'phone_source_label' => $source instanceof ContactSource ? $source->label() : null,
                    'source_ref' => $verification->contact !== null && is_string($r = $verification->contact->getAttribute('source_ref')) ? $r : null,
                    'checklist' => array_map(static fn (string $key): array => ['label' => (string) __('domain.checklist.'.$key), 'value' => (bool) ($checklist[$key] ?? false)], ['account_last4', 'amount', 'account_holder', 'change_initiated_by_vendor', 'invoice_number']),
                ];
            })->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function approvals(PaymentRequest $request, Workspace $workspace): array
    {
        $timezone = $workspace->timezone ?: 'UTC';

        return array_values(Approval::query()->forWorkspace($workspace)->where('payment_request_id', $request->getKey())->with('approver:id,name,deleted_at')->orderBy('approval_route_id')->orderBy('slot')->get()
            ->map(static function (Approval $approval) use ($workspace, $timezone): array {
                $role = $approval->approver?->roleIn($workspace);
                $decided = $approval->getAttribute('decided_at');

                return [
                    'slot' => (int) $approval->getAttribute('slot'),
                    'approver' => Refs::user($approval->approver)['name'] ?? '',
                    'role_label' => $role?->label() ?? '',
                    'status_label' => (string) $approval->getAttribute('status')?->label(),
                    'channel_label' => $approval->getAttribute('channel')?->label(),
                    'decided_at_local' => $decided instanceof \DateTimeInterface ? CarbonImmutable::instance($decided)->setTimezone($timezone)->format('Y-m-d H:i') : null,
                    'comment' => is_string($c = $approval->getAttribute('comment')) ? $c : null,
                ];
            })->all());
    }

    /**
     * @return array<string, string>
     */
    private function labels(): array
    {
        $labels = __('domain.packet.labels');

        return is_array($labels) ? array_map(static fn (mixed $value): string => (string) $value, $labels) : [];
    }

    public static function reasonLabel(string $reason): string
    {
        return CaseFilePresenter::reasonLabel($reason);
    }
}
