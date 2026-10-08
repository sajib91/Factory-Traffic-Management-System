<?php

namespace App\Application\Traffic;

use App\Domain\Traffic\SendControllerCommand;

interface EffectDispatcher
{
    public function dispatch(string $commandId, SendControllerCommand $effect): void;
}
