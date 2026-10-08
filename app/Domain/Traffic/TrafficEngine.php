<?php

namespace App\Domain\Traffic;

use DateTimeImmutable;

final class TrafficEngine
{
    public function __construct(
        private readonly JunctionConfig $config,
        private readonly Scheduler $scheduler,
    ) {}

    public function tick(JunctionState $state, QueueSnapshot $snapshot, DateTimeImmutable $now): Result
    {
        $this->assertAutomatic($state);

        $elapsed = $this->elapsedSeconds($state, $now);

        $target = match ($state->step) {
            Step::NS_GREEN, Step::EW_GREEN => $this->greenDecision($state, $snapshot, $elapsed),
            Step::NS_YELLOW, Step::EW_YELLOW => $elapsed >= $this->config->yellowSeconds ? Step::ALL_RED : null,
            Step::ALL_RED => $elapsed >= $this->config->allRedSeconds
                ? $this->greenStepFor($this->scheduler->pickNextPhaseAfterAllRed($snapshot))
                : null,
        };

        if ($target === null) {
            return Result::idle($state);
        }

        return $this->advance($state, $target, $now);
    }

    private function greenDecision(JunctionState $state, QueueSnapshot $snapshot, int $elapsed): ?Step
    {
        $phase = $state->step->phase();
        if ($phase === null || ! $this->scheduler->shouldSwitchTo($phase, $snapshot, $elapsed)) {
            return null;
        }

        return match ($state->step) {
            Step::NS_GREEN => Step::NS_YELLOW,
            Step::EW_GREEN => Step::EW_YELLOW,
            default => null,
        };
    }

    private function advance(JunctionState $state, Step $target, DateTimeImmutable $now): Result
    {
        $desiredSignals = $this->config->desiredSignalsFor($target);
        SignallingGuard::assertNoConflictingGreens($desiredSignals, $this->config);

        return new Result(
            state: $state->advance($target, $now, $desiredSignals),
            effects: [new SendControllerCommand($state->id, $target, $desiredSignals)],
        );
    }

    private function greenStepFor(Phase $phase): Step
    {
        return match ($phase) {
            Phase::NORTH_SOUTH => Step::NS_GREEN,
            Phase::EAST_WEST => Step::EW_GREEN,
        };
    }

    private function elapsedSeconds(JunctionState $state, DateTimeImmutable $now): int
    {
        $startedAt = $state->stepStartedAt ?? $now;

        return $now->getTimestamp() - $startedAt->getTimestamp();
    }

    private function assertAutomatic(JunctionState $state): void
    {
        if ($state->mode !== Mode::AUTOMATIC) {
            throw new \LogicException('TrafficEngine only supports AUTOMATIC mode for now.');
        }
    }
}
