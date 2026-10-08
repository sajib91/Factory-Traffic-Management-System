<?php

namespace App\Domain\Traffic;

use DateTimeImmutable;

final class PendingCommand
{
    /**
     * @param  array<string, string>  $desiredSignals  direction value => signal color value
     */
    public function __construct(
        public readonly string $commandId,
        public readonly Step $targetStep,
        public readonly array $desiredSignals,
        public readonly DateTimeImmutable $sentAt,
        public readonly int $attempts = 0,
    ) {}

    public function withCommandId(string $commandId): self
    {
        return new self(
            commandId: $commandId,
            targetStep: $this->targetStep,
            desiredSignals: $this->desiredSignals,
            sentAt: $this->sentAt,
            attempts: $this->attempts,
        );
    }

    public function incrementAttempts(): self
    {
        return new self(
            commandId: $this->commandId,
            targetStep: $this->targetStep,
            desiredSignals: $this->desiredSignals,
            sentAt: $this->sentAt,
            attempts: $this->attempts + 1,
        );
    }
}
