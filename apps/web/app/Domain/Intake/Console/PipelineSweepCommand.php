<?php

declare(strict_types=1);

namespace App\Domain\Intake\Console;

use App\Domain\Intake\PipelineService;
use Illuminate\Console\Command;

final class PipelineSweepCommand extends Command
{
    protected $signature = 'scampause:pipeline-sweep';

    protected $description = 'Republish or complete payment request pipelines that stopped progressing';

    public function handle(PipelineService $pipeline): int
    {
        $result = $pipeline->sweep();
        $this->info(sprintf('republished=%d completed=%d', $result['republished'], $result['completed']));

        return self::SUCCESS;
    }
}
