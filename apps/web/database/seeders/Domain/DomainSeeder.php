<?php

declare(strict_types=1);

namespace Database\Seeders\Domain;

use App\Knowledge\DocumentService;
use Carbon\CarbonImmutable;
use Database\Seeders\Domain\Support\FrozenClock;
use Database\Seeders\Domain\Support\OutboxSuppression;
use Illuminate\Database\Seeder;

final class DomainSeeder extends Seeder
{
    public const array SUPPRESSED_TOPICS = [...OutboxSuppression::DEFAULT_PATTERNS, 'evidence.packet.requested'];

    public ?CarbonImmutable $now = null;

    public bool $withKnowledge = true;

    public function run(DocumentService $documents): void
    {
        if (! (bool) config('scampause.demo_seed', true)) {
            return;
        }

        $now = $this->now ?? CarbonImmutable::now()->startOfMinute();
        $previous = CarbonImmutable::getTestNow();

        try {
            $users = FrozenClock::at($now, fn (): array => DemoUsersSeeder::ensure($now->subDays(130)));
            DemoWorkspaceSeeder::seed('northwind', 'Northwind Coffee Roasters', $users, $now);
            KedrWorkspaceSeeder::seed($users, $now);
        } finally {
            FrozenClock::restore($previous);
        }

        if ($this->withKnowledge) {
            (new KnowledgeSeeder)->run($documents);
        }
    }
}
