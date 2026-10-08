<?php

namespace App\Domain\Traffic;

enum Direction: string
{
    case NORTH = 'NORTH';

    case SOUTH = 'SOUTH';

    case EAST = 'EAST';

    case WEST = 'WEST';
}
