<?php

namespace App\Application\Traffic;

final class ControllerEvent
{
    /**
     * @param  array<string, string>|null  $actualSignals
     */
    public function __construct(
        public readonly string $junctionId,
        public readonly string $commandId,
        public readonly ?array $actualSignals = null,
        public readonly ?string $eventType = null,
    ) {}
}
