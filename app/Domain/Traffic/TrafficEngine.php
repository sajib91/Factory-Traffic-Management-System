<?php

namespace App\Domain\Traffic;

use DateTimeImmutable;

final class TrafficEngine
{
    public const EMERGENCY_TIMEOUT_SECONDS = 90;

    public const ACK_TIMEOUT_SECONDS = 10;

    public const MAX_ACK_RETRIES = 2;

    public function __construct(
        private readonly JunctionConfig $config,
        private readonly Scheduler $scheduler,
    ) {}

    public function tick(JunctionState $state, QueueSnapshot $snapshot, DateTimeImmutable $now): Result
    {
        // Check for pending command timeout/retry at top of tick
        if ($state->pendingCommand !== null && $state->pendingCommand->commandId !== '' && $state->controllerOnline) {
            $elapsed = $now->getTimestamp() - $state->pendingCommand->sentAt->getTimestamp();
            $maxAttempts = self::MAX_ACK_RETRIES + 1; // initial send + retries
            if ($elapsed >= self::ACK_TIMEOUT_SECONDS && $state->pendingCommand->attempts < $maxAttempts) {
                // Retry with same command id
                $pending = $state->pendingCommand->incrementAttempts();
                $state = $state->withPendingCommand($pending);
                $desiredSignals = $pending->desiredSignals;
                SignallingGuard::assertNoConflictingGreens($desiredSignals, $this->config);

                return new Result(
                    state: $state,
                    effects: [new SendControllerCommand($state->id, $pending->targetStep, $desiredSignals, $pending->commandId)],
                );
            }
            if ($elapsed >= self::ACK_TIMEOUT_SECONDS && $state->pendingCommand->attempts >= $maxAttempts) {
                // Timeout exhausted - go to DEGRADED with desired ALL_RED
                $allRedSignals = $this->config->desiredSignalsFor(Step::ALL_RED);
                SignallingGuard::assertNoConflictingGreens($allRedSignals, $this->config);
                $degradedState = $state->asDegraded($now)->clearPendingCommand()->advance(Step::ALL_RED, $now, $allRedSignals);

                return new Result(
                    state: $degradedState,
                    effects: [new SendControllerCommand($degradedState->id, Step::ALL_RED, $allRedSignals)],
                );
            }
        }

        if ($state->mode === Mode::DEGRADED) {
            if ($state->pendingCommand === null && $state->controllerOnline) {
                throw new \LogicException('A degraded junction must have a recovery command.');
            }

            return $this->tickDegraded($state, $now);
        }

        return match ($state->mode) {
            Mode::AUTOMATIC => $this->tickAutomatic($state, $snapshot, $now),
            Mode::MANUAL => $this->tickManual($state, $now),
            Mode::EMERGENCY => $this->tickEmergency($state, $now),
        };
    }

    public function handleEmergencyDetected(
        JunctionState $state,
        Direction $direction,
        string $vehicleId,
        DateTimeImmutable $now,
    ): Result {
        if ($this->config->phaseFor($direction) === null) {
            throw new InvalidDirectionException;
        }

        $emergency = $state->emergency ?? EmergencyState::empty();

        foreach ([$emergency->active, ...$emergency->waiting] as $request) {
            if ($request !== null && $request->vehicleId === $vehicleId) {
                return Result::idle($state);
            }
        }

        $request = new EmergencyRequest(
            vehicleId: $vehicleId,
            direction: $direction,
            detectedAt: $now,
        );

        if ($emergency->active === null) {
            $state = $state->asEmergency(
                new EmergencyState(
                    active: $request,
                    activeSince: $now,
                    waiting: [],
                ),
                $now,
            );
        } else {
            $state = $state->withEmergency(new EmergencyState(
                active: $emergency->active,
                activeSince: $emergency->activeSince,
                waiting: [...$emergency->waiting, $request],
            ));
        }

        return $this->withImmediatePreemption($state, $request->direction, $now);
    }

    public function handleEmergencyCleared(
        JunctionState $state,
        DateTimeImmutable $now,
        ?string $vehicleId = null,
    ): Result {
        $emergency = $state->emergency;

        if ($state->mode !== Mode::EMERGENCY || $emergency === null || $emergency->active === null) {
            return Result::idle($state);
        }

        if ($vehicleId !== null && $emergency->active->vehicleId !== $vehicleId) {
            return Result::idle($state);
        }

        return $this->finishEmergency($state, $now);
    }

