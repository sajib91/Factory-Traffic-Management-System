<?php

namespace App\Application\Traffic;

enum SensorEventOutcome: string
{
    case RECEIVED = 'RECEIVED';

    case DUPLICATE = 'DUPLICATE';

    case INVALID = 'INVALID';

    case STALE = 'STALE';

    case OUT_OF_ORDER = 'OUT_OF_ORDER';

    case ARRIVAL_APPLIED = 'ARRIVAL_APPLIED';

    case CLEARANCE_APPLIED = 'CLEARANCE_APPLIED';

    case CLEARED_WITHOUT_ARRIVAL = 'CLEARED_WITHOUT_ARRIVAL';
}
