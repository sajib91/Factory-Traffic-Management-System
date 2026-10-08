<?php

namespace App\Application\Traffic;

use App\Domain\Traffic\Clock;
use App\Domain\Traffic\SendControllerCommand;
use App\Domain\Traffic\SystemClock;
use App\Infrastructure\Models\ControllerCommand;

final class OutboxEffectDispatcher implements EffectDispatcher
{
    public function __construct(
        private readonly Clock $clock = new SystemClock,
    ) {}

    public function dispatch(string $commandId, SendControllerCommand $effect): void
    {
        ControllerCommand::query()
            ->where('command_id', $commandId)
            ->update([
                'status' => 'DISPATCHED',
                'sent_at' => $this->clock->now(),
            ]);
    }
}
