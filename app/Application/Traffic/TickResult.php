<?php

namespace App\Application\Traffic;

use App\Domain\Traffic\Mode;
use App\Domain\Traffic\Step;

final class TickResult
{
    /**
     * @param  list<string>  $commandIds
     */
    public function __construct(
        public readonly string $junctionId,
        public readonly ?Mode $mode = null,
        public readonly ?Step $step = null,
        public readonly bool $transitioned = false,
        public readonly array $commandIds = [],
    ) {}
}