    public function handleCommand(
        JunctionState $state,
        CommandRequest $command,
        DateTimeImmutable $now,
    ): Result {
        if ($command->returnToAutomatic) {
            return $state->mode === Mode::MANUAL
                ? new Result($state->asAutomatic($now))
                : Result::idle($state);
        }

        $direction = $command->direction ?? throw new InvalidDirectionException;

        if ($this->config->phaseFor($direction) === null) {
            throw new InvalidDirectionException;
        }

        if ($state->mode === Mode::EMERGENCY) {
            throw new CommandDuringEmergencyException;
        }
        if ($state->mode === Mode::DEGRADED) {
            throw new CommandDuringDegradedException;
        }

        return $this->withImmediatePreemption(
            $state->asManual(ManualOverride::request($direction, $now), $now),
            $direction,
            $now,
        );
    }

    /**
     * The controller only confirms that it physically applied the signals we
     * last commanded; the engine already holds the target state, so an ACK is
     * a no-op here (it is what records the controller's actual_signals).
     *
     * @param  array<string, string>  $actualSignals
     */
    /**
     * @param  array<string, string>  $actualSignals
     */
    public function handleControllerAck(JunctionState $state, string|array $commandId, ?DateTimeImmutable $now = null, array $actualSignals = []): Result
    {
        if (is_array($commandId)) {
            return Result::idle($state);
        }

        $now ??= new DateTimeImmutable;

        if ($state->pendingCommand === null) {
            return Result::idle($state);
        }

        if ($state->pendingCommand->commandId !== '' && $state->pendingCommand->commandId !== $commandId) {
            return Result::idle($state);
        }

        $newState = $state->clearPendingCommand();
        $newState = $newState->advance($state->pendingCommand->targetStep, $now, $state->pendingCommand->desiredSignals);

        return Result::acknowledged($newState);
    }

    /**
     * @param  array<string, string>  $actualSignals
     */
    public function handleControllerAckLegacy(JunctionState $state, array $actualSignals): Result
    {
        return Result::idle($state);
    }

    public function handleControllerOffline(JunctionState $state, DateTimeImmutable $now): Result
    {
        if (! $state->controllerOnline) {
            return Result::idle($state);
        }

        if ($state->mode !== Mode::DEGRADED && $state->mode !== Mode::EMERGENCY) {
            $allRedSignals = $this->config->desiredSignalsFor(Step::ALL_RED);
            $degradedState = $state->asDegraded($now)->withControllerOnline(false)->clearPendingCommand()->advance(Step::ALL_RED, $now, $allRedSignals);

            return new Result(
                state: $degradedState,
                effects: [new SendControllerCommand($degradedState->id, Step::ALL_RED, $allRedSignals)],
            );
        }

        return new Result($state->withControllerOnline(false)->clearPendingCommand());
    }

    public function handleControllerOnline(JunctionState $state, DateTimeImmutable $now): Result
    {
        if ($state->controllerOnline) {
            return Result::idle($state);
        }

        $newState = $state->withControllerOnline(true);
        if ($state->mode === Mode::DEGRADED) {
            $allRedSignals = $this->config->desiredSignalsFor(Step::ALL_RED);
            $newState = $newState->clearPendingCommand()->advance(Step::ALL_RED, $now, $allRedSignals);

            return new Result(
                state: $newState,
                effects: [new SendControllerCommand($newState->id, Step::ALL_RED, $allRedSignals)],
            );
        }

        return Result::idle($newState);
    }

