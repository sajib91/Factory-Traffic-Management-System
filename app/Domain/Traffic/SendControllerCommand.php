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
        public readonly ?string $commandId = null,
    ) {}

    public function withCommandId(string $commandId): self
    {
        return new self(
            junctionId: $this->junctionId,
            step: $this->step,
            desiredSignals: $this->desiredSignals,
            commandId: $commandId,
        );
    }
}
