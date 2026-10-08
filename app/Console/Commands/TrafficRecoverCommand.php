<?php

namespace App\Console\Commands;

use App\Application\Traffic\TrafficService;
use App\Infrastructure\Models\Junction;
use Illuminate\Console\Command;

final class TrafficRecoverCommand extends Command
{
    protected $signature = 'traffic:recover';

    protected $description = 'Put every junction into a safe recovery state and send ALL_RED commands.';

    public function handle(TrafficService $service): int
    {
        foreach (Junction::query()->pluck('id') as $junctionId) {
            $recovery = $service->recover($junctionId);
            $this->line(sprintf('%s recovery command %s', $junctionId, $recovery['command_id']));
        }

        return self::SUCCESS;
    }
}
