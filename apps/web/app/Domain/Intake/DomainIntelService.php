<?php

declare(strict_types=1);

namespace App\Domain\Intake;

use App\Domain\Billing\Entitlements;
use App\Domain\Intake\Models\DomainIntel;
use App\Domain\Intake\Models\DomainIntelSighting;
use App\Domain\Risk\Sender\DomainFacts;
use App\Domain\Support\Normalize\DomainNormalizer;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

final readonly class DomainIntelService
{
    public const string MODE_LIVE = 'live';

    public const string MODE_FAKE = 'fake';

    public const string MODE_CACHE_ONLY = 'cache_only';

    public function __construct(private Entitlements $entitlements, private ClockInterface $clock) {}

    public static function fakeAllowed(): bool
    {
        return config('scampause.domain_intel_driver') === 'fake'
            && in_array((string) config('app.env'), ['local', 'testing', 'e2e'], true);
    }

    public function mode(Workspace $workspace): string
    {
        if (self::fakeAllowed()) {
            return self::MODE_FAKE;
        }

        return $this->entitlements->allows($workspace, 'domain_intel_live') ? self::MODE_LIVE : self::MODE_CACHE_ONLY;
    }

    public function source(string $mode): string
    {
        return $mode === self::MODE_FAKE ? 'fake' : 'live';
    }

    /**
     * @param  list<string>  $domains
     * @return array{mode: string, facts: list<array<string, mixed>>, stale: list<string>}
     */
    public function lookup(Workspace $workspace, array $domains): array
    {
        $mode = $this->mode($workspace);
        $normalized = self::normalizeList($domains);
        $now = CarbonImmutable::instance($this->clock->now());
        $rows = DomainIntel::query()
            ->whereIn('domain', $normalized)
            ->where('source', $this->source($mode))
            ->get()
            ->keyBy('domain');

        $facts = [];
        $stale = [];

        foreach ($normalized as $domain) {
            $row = $rows->get($domain);
            $fresh = $row !== null && $row->getAttribute('fresh_until') instanceof \DateTimeInterface
                && CarbonImmutable::instance($row->getAttribute('fresh_until'))->greaterThan($now);

            if ($row !== null && ($fresh || $mode === self::MODE_CACHE_ONLY)) {
                $facts[] = self::toContract($row, $this->sighting($workspace->id, $domain, $now));
            } elseif ($mode !== self::MODE_CACHE_ONLY) {
                $stale[] = $domain;
            }
        }

        return ['mode' => $mode, 'facts' => $facts, 'stale' => $stale];
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     */
    public function store(Workspace $workspace, array $facts): int
    {
        $mode = $this->mode($workspace);

        if ($mode === self::MODE_CACHE_ONLY) {
            return 0;
        }

        $source = $this->source($mode);
        $now = CarbonImmutable::instance($this->clock->now());
        $stored = 0;

        foreach ($facts as $fact) {
            $domain = DomainNormalizer::registrable(is_string($fact['domain'] ?? null) ? $fact['domain'] : null);

            if ($domain === null) {
                continue;
            }

            $status = in_array($fact['rdap_status'] ?? null, ['ok', 'not_found', 'error', 'skipped'], true) ? (string) $fact['rdap_status'] : 'error';
            $checkedAt = is_string($fact['checked_at'] ?? null) ? CarbonImmutable::parse($fact['checked_at'])->utc() : $now;
            $dmarc = in_array($fact['dmarc_policy'] ?? null, ['none', 'quarantine', 'reject', 'absent'], true) ? (string) $fact['dmarc_policy'] : null;
            $registered = is_string($fact['registered_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}/', $fact['registered_at']) === 1
                ? substr($fact['registered_at'], 0, 10)
                : null;

            DB::table('domain_intel')->upsert([[
                'domain' => $domain,
                'source' => $source,
                'unicode_form' => is_string($fact['unicode_form'] ?? null) ? mb_substr($fact['unicode_form'], 0, 253) : DomainNormalizer::unicode($domain),
                'has_mx' => is_bool($fact['has_mx'] ?? null) ? $fact['has_mx'] : null,
                'has_spf' => is_bool($fact['has_spf'] ?? null) ? $fact['has_spf'] : null,
                'dmarc_policy' => $dmarc,
                'registered_at' => $registered,
                'registrar' => is_string($fact['registrar'] ?? null) ? mb_substr($fact['registrar'], 0, 255) : null,
                'rdap_status' => $status,
                'checked_at' => $checkedAt,
                'fresh_until' => $status === 'error' ? $checkedAt->addHour() : $checkedAt->addDay(),
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['domain', 'source'], ['unicode_form', 'has_mx', 'has_spf', 'dmarc_policy', 'registered_at', 'registrar', 'rdap_status', 'checked_at', 'fresh_until', 'updated_at']);

            $this->sighting($workspace->id, $domain, $now);
            $stored++;
        }

        return $stored;
    }

    /**
     * @param  list<string>  $domains
     * @return array<string, DomainFacts>
     */
    public function factsFor(Workspace $workspace, array $domains): array
    {
        $mode = $this->mode($workspace);
        $rows = DomainIntel::query()
            ->whereIn('domain', self::normalizeList($domains))
            ->where('source', $this->source($mode))
            ->get();
        $facts = [];

        foreach ($rows as $row) {
            $registered = $row->getAttribute('registered_at');
            $dmarc = $row->getAttribute('dmarc_policy');
            $facts[(string) $row->getAttribute('domain')] = new DomainFacts(
                (string) $row->getAttribute('domain'),
                $registered instanceof \DateTimeInterface ? CarbonImmutable::instance($registered) : null,
                is_string($dmarc) ? $dmarc : null,
                (string) $row->getAttribute('rdap_status'),
            );
        }

        return $facts;
    }

    public function firstSeen(int $workspaceId, string $domain): ?CarbonImmutable
    {
        $sighting = DomainIntelSighting::query()->forWorkspace($workspaceId)->where('domain', $domain)->first();
        $at = $sighting?->getAttribute('first_checked_at');

        return $at instanceof \DateTimeInterface ? CarbonImmutable::instance($at) : null;
    }

    /**
     * @param  list<string>  $domains
     * @return list<string>
     */
    public static function normalizeList(array $domains): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $domain): ?string => DomainNormalizer::registrable($domain),
            array_slice($domains, 0, 50),
        ))));
    }

    /**
     * @return array<string, mixed>
     */
    private static function toContract(DomainIntel $row, CarbonImmutable $firstSeen): array
    {
        $registered = $row->getAttribute('registered_at');

        return [
            'domain' => $row->getAttribute('domain'),
            'unicode_form' => $row->getAttribute('unicode_form'),
            'has_mx' => $row->getAttribute('has_mx'),
            'has_spf' => $row->getAttribute('has_spf'),
            'dmarc_policy' => $row->getAttribute('dmarc_policy'),
            'registered_at' => $registered instanceof \DateTimeInterface ? CarbonImmutable::instance($registered)->toDateString() : null,
            'registrar' => $row->getAttribute('registrar'),
            'rdap_status' => $row->getAttribute('rdap_status'),
            'source' => $row->getAttribute('source'),
            'checked_at' => $firstSeen->toIso8601ZuluString(),
        ];
    }

    private function sighting(int $workspaceId, string $domain, CarbonImmutable $now): CarbonImmutable
    {
        DB::table('domain_intel_sightings')->insertOrIgnore([
            'workspace_id' => $workspaceId,
            'domain' => $domain,
            'first_checked_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->firstSeen($workspaceId, $domain) ?? $now;
    }
}
