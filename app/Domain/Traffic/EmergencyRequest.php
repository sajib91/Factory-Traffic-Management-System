<?php

namespace App\Domain\Traffic;

use DateTimeImmutable;

final class EmergencyRequest
{
    public function __construct(
        public readonly string $vehicleId,
        public readonly Direction $direction,
        public readonly DateTimeImmutable $detectedAt,
    ) {}
}
