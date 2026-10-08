<?php

namespace App\Domain\Traffic;

use DateTimeImmutable;

final class EmergencyState
{
    /**
     * @param  list<EmergencyRequest>  $waiting
     */
    public function __construct(
        public readonly ?EmergencyRequest $active = null,
        public readonly ?DateTimeImmutable $activeSince = null,
        public readonly array $waiting = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }
}
