<?php

namespace App\Domain\Traffic;

final class Result
{
    /**
     * @param  list<Effect>  $effects
     */
    public function __construct(
        public readonly JunctionState $state,
        public readonly array $effects = [],
        public readonly bool $acknowledged = false,
    ) {}

    public static function idle(JunctionState $state): self
    {
        return new self($state);
    }

    public static function acknowledged(JunctionState $state): self
    {
        return new self($state, [], true);
    }

    /**
     * @return list<Effect>
     */
    public function effects(): array
    {
        return $this->effects;
    }
}
