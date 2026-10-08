<?php

namespace App\Application\Traffic;

final class SensorEventResult
{
    /**
     * @param  list<string>  $commandIds
     */
    public function __construct(
        public readonly string $eventId,
        public readonly SensorEventOutcome $outcome,
        public readonly ?string $junctionId = null,
        public readonly ?string $reason = null,
        public readonly ?int $queueCountAfter = null,
        public readonly array $commandIds = [],
    ) {}
}
