<?php

namespace App\Domain\Traffic;

enum Phase: string
{
    case NORTH_SOUTH = 'NORTH_SOUTH';

    case EAST_WEST = 'EAST_WEST';

    public function other(): self
    {
        return $this === self::NORTH_SOUTH ? self::EAST_WEST : self::NORTH_SOUTH;
    }
}
