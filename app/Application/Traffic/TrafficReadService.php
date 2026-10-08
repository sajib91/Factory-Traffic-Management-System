<?php

namespace App\Application\Traffic;

use App\Infrastructure\Models\AuditLog;
use App\Infrastructure\Models\Junction;
use App\Infrastructure\Models\QueuedVehicle;
use DateTimeInterface;

final class TrafficReadService
{
    public function all(): array
    {
        return Junction::query()->orderBy('id')->get()->map(fn (Junction $junction) => $this->summary($junction))->all();
    }

    public function find(string $id): ?Junction
    {
        return Junction::query()->find($id);
    }

    public function status(Junction $junction): array
    {
        $directions = array_keys($junction->desired_signals ?? []);
        $queues = array_fill_keys($directions, 0);
        foreach (QueuedVehicle::query()->where('junction_id', $junction->id)->get(['direction']) as $vehicle) {
            $queues[$vehicle->direction] = ($queues[$vehicle->direction] ?? 0) + 1;
        }

        $desired = $junction->desired_signals ?? [];
        $actual = $junction->actual_signals ?? [];
        $alerts = [];
        if ($junction->controller_status !== 'ONLINE') {
            $alerts[] = 'offline';
        }
        if ($junction->pending !== null && $junction->pending['sent_at'] ?? false) {
            $sentAt = strtotime((string) $junction->pending['sent_at']);
            if ($sentAt !== false && time() - $sentAt >= 10) {
                $alerts[] = 'timeout';
            }
        }
        if ($desired != $actual && $actual !== []) {
            $alerts[] = 'mismatch';
        }
        if (in_array('UNKNOWN', $actual, true)) {
            $alerts[] = 'unknown';
        }

        return [
            'id' => $junction->id,
            'name' => $junction->name,
            'desired_signals' => $desired,
            'actual_signals' => $actual,
            'queues' => $queues,
            'mode' => $junction->mode,
            'phase' => $junction->phase,
            'controller_status' => $junction->controller_status,
            'emergency' => $junction->emergency,
            'manual' => $junction->manual,
            'pending_command' => $junction->pending,
            'alerts' => array_values(array_unique($alerts)),
            'version' => $junction->version,
        ];
    }

    public function history(Junction $junction, int $limit, ?string $type): array
    {
        $query = AuditLog::query()->where('junction_id', $junction->id)->latest('created_at')->latest('id');
        if ($type !== null && $type !== '') {
            $query->where('event_type', $type);
        }

        return $query->limit($limit)->get()->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'type' => $log->event_type,
            'direction' => $log->direction,
            'payload' => $log->payload,
            'command_id' => $log->command_id,
            'created_at' => $log->created_at?->toIso8601String(),
        ])->all();
    }

    private function summary(Junction $junction): array
    {
        return [
            'id' => $junction->id,
            'name' => $junction->name,
            'mode' => $junction->mode,
            'phase' => $junction->phase,
            'controller_status' => $junction->controller_status,
        ];
    }
}
