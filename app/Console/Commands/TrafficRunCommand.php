<?php

namespace App\Console\Commands;

use App\Application\Traffic\TrafficService;
use App\Infrastructure\Models\Junction;
use Illuminate\Console\Command;

final class TrafficRunCommand extends Command
{
    protected $signature = 'traffic:run';

    protected $description = 'Runs the traffic engine tick loop for every junction (CLI only, no sleeps in HTTP paths).';

    public function handle(TrafficService $service): int
    {
        $this->call('traffic:recover');

        $running = true;
        $this->stopOnSignals($running);

        while ($running) {
            $startedAt = microtime(true);

            foreach (Junction::query()->pluck('id') as $junctionId) {
                try {
                    $service->tick($junctionId);
                } catch (\LogicException $exception) {
                    $this->warn(sprintf('%s requires recovery: %s', $junctionId, $exception->getMessage()));
                    $service->recover($junctionId);
                }
            }

            $elapsed = microtime(true) - $startedAt;
            $remaining = 1 - $elapsed;

            if ($remaining > 0) {
                usleep((int) ($remaining * 1_000_000));
            }
        }

        return self::SUCCESS;
    }

    private function stopOnSignals(bool &$running): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, static function () use (&$running): void {
                $running = false;
            });
        }
    }
}
