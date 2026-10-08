<?php

namespace App\Domain\Traffic;

final class PhaseConfig
{
    /**
     * @param  list<string>  $green
     */
    public function __construct(
        public readonly Phase $phase,
        public readonly array $green,
        public readonly int $duration,
    ) {}

    /**
     * @return list<Direction>
     */
    public function greenDirections(): array
    {
        return array_map(fn (string $direction) => Direction::from($direction), $this->green);
    }
}
