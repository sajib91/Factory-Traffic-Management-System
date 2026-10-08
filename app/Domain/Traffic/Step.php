<?php

namespace App\Domain\Traffic;

enum Step: string
{
    case NS_GREEN = 'NS_GREEN';

    case NS_YELLOW = 'NS_YELLOW';

    case ALL_RED = 'ALL_RED';

    case EW_GREEN = 'EW_GREEN';

    case EW_YELLOW = 'EW_YELLOW';

    public function phase(): ?Phase
    {
        return match ($this) {
            self::NS_GREEN, self::NS_YELLOW => Phase::NORTH_SOUTH,
            self::EW_GREEN, self::EW_YELLOW => Phase::EAST_WEST,
            self::ALL_RED => null,
        };
    }

    public function isYellow(): bool
    {
        return $this === self::NS_YELLOW || $this === self::EW_YELLOW;
    }
}
