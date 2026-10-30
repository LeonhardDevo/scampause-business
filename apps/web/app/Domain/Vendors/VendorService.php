<?php

declare(strict_types=1);

namespace App\Domain\Vendors;

use App\Audit\Auditor;
use App\Billing\PlanLimits;
use App\Domain\Support\Normalize\DomainNormalizer;
use App\Domain\Support\Normalize\TaxId;
use App\Domain\Vendors\Enums\DomainSource;
use App\Domain\Vendors\Enums\VendorKind;
use App\Domain\Vendors\Enums\VendorStatus;
use App\Domain\Vendors\Models\Vendor;
use App\Domain\Vendors\Models\VendorDomain;
use App\Domain\Workflow\ReassessmentService;
use App\Domain\Workflow\WorkflowException;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

final readonly class VendorService
{
    public const string LIMIT_KEY = 'vendors';

    private const array AUDITED = ['kind', 'name', 'legal_name', 'tax_id', 'country', 'default_currency', 'status', 'payment_terms_days', 'linked_user_id'];

    public function __construct(
        private Auditor $auditor,
        private PlanLimits $limits,
        private ReassessmentService $reassessment,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Workspace $workspace, User $actor, array $data): Vendor
    {
        $this->limits->ensure($workspace, self::LIMIT_KEY);

        return DB::transaction(function () use ($workspace, $actor, $data): Vendor {
            $vendor = new Vendor;
            $vendor->forceFill([
                'workspace_id' => $workspace->id,
                ...$this->attributes($data),
                'created_by' => $actor->id,
            ])->save();

            $this->auditor->record('vendor.created', $vendor, metadata: ['kind' => $vendor->getAttribute('kind')?->value], actor: $actor, workspace: $workspace, subjectLabel: (string) $vendor->getAttribute('name'));

            return $vendor;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Vendor $vendor, User $actor, array $data): Vendor
    {
        return DB::transaction(function () use ($vendor, $actor, $data): Vendor {
            $before = $this->snapshot($vendor);
            $vendor->forceFill($this->attributes($data));
            $dirty = array_keys($vendor->getDirty());
            $vendor->save();
            $this->auditor->record('vendor.updated', $vendor, Auditor::diff($before, $this->snapshot($vendor), self::AUDITED), actor: $actor, workspace: (int) $vendor->getAttribute('workspace_id'), subjectLabel: (string) $vendor->getAttribute('name'));

            if (array_intersect($dirty, ['tax_id_normalized', 'default_currency', 'status', 'kind']) !== []) {
                $this->reassessment->forVendorAfterCommit((int) $vendor->getAttribute('workspace_id'), (int) $vendor->getKey());
            }

            return $vendor;
        });
    }

    public function archive(Vendor $vendor, User $actor): void
    {
        DB::transaction(function () use ($vendor, $actor): void {
            $vendor->forceFill(['status' => VendorStatus::Archived])->save();
            $vendor->delete();
            $this->auditor->record('vendor.archived', $vendor, actor: $actor, workspace: (int) $vendor->getAttribute('workspace_id'), subjectLabel: (string) $vendor->getAttribute('name'));
        });
    }

    public function addDomain(Vendor $vendor, User $actor, string $raw, DomainSource $source): VendorDomain
    {
        $domain = DomainNormalizer::registrable($raw);

        if ($domain === null) {
            throw new WorkflowException('invalid_domain', 'domain');
        }

        if (in_array($domain, (array) config('scampause.free_mail_domains', []), true)) {
            throw new WorkflowException('free_mail_domain', 'domain');
        }

        $workspaceId = (int) $vendor->getAttribute('workspace_id');

        if (VendorDomain::query()->forWorkspace($workspaceId)->where('vendor_id', $vendor->getKey())->where('domain', $domain)->exists()) {
            throw new WorkflowException('domain_exists', 'domain');
        }

        return DB::transaction(function () use ($vendor, $actor, $domain, $source, $workspaceId): VendorDomain {
            $model = new VendorDomain;
            $model->forceFill([
                'workspace_id' => $workspaceId,
                'vendor_id' => $vendor->getKey(),
                'domain' => $domain,
                'source' => $source,
                'created_by' => $actor->id,
            ])->save();
            $this->auditor->record('vendor.domain_added', $model, metadata: ['domain' => $domain], actor: $actor, workspace: $workspaceId, subjectLabel: (string) $vendor->getAttribute('name'));
            $this->reassessment->forVendorAfterCommit($workspaceId, (int) $vendor->getKey());

            return $model;
        });
    }

    public function removeDomain(VendorDomain $domain, User $actor): void
    {
        DB::transaction(function () use ($domain, $actor): void {
            $workspaceId = (int) $domain->getAttribute('workspace_id');
            $domain->delete();
            $this->auditor->record('vendor.domain_removed', $domain, metadata: ['domain' => (string) $domain->getAttribute('domain')], actor: $actor, workspace: $workspaceId, subjectLabel: (string) $domain->getAttribute('domain'));
            $this->reassessment->forVendorAfterCommit($workspaceId, (int) $domain->getAttribute('vendor_id'));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $out = [];
        $map = ['kind', 'name', 'legal_name', 'aliases', 'tax_id', 'country', 'default_currency', 'status', 'payment_terms_days', 'notes', 'linked_user_id'];

        foreach ($map as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];
            $out[$key] = match ($key) {
                'kind' => $value instanceof VendorKind ? $value : VendorKind::from((string) $value),
                'status' => $value instanceof VendorStatus ? $value : VendorStatus::from((string) $value),
                'aliases' => array_values(array_filter(array_map(static fn (mixed $alias): string => mb_substr(trim((string) $alias), 0, 120, 'UTF-8'), is_array($value) ? $value : []), static fn (string $alias): bool => $alias !== '')),
                'country', 'default_currency' => is_string($value) && $value !== '' ? strtoupper($value) : null,
                'name' => trim((string) $value),
                'payment_terms_days', 'linked_user_id' => $value === null || $value === '' ? null : (int) $value,
                default => is_string($value) && trim($value) !== '' ? trim($value) : null,
            };
        }

        if (array_key_exists('tax_id', $out)) {
            $out['tax_id_normalized'] = TaxId::normalize(is_string($out['tax_id']) ? $out['tax_id'] : null);
        }

        if (isset($out['kind']) && $out['kind'] !== VendorKind::Employee) {
            $out['linked_user_id'] = null;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function snapshot(Vendor $vendor): array
    {
        $out = [];

        foreach (self::AUDITED as $key) {
            $out[$key] = $vendor->getAttribute($key);
        }

        return $out;
    }
}
