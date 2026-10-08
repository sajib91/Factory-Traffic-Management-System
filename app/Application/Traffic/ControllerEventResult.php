<?php

namespace App\Application\Traffic;

final class ControllerEventResult
{
    public function __construct(
        public readonly ControllerEventOutcome $outcome,
        public readonly ?string $junctionId = null,
        public readonly ?string $commandId = null,
        public readonly ?string $reason = null,
    ) {}
}
