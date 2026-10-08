<?php

namespace App\Application\Traffic;

use DateTimeImmutable;

final class SensorEvent
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $junctionId,
        public readonly string $direction,
        public readonly string $vehicleType,
        public readonly string $eventType,
        public readonly string $vehicleId,
        public readonly int $sequenceNo,
        public readonly DateTimeImmutable $occurredAt,
    ) {}

    public static function fromArray(array $payload): self
    {
        $required = [
            'event_id',
            'junction_id',
            'direction',
            'vehicle_type',
            'event_type',
            'vehicle_id',
            'sequence_no',
            'occurred_at',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
                throw new \InvalidArgumentException("Missing sensor event field: {$key}");
            }
        }

        if (! is_numeric($payload['sequence_no'])) {
            throw new \InvalidArgumentException('sequence_no must be numeric.');
        }

        try {
            $occurredAt = new DateTimeImmutable($payload['occurred_at']);
        } catch (\Exception) {
            throw new \InvalidArgumentException('occurred_at is not a valid date.');
        }

        return new self(
            eventId: (string) $payload['event_id'],
            junctionId: (string) $payload['junction_id'],
            direction: (string) $payload['direction'],
            vehicleType: (string) $payload['vehicle_type'],
            eventType: (string) $payload['event_type'],
            vehicleId: (string) $payload['vehicle_id'],
            sequenceNo: (int) $payload['sequence_no'],
            occurredAt: $occurredAt,
        );
    }
}
