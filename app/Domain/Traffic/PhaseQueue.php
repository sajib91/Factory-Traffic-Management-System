<?php

namespace App\Domain\Traffic;

final class PhaseQueue
{
    public function __construct(
        public readonly int $count,
        public readonly int $weightSum,
        public readonly float $oldestWaitSeconds,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0.0);
    }

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }
}
