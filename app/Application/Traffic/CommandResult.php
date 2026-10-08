<?php

namespace App\Application\Traffic;

final class CommandResult
{
    /**
     * @param  list<string>  $commandIds
     */
    public function __construct(
        public readonly CommandOutcome $outcome,
        public readonly ?string $junctionId = null,
        public readonly ?string $reason = null,
        public readonly array $commandIds = [],
    ) {}
}
