<?php

namespace App\Application\Traffic;

use App\Domain\Traffic\Clock;
use App\Domain\Traffic\CommandDuringDegradedException;
use App\Domain\Traffic\CommandDuringEmergencyException;
use App\Domain\Traffic\CommandRequest;
use App\Domain\Traffic\Direction;
use App\Domain\Traffic\InvalidDirectionException;
use App\Domain\Traffic\JunctionConfig;
use App\Domain\Traffic\JunctionState;
use App\Domain\Traffic\Mode;
use App\Domain\Traffic\Phase;
use App\Domain\Traffic\PhaseQueue;
use App\Domain\Traffic\QueueSnapshot;
use App\Domain\Traffic\Result;
use App\Domain\Traffic\Scheduler;
use App\Domain\Traffic\SendControllerCommand;
use App\Domain\Traffic\Step;
use App\Domain\Traffic\SystemClock;
use App\Domain\Traffic\TrafficEngine;
use App\Domain\Traffic\VehicleType;
use App\Infrastructure\Models\AuditLog;
use App\Infrastructure\Models\ControllerCommand;
use App\Infrastructure\Models\Junction;
use App\Infrastructure\Models\ProcessedEvent;
use App\Infrastructure\Models\QueuedVehicle;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TrafficService
{
    public const STALENESS_WINDOW_SECONDS = 120;

    /**
     * TrafficService is the ONLY gateway into the engine. Every operation runs
     * inside one transaction that takes the junction's row lock (lockForUpdate),
     * so per-junction state changes are serialized even under concurrent events.
     */
    private ?EffectDispatcher $dispatcher = null;

    public function __construct(
        private readonly Clock $clock = new SystemClock,
        private readonly JunctionStateMapper $mapper = new JunctionStateMapper,
        ?EffectDispatcher $dispatcher = null,
    ) {
        $this->dispatcher = $dispatcher ?? new OutboxEffectDispatcher($this->clock);
    }

    /**
     * Re-establishes a safe controller state after a process restart.
     *
     * @return array{junction_id: string, command_id: string}
     */
    public function recover(string $junctionId): array
    {
        $now = $this->clock->now();

        return DB::transaction(function () use ($junctionId, $now): array {
            $junction = $this->lockJunction($junctionId);
            if ($junction === null) {
                return ['junction_id' => $junctionId, 'command_id' => ''];
            }

            $config = JunctionConfig::fromArray($junction->config);
            $allRed = $config->desiredSignalsFor(Step::ALL_RED);
            $unknown = array_fill_keys(array_keys($allRed), 'UNKNOWN');
            $commandId = (string) Str::uuid();

            ControllerCommand::query()
                ->where('junction_id', $junction->id)
                ->whereIn('status', ['PENDING', 'DISPATCHED'])
                ->update(['status' => 'SUPERSEDED']);

            ControllerCommand::query()->create([
                'command_id' => $commandId,
                'junction_id' => $junction->id,
                'desired_signals' => $allRed,
                'status' => 'PENDING',
                'attempts' => 0,
            ]);

            $junction->fill([
                'mode' => Mode::DEGRADED->value,
                'phase' => Step::ALL_RED->value,
                'step_started_at' => $now,
                'mode_started_at' => $now,
                'desired_signals' => $allRed,
                'actual_signals' => $unknown,
                'pending' => [
                    'command_id' => $commandId,
                    'target_step' => Step::ALL_RED->value,
                    'desired_signals' => $allRed,
                    'sent_at' => $now->format('Y-m-d H:i:s'),
                    'attempts' => 0,
                ],
                'emergency' => null,
                'manual' => null,
                'version' => ((int) $junction->version) + 1,
            ]);
            $junction->save();

            $this->audit($junction->id, 'RECOVERY_STARTED', null, [
                'command_id' => $commandId,
                'desired_signals' => $allRed,
            ], $now, [$commandId]);

            $this->dispatchAfterCommit([
                $commandId => new SendControllerCommand($junction->id, Step::ALL_RED, $allRed, $commandId),
            ]);

            return ['junction_id' => $junction->id, 'command_id' => $commandId];
        });
    }

    public function sensorEvent(SensorEvent $event): SensorEventResult
    {
        $now = $this->clock->now();

        return DB::transaction(function () use ($event, $now) {
            $junction = $this->lockJunction($event->junctionId);

            if ($junction === null) {
                $this->audit($event->junctionId, $event->eventType, $event->direction, [
                    'event_id' => $event->eventId,
                    'vehicle_id' => $event->vehicleId,
                    'vehicle_type' => $event->vehicleType,
                    'sequence_no' => $event->sequenceNo,
                    'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s'),
                    'outcome' => SensorEventOutcome::INVALID->value,
                    'reason' => 'unknown junction',
                ], $now);

                return new SensorEventResult($event->eventId, SensorEventOutcome::INVALID, reason: 'unknown junction');
            }

            if (! $this->recordReceived($event, $now)) {
                $this->sensorAudit($junction, $event, SensorEventOutcome::DUPLICATE, 'duplicate event_id', $now);

                return new SensorEventResult(
                    $event->eventId,
                    SensorEventOutcome::DUPLICATE,
                    $junction->id,
                    'duplicate event_id',
                    $this->queueCount($junction->id),
                );
            }

            $error = $this->validationError($event, $junction);
            if ($error !== null) {
                return $this->reject($junction, $event, SensorEventOutcome::INVALID, $error, $now);
            }

            if ($this->isStale($event, $now)) {
                return $this->reject($junction, $event, SensorEventOutcome::STALE, 'stale event', $now);
            }

            if ($this->isOutOfOrder($event, $junction)) {
                return $this->reject($junction, $event, SensorEventOutcome::OUT_OF_ORDER, 'out of order sequence', $now);
            }

            [$outcome, $effects] = $this->apply($junction, $event, $now);

            if ($outcome === SensorEventOutcome::CLEARED_WITHOUT_ARRIVAL) {
                return $this->reject($junction, $event, SensorEventOutcome::CLEARED_WITHOUT_ARRIVAL, 'no arrival exists', $now);
            }

            $this->markOutcome($event, $outcome);
            $this->sensorAudit($junction, $event, $outcome, null, $now, array_keys($effects));

            $this->dispatchAfterCommit($effects);

            return new SensorEventResult(
                $event->eventId,
                $outcome,
                $junction->id,
                queueCountAfter: $this->queueCount($junction->id),
                commandIds: array_keys($effects),
            );
        });
    }

    public function command(string $junctionId, CommandRequest $command): CommandResult
    {
        $now = $this->clock->now();

        return DB::transaction(function () use ($junctionId, $command, $now) {
            $junction = $this->lockJunction($junctionId);

            if ($junction === null) {
                $this->audit($junctionId, 'COMMAND', $command->direction?->value, [
                    'command' => $command->returnToAutomatic ? 'RETURN_TO_AUTOMATIC' : 'MANUAL_GREEN_REQUEST',
                    'outcome' => CommandOutcome::NO_SUCH_JUNCTION->value,
                    'reason' => 'unknown junction',
                ], $now);

                return new CommandResult(CommandOutcome::NO_SUCH_JUNCTION, reason: 'unknown junction');
            }

            $state = $this->mapper->fromModel($junction);
            $engine = $this->engineFor(JunctionConfig::fromArray($junction->config));

            try {
                $result = $engine->handleCommand($state, $command, $now);
            } catch (InvalidDirectionException) {
                $outcome = CommandOutcome::REJECTED_INVALID_DIRECTION;
                $this->commandAudit($junction, $command, $outcome, 'invalid direction', $now, []);

                return new CommandResult($outcome, $junction->id, 'invalid direction');
            } catch (CommandDuringEmergencyException) {
                $outcome = CommandOutcome::REJECTED_DURING_EMERGENCY;
                $this->commandAudit($junction, $command, $outcome, 'manual command blocked during emergency', $now, []);

                return new CommandResult($outcome, $junction->id, 'manual command blocked during emergency');
            } catch (CommandDuringDegradedException) {
                $outcome = CommandOutcome::REJECTED_DURING_DEGRADED;
                $this->commandAudit($junction, $command, $outcome, 'manual command blocked during degraded', $now, []);

                return new CommandResult($outcome, $junction->id, 'manual command blocked during degraded');
            }

            $effects = $this->applyResult($junction, $state, $result);

            $this->commandAudit($junction, $command, CommandOutcome::APPLIED, null, $now, array_keys($effects));

            $this->dispatchAfterCommit($effects);

            return new CommandResult(CommandOutcome::APPLIED, $junction->id, commandIds: array_keys($effects));
        });
    }

    public function controllerEvent(ControllerEvent $event): ControllerEventResult
    {
        $now = $this->clock->now();

        return DB::transaction(function () use ($event, $now) {
            $junction = $this->lockJunction($event->junctionId);

            if ($junction === null) {
                $this->audit($event->junctionId, 'CONTROLLER_ACK', null, [
                    'command_id' => $event->commandId,
                    'outcome' => ControllerEventOutcome::NO_SUCH_JUNCTION->value,
                    'reason' => 'unknown junction',
                ], $now);

                return new ControllerEventResult(ControllerEventOutcome::NO_SUCH_JUNCTION, reason: 'unknown junction');
            }

            $command = ControllerCommand::query()->where('command_id', $event->commandId)->where('junction_id', $junction->id)->first();

            if ($command === null) {
                $this->audit($junction->id, 'CONTROLLER_ACK', null, [
                    'command_id' => $event->commandId,
                    'outcome' => ControllerEventOutcome::UNKNOWN_COMMAND->value,
                    'reason' => 'unknown command',
                ], $now);

                return new ControllerEventResult(ControllerEventOutcome::UNKNOWN_COMMAND, $junction->id, $event->commandId, 'unknown command');
            }

            if ($command->status === 'ACKED') {
                $this->audit($junction->id, 'CONTROLLER_ACK', null, [
                    'command_id' => $event->commandId,
                    'outcome' => ControllerEventOutcome::IGNORED->value,
                    'reason' => 'duplicate ack',
                ], $now);

                return new ControllerEventResult(ControllerEventOutcome::IGNORED, $junction->id, $event->commandId, 'duplicate ack');
            }

            $actualSignals = $event->actualSignals ?? $command->desired_signals;

            $state = $this->mapper->fromModel($junction);
            $engine = $this->engineFor(JunctionConfig::fromArray($junction->config));
            $result = $engine->handleControllerAck($state, $event->commandId, $now, $actualSignals);

            $isRecoveryAck = AuditLog::query()
                ->where('junction_id', $junction->id)
                ->where('event_type', 'RECOVERY_STARTED')
                ->where('command_id', $event->commandId)
                ->exists();

            if ($isRecoveryAck && $result->acknowledged) {
                $result = new Result($result->state->asAutomatic($now), [], true);
            }

            $junction->actual_signals = $actualSignals;
            if ($result->acknowledged) {
                $junction->fill($this->mapper->toAttributes($result->state));
            }
            $junction->version = ((int) $junction->version) + 1;
            $junction->save();
            $command->update(['status' => 'ACKED', 'acked_at' => $now]);

            $this->audit($junction->id, 'CONTROLLER_ACK', null, [
                'outcome' => ControllerEventOutcome::ACKED->value,
                'actual_signals' => $actualSignals,
            ], $now);

            return new ControllerEventResult(ControllerEventOutcome::ACKED, $junction->id, $event->commandId);
        });
    }

    public function controllerStatus(string $junctionId, string $status, ?string $reason = null): ControllerEventResult
    {
        $now = $this->clock->now();

        return DB::transaction(function () use ($junctionId, $status, $now) {
            $junction = $this->lockJunction($junctionId);

            if ($junction === null) {
                $this->audit($junctionId, 'CONTROLLER_STATUS', null, [
                    'status' => $status,
                    'outcome' => ControllerEventOutcome::NO_SUCH_JUNCTION->value,
                    'reason' => 'unknown junction',
                ], $now);

                return new ControllerEventResult(ControllerEventOutcome::NO_SUCH_JUNCTION, reason: 'unknown junction');
            }

            $state = $this->mapper->fromModel($junction);
            $engine = $this->engineFor(JunctionConfig::fromArray($junction->config));
            if ($status === 'OFFLINE') {
                $result = $engine->handleControllerOffline($state, $now);
                if ($result->state !== $state) {
                    $junction->fill($this->mapper->toAttributes($result->state));
                    $junction->version = ((int) $junction->version) + 1;
                    $junction->save();
                }
                $this->audit($junction->id, 'CONTROLLER_STATUS', null, [
                    'status' => 'OFFLINE',
                    'outcome' => 'UPDATED',
                ], $now);
            } elseif ($status === 'ONLINE') {
                $result = $engine->handleControllerOnline($state, $now);
                if ($result->state !== $state) {
                    $junction->fill($this->mapper->toAttributes($result->state));
                    $junction->version = ((int) $junction->version) + 1;
                    $junction->save();
                }
                $this->audit($junction->id, 'CONTROLLER_STATUS', null, [
                    'status' => 'ONLINE',
                    'outcome' => 'UPDATED',
                ], $now);
                $this->dispatchAfterCommit($this->applyResult($junction, $state, $result));
            }

            return new ControllerEventResult(ControllerEventOutcome::ACKED, $junction->id);
        });
    }

    public function tick(string $junctionId): TickResult
    {
        $now = $this->clock->now();

        return DB::transaction(function () use ($junctionId, $now) {
            $junction = $this->lockJunction($junctionId);

            if ($junction === null) {
                $this->audit($junctionId, 'TICK', null, [
                    'outcome' => 'NO_SUCH_JUNCTION',
                    'reason' => 'unknown junction',
                ], $now);

                return new TickResult($junctionId);
            }

            $state = $this->mapper->fromModel($junction);
            $engine = $this->engineFor(JunctionConfig::fromArray($junction->config));
            $snapshot = $this->buildSnapshot($junction, $state, $now);
            $before = $state->step;

            $result = $engine->tick($state, $snapshot, $now);
            $effects = $this->applyResult($junction, $state, $result);

            $this->audit($junction->id, 'TICK', null, [
                'outcome' => 'TICKED',
                'mode' => $junction->mode,
                'from_step' => $before->value,
                'to_step' => $junction->phase,
                'transitioned' => $junction->phase !== $before->value,
            ], $now, array_keys($effects));

            $this->dispatchAfterCommit($effects);

            return new TickResult(
                $junction->id,
                Mode::from($junction->mode),
                Step::from($junction->phase ?? Step::ALL_RED->value),
                $junction->phase !== $before->value,
                array_keys($effects),
            );
        });
    }

    /**
     * Locks the junction row for update. This per-junction row lock is our
     * consistency strategy: all state changes for one junction are serialized,
     * so concurrent sensor events / commands / ticks can never interleave.
     */
    private function lockJunction(string $junctionId): ?Junction
    {
        return Junction::query()->whereKey($junctionId)->lockForUpdate()->first();
    }

    /**
     * Persists the engine result (bumping the row version) and returns the
     * effects keyed by the id of the controller_commands row we wrote for it.
     *
     * @return array<string, SendControllerCommand>
     */
    private function applyResult(Junction $junction, JunctionState $state, Result $result): array
    {
        $effects = [];

        if ($result->state !== $state) {
            $junction->fill($this->mapper->toAttributes($result->state));
            $junction->version = ((int) $junction->version) + 1;
            $junction->save();
        }

        foreach ($result->effects() as $effect) {
            if ($effect instanceof SendControllerCommand) {
                $commandId = $this->persistControllerCommand($junction->id, $effect);
                $effects[$commandId] = $effect;

                if ($result->state->pendingCommand !== null && $result->state->pendingCommand->commandId === '') {
                    $junction->pending = [
                        'command_id' => $commandId,
                        'target_step' => $result->state->pendingCommand->targetStep->value,
                        'desired_signals' => $result->state->pendingCommand->desiredSignals,
                        'sent_at' => $result->state->pendingCommand->sentAt->format('Y-m-d H:i:s'),
                        'attempts' => $result->state->pendingCommand->attempts,
                    ];
                    $junction->save();
                }
            }
        }

        return $effects;
    }

    /**
     * @param  array<string, SendControllerCommand>  $effects
     */
    private function dispatchAfterCommit(array $effects): void
    {
        if ($effects === []) {
            return;
        }

        DB::afterCommit(function () use ($effects): void {
            foreach ($effects as $commandId => $effect) {
                $this->dispatcher->dispatch($commandId, $effect);
            }
        });
    }

    /**
     * @return array{0: SensorEventOutcome, 1: array<string, SendControllerCommand>}
     */
    private function apply(Junction $junction, SensorEvent $event, DateTimeImmutable $now): array
    {
        $effects = [];
        $isEmergency = $event->vehicleType === VehicleType::EMERGENCY->value;

        if ($event->eventType === SensorEventType::VEHICLE_ARRIVED->value) {
            QueuedVehicle::query()->firstOrCreate(
                [
                    'junction_id' => $junction->id,
                    'vehicle_id' => $event->vehicleId,
                ],
                [
                    'direction' => $event->direction,
                    'vehicle_type' => $event->vehicleType,
                    'arrived_at' => $event->occurredAt,
                ],
            );

            if ($isEmergency) {
                $effects = $this->applyEmergencyTransition($junction, $event, $now, isClear: false);
            }

            $outcome = SensorEventOutcome::ARRIVAL_APPLIED;
        } else {
            $queued = QueuedVehicle::query()
                ->where('junction_id', $junction->id)
                ->where('vehicle_id', $event->vehicleId)
                ->first();

            if ($queued === null) {
                return [SensorEventOutcome::CLEARED_WITHOUT_ARRIVAL, []];
            }

            $queued->delete();

            if ($isEmergency) {
                $effects = $this->applyEmergencyTransition($junction, $event, $now, isClear: true);
            }

            $outcome = SensorEventOutcome::CLEARANCE_APPLIED;
        }

        $this->advanceCursor($junction, $event);

        return [$outcome, $effects];
    }

    /**
     * @return array<string, SendControllerCommand>
     */
    private function applyEmergencyTransition(
        Junction $junction,
        SensorEvent $event,
        DateTimeImmutable $now,
        bool $isClear,
    ): array {
        $state = $this->mapper->fromModel($junction);
        $engine = $this->engineFor(JunctionConfig::fromArray($junction->config));

        $result = $isClear
            ? $engine->handleEmergencyCleared($state, $now, $event->vehicleId)
            : $engine->handleEmergencyDetected($state, Direction::from($event->direction), $event->vehicleId, $now);

        return $this->applyResult($junction, $state, $result);
    }

    private function advanceCursor(Junction $junction, SensorEvent $event): void
    {
        $sequences = $junction->last_sequences ?? [];
        $sequences[$event->direction] = $event->sequenceNo;
        $junction->last_sequences = $sequences;
        $junction->version = ((int) $junction->version) + 1;
        $junction->save();
    }

    private function reject(
        Junction $junction,
        SensorEvent $event,
        SensorEventOutcome $outcome,
        string $reason,
        DateTimeImmutable $now,
    ): SensorEventResult {
        $this->markOutcome($event, $outcome);
        $this->sensorAudit($junction, $event, $outcome, $reason, $now);

        return new SensorEventResult(
            $event->eventId,
            $outcome,
            $junction->id,
            $reason,
            $this->queueCount($junction->id),
        );
    }

    private function recordReceived(SensorEvent $event, DateTimeImmutable $now): bool
    {
        return ProcessedEvent::query()->insertOrIgnore([
            'event_id' => $event->eventId,
            'junction_id' => $event->junctionId,
            'outcome' => SensorEventOutcome::RECEIVED->value,
            'received_at' => $now,
        ]);
    }

    private function markOutcome(SensorEvent $event, SensorEventOutcome $outcome): void
    {
        ProcessedEvent::query()
            ->where('event_id', $event->eventId)
            ->update(['outcome' => $outcome->value]);
    }

    private function validationError(SensorEvent $event, Junction $junction): ?string
    {
        if (! $this->isEnumValue(Direction::class, $event->direction)) {
            return 'unknown direction';
        }

        $directions = array_map(
            fn (Direction $direction) => $direction->value,
            JunctionConfig::fromArray($junction->config)->directions(),
        );

        if (! in_array($event->direction, $directions, true)) {
            return 'direction not served by junction';
        }

        if (! $this->isEnumValue(VehicleType::class, $event->vehicleType)) {
            return 'unknown vehicle_type';
        }

        if (! $this->isEnumValue(SensorEventType::class, $event->eventType)) {
            return 'unknown event_type';
        }

        return null;
    }

    private function isStale(SensorEvent $event, DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() - $event->occurredAt->getTimestamp() > self::STALENESS_WINDOW_SECONDS;
    }

    private function isOutOfOrder(SensorEvent $event, Junction $junction): bool
    {
        $last = (int) ($junction->last_sequences[$event->direction] ?? 0);

        return $event->sequenceNo <= $last;
    }

    private function buildSnapshot(Junction $junction, JunctionState $state, DateTimeImmutable $now): QueueSnapshot
    {
        $config = JunctionConfig::fromArray($junction->config);
        $servingPhase = in_array($state->step, [Step::NS_GREEN, Step::EW_GREEN], true) ? $state->step->phase() : null;

        $northSouth = [];
        $eastWest = [];

        foreach (QueuedVehicle::query()->where('junction_id', $junction->id)->orderBy('arrived_at')->get() as $vehicle) {
            try {
                $direction = Direction::from($vehicle->direction);
            } catch (\ValueError) {
                continue;
            }

            $phase = $config->phaseFor($direction);

            if ($phase === Phase::NORTH_SOUTH) {
                $northSouth[] = $vehicle;
            } elseif ($phase === Phase::EAST_WEST) {
                $eastWest[] = $vehicle;
            }
        }

        return QueueSnapshot::of(
            $this->queueFromVehicles($northSouth, $now, $servingPhase === Phase::NORTH_SOUTH),
            $this->queueFromVehicles($eastWest, $now, $servingPhase === Phase::EAST_WEST),
        );
    }

    /**
     * @param  list<QueuedVehicle>  $vehicles
     */
    private function queueFromVehicles(array $vehicles, DateTimeImmutable $now, bool $served): PhaseQueue
    {
        $count = count($vehicles);
        $weightSum = 0;
        $oldestArrived = null;

        foreach ($vehicles as $vehicle) {
            $weightSum += $this->weightFor($vehicle->vehicle_type);

            $arrivedAt = $vehicle->arrived_at instanceof DateTimeImmutable
                ? $vehicle->arrived_at
                : new DateTimeImmutable($vehicle->arrived_at->format('Y-m-d H:i:s'));

            if ($oldestArrived === null || $arrivedAt < $oldestArrived) {
                $oldestArrived = $arrivedAt;
            }
        }

        $oldestWait = 0.0;

        if ($oldestArrived !== null && ! $served) {
            $oldestWait = (float) ($now->getTimestamp() - $oldestArrived->getTimestamp());
        }

        return new PhaseQueue($count, $weightSum, $oldestWait);
    }

    private function weightFor(string $vehicleType): int
    {
        try {
            return VehicleType::from($vehicleType)->weight();
        } catch (\ValueError) {
            return 1;
        }
    }

    /**
     * @param  list<string>  $commandIds
     */
    private function sensorAudit(
        Junction $junction,
        SensorEvent $event,
        SensorEventOutcome $outcome,
        ?string $reason,
        DateTimeImmutable $now,
        array $commandIds = [],
    ): void {
        $this->audit($junction->id, $event->eventType, $event->direction, [
            'event_id' => $event->eventId,
            'vehicle_id' => $event->vehicleId,
            'vehicle_type' => $event->vehicleType,
            'sequence_no' => $event->sequenceNo,
            'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s'),
            'outcome' => $outcome->value,
            'reason' => $reason,
            'desired_signals' => $junction->desired_signals,
            'command_ids' => $commandIds,
        ], $now, $commandIds);
    }

    /**
     * @param  list<string>  $commandIds
     */
    private function commandAudit(
        Junction $junction,
        CommandRequest $command,
        CommandOutcome $outcome,
        ?string $reason,
        DateTimeImmutable $now,
        array $commandIds,
    ): void {
        $this->audit($junction->id, 'COMMAND', $command->direction?->value, [
            'command' => $command->returnToAutomatic ? 'RETURN_TO_AUTOMATIC' : 'MANUAL_GREEN_REQUEST',
            'outcome' => $outcome->value,
            'reason' => $reason,
            'desired_signals' => $junction->desired_signals,
            'command_ids' => $commandIds,
        ], $now, $commandIds);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $commandIds
     */
    private function audit(
        string $junctionId,
        string $eventType,
        ?string $direction,
        array $payload,
        DateTimeImmutable $now,
        array $commandIds = [],
    ): void {
        AuditLog::query()->create([
            'junction_id' => $junctionId,
            'event_type' => $eventType,
            'direction' => $direction,
            'payload' => $payload,
            'command_id' => $payload['command_ids'][0] ?? $commandIds[0] ?? null,
            'created_at' => $now,
        ]);
    }

    private function persistControllerCommand(string $junctionId, SendControllerCommand $command): string
    {
        $commandId = $command->commandId ?? (string) Str::uuid();

        ControllerCommand::query()->updateOrCreate(
            ['command_id' => $commandId],
            [
                'junction_id' => $junctionId,
                'desired_signals' => $command->desiredSignals,
                'status' => 'PENDING',
                'attempts' => 0,
            ],
        );

        return $commandId;
    }

    private function engineFor(JunctionConfig $config): TrafficEngine
    {
        return new TrafficEngine($config, new Scheduler($config));
    }

    private function queueCount(string $junctionId): int
    {
        return QueuedVehicle::query()->where('junction_id', $junctionId)->count();
    }

    private function isEnumValue(string $enum, string $value): bool
    {
        try {
            $enum::from($value);

            return true;
        } catch (\ValueError) {
            return false;
        }
    }
}
