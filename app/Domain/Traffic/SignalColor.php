<?php

namespace App\Domain\Traffic;

enum SignalColor: string
{
    case RED = 'RED';

    case YELLOW = 'YELLOW';

    case GREEN = 'GREEN';
}
