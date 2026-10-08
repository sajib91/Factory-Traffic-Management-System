<?php

namespace App\Domain\Traffic;

use DateTimeImmutable;

final class JunctionState
{
    /**
     * @param  array<string, string>  $desiredSignals
     */
    public function __construct(
        public readonly string $id,
        public readonly Mode $mode,
        public readonly Step $step,
        public readonly ?DateTimeImmutable $stepStartedAt,
        public readonly array $desiredSignals,
    ) {}

    public static function initial(
        string $id,
        JunctionConfig $config,
        DateTimeImmutable $now,
        Mode $mode = Mode::AUTOMATIC,
    ): self {
        return new self(
            id: $id,
            mode: $mode,
            step: Step::ALL_RED,
            stepStartedAt: $now,
            desiredSignals: $config->desiredSignalsFor(Step::ALL_RED),
        );
    }

    /**
     * @param  array<Direction, SignalColor>  $desiredSignals
     */
    public function advance(Step $step, DateTimeImmutable $stepStartedAt, array $desiredSignals): self
    {
        return new self(
            id: $this->id,
            mode: $this->mode,
            step: $step,
            stepStartedAt: $stepStartedAt,
            desiredSignals: $desiredSignals,
        );
    }

    public function signalFor(Direction $direction): string
    {
        return $this->desiredSignals[$direction->value] ?? SignalColor::RED->value;
    }
}
