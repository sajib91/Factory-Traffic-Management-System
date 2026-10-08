<?php

namespace App\Application\Traffic;

use App\Domain\Traffic\Direction;
use App\Domain\Traffic\EmergencyRequest;
use App\Domain\Traffic\EmergencyState;
use App\Domain\Traffic\JunctionState;
use App\Domain\Traffic\ManualOverride;
use App\Domain\Traffic\Mode;
use App\Domain\Traffic\PendingCommand;
use App\Domain\Traffic\Step;
use App\Infrastructure\Models\Junction;
use DateTimeImmutable;
use DateTimeInterface;

final class JunctionStateMapper
{
    public function fromModel(Junction $junction): JunctionState
    {
        $step = $junction->phase !== null ? Step::from($junction->phase) : Step::ALL_RED;

        $pending = null;
        if ($junction->pending !== null) {
            $pending = new PendingCommand(
                commandId: $junction->pending['command_id'] ?? '',
                targetStep: Step::from($junction->pending['target_step']),
                desiredSignals: $junction->pending['desired_signals'],
                sentAt: $this->toImmutable($junction->pending['sent_at']) ?? new DateTimeImmutable,
                attempts: $junction->pending['attempts'] ?? 0,
            );
        }

        return new JunctionState(
            id: $junction->id,
            mode: Mode::from($junction->mode),
            modeStartedAt: $this->toImmutable($junction->mode_started_at),
            step: $step,
            stepStartedAt: $this->toImmutable($junction->step_started_at),
            desiredSignals: $junction->desired_signals,
            manual: $junction->manual !== null ? $this->manualFromArray($junction->manual) : null,
            emergency: $junction->emergency !== null ? $this->emergencyFromArray($junction->emergency) : null,
            pendingCommand: $pending,
            controllerOnline: $junction->controller_status === 'ONLINE',
        );
    }

    public function toAttributes(JunctionState $state): array
    {
        $pending = null;
        if ($state->pendingCommand !== null) {
            $pending = [
                'command_id' => $state->pendingCommand->commandId,
                'target_step' => $state->pendingCommand->targetStep->value,
                'desired_signals' => $state->pendingCommand->desiredSignals,
                'sent_at' => $state->pendingCommand->sentAt->format('Y-m-d H:i:s'),
                'attempts' => $state->pendingCommand->attempts,
            ];
        }

        return [
            'mode' => $state->mode->value,
            'phase' => $state->step->value,
            'step_started_at' => $state->stepStartedAt,
            'mode_started_at' => $state->modeStartedAt,
            'desired_signals' => $state->desiredSignals,
            'emergency' => $this->emergencyToArray($state->emergency),
            'manual' => $this->manualToArray($state->manual),
            'pending' => $pending,
            'controller_status' => $state->controllerOnline ? 'ONLINE' : 'OFFLINE',
        ];
    }

    private function manualFromArray(array $data): ManualOverride
    {
        return new ManualOverride(
            direction: Direction::from($data['direction']),
            modeStartedAt: new DateTimeImmutable($data['mode_started_at']),
            expiresAt: new DateTimeImmutable($data['expires_at']),
        );
    }

    private function manualToArray(?ManualOverride $manual): ?array
    {
        if ($manual === null) {
            return null;
        }

        return [
            'direction' => $manual->direction->value,
            'mode_started_at' => $manual->modeStartedAt->format('Y-m-d H:i:s'),
            'expires_at' => $manual->expiresAt->format('Y-m-d H:i:s'),
        ];
    }

    private function emergencyFromArray(array $data): EmergencyState
    {
        $waiting = [];
        foreach ($data['waiting'] ?? [] as $request) {
            $waiting[] = new EmergencyRequest(
                vehicleId: $request['vehicle_id'],
                direction: Direction::from($request['direction']),
                detectedAt: new DateTimeImmutable($request['detected_at']),
            );
        }

        $active = null;
        if (($data['active'] ?? null) !== null) {
            $active = new EmergencyRequest(
                vehicleId: $data['active']['vehicle_id'],
                direction: Direction::from($data['active']['direction']),
                detectedAt: new DateTimeImmutable($data['active']['detected_at']),
            );
        }

        return new EmergencyState(
            active: $active,
            activeSince: $data['active_since'] !== null
                ? new DateTimeImmutable($data['active_since'])
                : null,
            waiting: $waiting,
        );
    }

    private function emergencyToArray(?EmergencyState $state): ?array
    {
        if ($state === null) {
            return null;
        }

        return [
            'active' => $state->active !== null
                ? [
                    'vehicle_id' => $state->active->vehicleId,
                    'direction' => $state->active->direction->value,
                    'detected_at' => $state->active->detectedAt->format('Y-m-d H:i:s'),
                ]
                : null,
            'active_since' => $state->activeSince?->format('Y-m-d H:i:s'),
            'waiting' => array_map(
                fn (EmergencyRequest $request) => [
                    'vehicle_id' => $request->vehicleId,
                    'direction' => $request->direction->value,
                    'detected_at' => $request->detectedAt->format('Y-m-d H:i:s'),
                ],
                $state->waiting,
            ),
        ];
    }

    private function toImmutable(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromMutable($value);
        }

        return new DateTimeImmutable($value);
    }
}
