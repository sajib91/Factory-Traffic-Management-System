<?php

namespace App\Domain\Traffic;

final class SendControllerCommand implements Effect
{
    /**
     * @param  array<string, string>  $desiredSignals  direction value => signal color value
     */
    public function __construct(
        public readonly string $junctionId,
        public readonly Step $step,
        public readonly array $desiredSignals,
    ) {}
}
