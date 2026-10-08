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
        public readonly ?DateTimeImmutable $modeStartedAt,
        public readonly Step $step,
        public readonly ?DateTimeImmutable $stepStartedAt,
        public readonly array $desiredSignals,
        public readonly ?ManualOverride $manual = null,
        public readonly ?EmergencyState $emergency = null,
        public readonly ?PendingCommand $pendingCommand = null,
        public readonly bool $controllerOnline = true,
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
            modeStartedAt: $now,
            step: Step::ALL_RED,
            stepStartedAt: $now,
            desiredSignals: $config->desiredSignalsFor(Step::ALL_RED),
            controllerOnline: true,
        );
    }

    /**
     * @param  array<string, string>  $desiredSignals
     */
    public function advance(Step $step, DateTimeImmutable $stepStartedAt, array $desiredSignals): self
    {
        return new self(
            id: $this->id,
            mode: $this->mode,
            modeStartedAt: $this->modeStartedAt,
            step: $step,
            stepStartedAt: $stepStartedAt,
            desiredSignals: $desiredSignals,
            manual: $this->manual,
            emergency: $this->emergency,
            pendingCommand: $this->pendingCommand,
            controllerOnline: $this->controllerOnline,
        );
    }

    public function asManual(ManualOverride $manual, DateTimeImmutable $modeStartedAt): self
    {
        return new self(
            id: $this->id,
            mode: Mode::MANUAL,
            modeStartedAt: $modeStartedAt,
            step: $this->step,
            stepStartedAt: $this->stepStartedAt,
            desiredSignals: $this->desiredSignals,
            manual: $manual,
            emergency: $this->emergency,
            pendingCommand: $this->pendingCommand,
            controllerOnline: $this->controllerOnline,
        );
    }

    public function asEmergency(EmergencyState $emergency, DateTimeImmutable $modeStartedAt): self
    {
        return new self(
            id: $this->id,
            mode: Mode::EMERGENCY,
            modeStartedAt: $modeStartedAt,
            step: $this->step,
            stepStartedAt: $this->stepStartedAt,
            desiredSignals: $this->desiredSignals,
            manual: null,
            emergency: $emergency,
            pendingCommand: $this->pendingCommand,
            controllerOnline: $this->controllerOnline,
        );
    }

    public function withEmergency(EmergencyState $emergency): self
    {
        return new self(
            id: $this->id,
            mode: $this->mode,
            modeStartedAt: $this->modeStartedAt,
            step: $this->step,
            stepStartedAt: $this->stepStartedAt,
            desiredSignals: $this->desiredSignals,
            manual: $this->manual,
            emergency: $emergency,
            pendingCommand: $this->pendingCommand,
            controllerOnline: $this->controllerOnline,
        );
    }

    public function asAutomatic(DateTimeImmutable $modeStartedAt): self
    {
        return new self(
            id: $this->id,
            mode: Mode::AUTOMATIC,
            modeStartedAt: $modeStartedAt,
            step: $this->step,
            stepStartedAt: $this->stepStartedAt,
            desiredSignals: $this->desiredSignals,
            emergency: null,
            manual: null,
            pendingCommand: $this->pendingCommand,
            controllerOnline: $this->controllerOnline,
        );
    }

    public function asDegraded(DateTimeImmutable $modeStartedAt): self
    {
        return new self(
            id: $this->id,
            mode: Mode::DEGRADED,
            modeStartedAt: $modeStartedAt,
            step: $this->step,
            stepStartedAt: $this->stepStartedAt,
            desiredSignals: $this->desiredSignals,
            manual: null,
            emergency: $this->emergency,
            pendingCommand: $this->pendingCommand,
            controllerOnline: $this->controllerOnline,
        );
    }

    public function withPendingCommand(PendingCommand $pendingCommand): self
    {
        return new self(
            id: $this->id,
            mode: $this->mode,
            modeStartedAt: $this->modeStartedAt,
            step: $this->step,
            stepStartedAt: $this->stepStartedAt,
            desiredSignals: $this->desiredSignals,
            manual: $this->manual,
            emergency: $this->emergency,
            pendingCommand: $pendingCommand,
            controllerOnline: $this->controllerOnline,
        );
    }

    public function withPendingCommandId(string $commandId): self
    {
        if ($this->pendingCommand === null) {
            return $this;
        }

        return $this->withPendingCommand($this->pendingCommand->withCommandId($commandId));
    }

    public function clearPendingCommand(): self
    {
        return new self(
            id: $this->id,
            mode: $this->mode,
            modeStartedAt: $this->modeStartedAt,
            step: $this->step,
            stepStartedAt: $this->stepStartedAt,
            desiredSignals: $this->desiredSignals,
            manual: $this->manual,
            emergency: $this->emergency,
            pendingCommand: null,
            controllerOnline: $this->controllerOnline,
        );
    }

    public function withControllerOnline(bool $online): self
    {
        return new self(
            id: $this->id,
            mode: $this->mode,
            modeStartedAt: $this->modeStartedAt,
            step: $this->step,
            stepStartedAt: $this->stepStartedAt,
            desiredSignals: $this->desiredSignals,
            manual: $this->manual,
            emergency: $this->emergency,
            pendingCommand: $this->pendingCommand,
            controllerOnline: $online,
        );
    }

    public function awaitingAck(): bool
    {
        return $this->pendingCommand !== null;
    }

    public function signalFor(Direction $direction): string
    {
        return $this->desiredSignals[$direction->value] ?? SignalColor::RED->value;
    }
}
