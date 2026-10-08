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
    ) {}

    public static function idle(JunctionState $state): self
    {
        return new self($state);
    }

    /**
     * @return list<Effect>
     */
    public function effects(): array
    {
        return $this->effects;
    }
}