    private function tickAutomatic(JunctionState $state, QueueSnapshot $snapshot, DateTimeImmutable $now): Result
    {
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

    private function tickManual(JunctionState $state, DateTimeImmutable $now): Result
    {
        if ($state->mode === Mode::DEGRADED) {
            throw new CommandDuringDegradedException;
        }
        $manual = $state->manual;

        if ($manual === null || $now->getTimestamp() >= $manual->expiresAt->getTimestamp()) {
            return new Result($state->asAutomatic($now));
        }

        return $this->tickDirected($state, $this->config->phaseFor($manual->direction), $now);
    }

    private function tickEmergency(JunctionState $state, DateTimeImmutable $now): Result
    {
        $emergency = $state->emergency;

        if ($emergency === null || $emergency->active === null || $emergency->activeSince === null) {
            return new Result($state->asAutomatic($now));
        }

        $activePhase = $this->config->phaseFor($emergency->active->direction);

        if (
            $activePhase !== null
            && $now->getTimestamp() - $emergency->activeSince->getTimestamp() >= self::EMERGENCY_TIMEOUT_SECONDS
            && $state->step === $this->greenStepFor($activePhase)
        ) {
            return $this->finishEmergency($state, $now);
        }

        return $this->tickDirected($state, $activePhase, $now);
    }

    private function tickDirected(JunctionState $state, ?Phase $target, DateTimeImmutable $now): Result
    {
        if ($target === null) {
            return $this->advance($state, Step::ALL_RED, $now);
        }

        $step = $state->step;
        $phase = $step->phase();
        $elapsed = $this->elapsedSeconds($state, $now);

        if ($phase === $target) {
            if ($step->isYellow()) {
                return $this->advanceWhen($state, $elapsed, $this->config->yellowSeconds, Step::ALL_RED, $now);
            }

            return Result::idle($state);
        }

        return match ($step) {
            Step::ALL_RED => $this->advanceWhen($state, $elapsed, $this->config->allRedSeconds, $this->greenStepFor($target), $now),
            Step::NS_YELLOW, Step::EW_YELLOW => $this->advanceWhen($state, $elapsed, $this->config->yellowSeconds, Step::ALL_RED, $now),
            Step::NS_GREEN => $this->advance($state, Step::NS_YELLOW, $now),
            Step::EW_GREEN => $this->advance($state, Step::EW_YELLOW, $now),
        };
    }

    private function finishEmergency(JunctionState $state, DateTimeImmutable $now): Result
    {
        $emergency = $state->emergency ?? EmergencyState::empty();

        if ($emergency->waiting === []) {
            return new Result($state->asAutomatic($now));
        }

        $next = $emergency->waiting[0];
        $remaining = array_slice($emergency->waiting, 1);

        $state = $state->asEmergency(new EmergencyState(
            active: $next,
            activeSince: $now,
            waiting: $remaining,
        ), $now);

        return $this->withImmediatePreemption($state, $next->direction, $now);
    }

    private function withImmediatePreemption(
        JunctionState $state,
        Direction $direction,
        DateTimeImmutable $now,
    ): Result {
        $target = $this->config->phaseFor($direction);
        $currentPhase = $state->step->phase();

        if (
            $target === null
            || $currentPhase === null
            || $currentPhase === $target
            || $state->step->isYellow()
        ) {
            return Result::idle($state);
        }

        return $this->advance($state, $this->yellowStepFor($currentPhase), $now);
    }

    private function advanceWhen(
        JunctionState $state,
        int $elapsed,
        int $needed,
        Step $target,
        DateTimeImmutable $now,
    ): Result {
        return $elapsed >= $needed
            ? $this->advance($state, $target, $now)
            : Result::idle($state);
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

        // Only transitions into a GREEN step are ACK-gated
        $isGreen = $target === Step::NS_GREEN || $target === Step::EW_GREEN;
        if ($isGreen) {
            $pending = new PendingCommand('', $target, $desiredSignals, $now, 0);
            $stateWithPending = $state->withPendingCommand($pending)->advance($target, $now, $desiredSignals);

            return new Result(
                state: $stateWithPending,
                effects: [new SendControllerCommand($state->id, $target, $desiredSignals)],
            );
        }

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

    private function yellowStepFor(Phase $phase): Step
    {
        return match ($phase) {
            Phase::NORTH_SOUTH => Step::NS_YELLOW,
            Phase::EAST_WEST => Step::EW_YELLOW,
        };
    }

    private function tickDegraded(JunctionState $state, DateTimeImmutable $now): Result
    {
        if ($state->pendingCommand !== null && $state->controllerOnline) {
            $elapsed = $now->getTimestamp() - $state->pendingCommand->sentAt->getTimestamp();
            $maxAttempts = self::MAX_ACK_RETRIES + 1;
            if ($elapsed >= self::ACK_TIMEOUT_SECONDS && $state->pendingCommand->attempts < $maxAttempts) {
                $pending = $state->pendingCommand->incrementAttempts();
                $state = $state->withPendingCommand($pending);
                $desiredSignals = $pending->desiredSignals;
                SignallingGuard::assertNoConflictingGreens($desiredSignals, $this->config);

                return new Result(
                    state: $state,
                    effects: [new SendControllerCommand($state->id, $pending->targetStep, $desiredSignals, $pending->commandId)],
                );
            }
            if ($elapsed >= self::ACK_TIMEOUT_SECONDS && $state->pendingCommand->attempts >= $maxAttempts) {
                return Result::idle($state);
            }

            return Result::idle($state);
        }

        if ($state->pendingCommand === null && $state->controllerOnline && $state->mode === Mode::DEGRADED) {
            $allRedSignals = $this->config->desiredSignalsFor(Step::ALL_RED);
            $pending = new PendingCommand('', Step::ALL_RED, $allRedSignals, $now, 0);
            $stateWithPending = $state->withPendingCommand($pending)->advance(Step::ALL_RED, $now, $allRedSignals);

            return new Result(
                state: $stateWithPending,
                effects: [new SendControllerCommand($stateWithPending->id, Step::ALL_RED, $allRedSignals)],
            );
        }

        return Result::idle($state);
    }

    private function elapsedSeconds(JunctionState $state, DateTimeImmutable $now): int
    {
        $startedAt = $state->stepStartedAt ?? $now;

        return $now->getTimestamp() - $startedAt->getTimestamp();
    }
}
