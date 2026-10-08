<?php

namespace App\Application\Traffic;

use App\Domain\Traffic\Clock;
use App\Domain\Traffic\SystemClock;

final class SensorEventHandler
{
    private ?TrafficService $service = null;

    public function __construct(
        private readonly Clock $clock = new SystemClock,
    ) {}

    public function handle(SensorEvent $event): SensorEventResult
    {
        return $this->service()->sensorEvent($event);
    }

    public function handlePayload(array $payload): SensorEventResult
    {
        return $this->handle(SensorEvent::fromArray($payload));
    }

    private function service(): TrafficService
    {
        return $this->service ??= new TrafficService(
            clock: $this->clock,
            dispatcher: new OutboxEffectDispatcher($this->clock),
        );
    }
}
