<?php

namespace App\Domain\Traffic;

use RuntimeException;

final class CommandDuringDegradedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Manual commands are not allowed during degraded operation.');
    }
}
