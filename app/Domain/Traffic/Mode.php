<?php

namespace App\Domain\Traffic;

enum Mode: string
{
    case AUTOMATIC = 'AUTOMATIC';

    case MANUAL = 'MANUAL';

    case EMERGENCY = 'EMERGENCY';

    case DEGRADED = 'DEGRADED';
}
