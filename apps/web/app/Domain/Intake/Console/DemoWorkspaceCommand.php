<?php

declare(strict_types=1);

namespace App\Domain\Intake\Console;

use App\Knowledge\DocumentService;
use Carbon\CarbonImmutable;
use Database\Seeders\Domain\DemoUsersSeeder;
use Database\Seeders\Domain\DemoWorkspaceSeeder;
use Database\Seeders\Domain\KnowledgeSeeder;
use Database\Seeders\Domain\Support\FrozenClock;
use Illuminate\Console\Command;

final class DemoWorkspaceCommand extends Command
{
    protected $signature = 'scampause:demo-workspace {slug : Slug of the demo workspace, e.g. northwind-en-light} {--name=Northwind Coffee Roasters : Display name} {--knowledge : Also upload the seed knowledge documents}';

    protected $description = 'Create an isolated copy of the Northwind demo workspace (non-production environments only).';

    public function handle(DocumentService $documents): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('Demo workspaces cannot be created in production.');

            return self::FAILURE;
        }

        $slug = (string) $this->argument('slug');

        if (preg_match('/^[a-z0-9][a-z0-9-]{1,60}$/', $slug) !== 1) {
            $this->error('The slug may contain lowercase letters, digits and dashes only.');

            return self::INVALID;
        }

        $now = CarbonImmutable::now()->startOfMinute();
        $users = FrozenClock::at($now, fn (): array => DemoUsersSeeder::ensure($now->subDays(130)));
        $workspace = DemoWorkspaceSeeder::seed($slug, (string) $this->option('name'), $users, $now);

        if ((bool) $this->option('knowledge')) {
            KnowledgeSeeder::seedWorkspace($documents, $workspace, 'northwind', $users['alex']);
        }

        $this->info('Demo workspace '.$workspace->slug.' (#'.$workspace->id.') is ready.');

        return self::SUCCESS;
    }
}
