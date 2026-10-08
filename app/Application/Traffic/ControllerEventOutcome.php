<?php

namespace App\Application\Traffic;

enum ControllerEventOutcome: string
{
    case ACKED = 'ACKED';

    case UNKNOWN_COMMAND = 'UNKNOWN_COMMAND';

    case NO_SUCH_JUNCTION = 'NO_SUCH_JUNCTION';

    case IGNORED = 'IGNORED';
}
