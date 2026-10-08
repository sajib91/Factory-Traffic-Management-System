<?php

namespace App\Http\Controllers;

use App\Application\Traffic\ControllerEvent;
use App\Application\Traffic\ControllerEventOutcome;
use App\Application\Traffic\SensorEvent;
use App\Application\Traffic\TrafficReadService;
use App\Application\Traffic\TrafficService;
use App\Domain\Traffic\CommandRequest as DomainCommandRequest;
use App\Domain\Traffic\Direction;
use App\Domain\Traffic\JunctionConfig;
use App\Http\Requests\ControllerEventRequest;
use App\Http\Requests\CreateJunctionRequest;
use App\Http\Requests\HistoryRequest;
use App\Http\Requests\ManualCommandRequest;
use App\Http\Requests\SensorEventRequest;
use App\Infrastructure\Models\Junction;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class TrafficApiController extends Controller
{
    public function __construct(
        private readonly TrafficService $traffic,
        private readonly TrafficReadService $read,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->read->all()]);
    }

    public function store(CreateJunctionRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            $config = JunctionConfig::fromArray($data['config']);
        } catch (\ValueError|\TypeError $exception) {
            return $this->error('VALIDATION_ERROR', 'The junction configuration is invalid.', 422);
        }
        $signals = $config->desiredSignalsFor(\App\Domain\Traffic\Step::ALL_RED);
        $junction = Junction::query()->create([
            'id' => $data['id'],
            'name' => $data['name'],
            'config' => $data['config'],
            'mode' => 'AUTOMATIC',
            'phase' => 'ALL_RED',
            'step_started_at' => now(),
            'mode_started_at' => now(),
            'desired_signals' => $signals,
            'actual_signals' => $signals,
            'controller_status' => 'ONLINE',
            'last_sequences' => array_fill_keys($data['config']['directions'], 0),
            'pending' => null,
        ]);

        return response()->json(['data' => $this->read->status($junction)], 201);
    }

    public function show(string $id): JsonResponse
    {
        $junction = $this->read->find($id);
        return $junction === null
            ? $this->error('NOT_FOUND', 'Unknown junction.', 404)
            : response()->json(['data' => $this->read->status($junction)]);
    }

    public function status(string $id): JsonResponse
    {
        return $this->show($id);
    }

    public function sensorEvent(SensorEventRequest $request): JsonResponse
    {
        try {
            $result = $this->traffic->sensorEvent(SensorEvent::fromArray($request->validated()));
        } catch (\InvalidArgumentException $exception) {
            return $this->error('VALIDATION_ERROR', $exception->getMessage(), 422);
        }

        if ($result->reason === 'unknown junction') {
            return $this->error('NOT_FOUND', $result->reason, 404);
        }
        if (in_array($result->outcome->value, ['STALE', 'OUT_OF_ORDER', 'INVALID', 'CLEARED_WITHOUT_ARRIVAL'], true)) {
            return $this->error('INVALID_EVENT', $result->reason ?? $result->outcome->value, 422);
        }

        return response()->json(['data' => [
            'event_id' => $result->eventId,
            'outcome' => $result->outcome->value,
            'junction_id' => $result->junctionId,
            'queue_count' => $result->queueCountAfter,
            'command_ids' => $result->commandIds,
        ]], $result->outcome->value === 'DUPLICATE' ? 200 : 201);
    }

    public function command(ManualCommandRequest $request, string $id): JsonResponse
    {
        $junction = $this->read->find($id);
        if ($junction === null) {
            return $this->error('NOT_FOUND', 'Unknown junction.', 404);
        }

        $command = match ($request->validated('command')) {
            'GREEN_NS' => DomainCommandRequest::manualGreen(Direction::NORTH),
            'GREEN_EW' => DomainCommandRequest::manualGreen(Direction::EAST),
            default => DomainCommandRequest::returnToAutomatic(),
        };
        $result = $this->traffic->command($id, $command);
        if (in_array($result->outcome->value, ['REJECTED_DURING_DEGRADED', 'REJECTED_DURING_EMERGENCY'], true)) {
            return $this->error('COMMAND_NOT_ALLOWED', $result->reason ?? $result->outcome->value, 409);
        }
        if ($result->outcome->value !== 'APPLIED') {
            return $this->error('INVALID_COMMAND', $result->reason ?? $result->outcome->value, 422);
        }

        return response()->json(['data' => [
            'junction_id' => $id,
            'outcome' => $result->outcome->value,
            'command_ids' => $result->commandIds,
        ]], 202);
    }

    public function controllerEvent(ControllerEventRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $data['event_type'] === 'ACK'
            ? $this->traffic->controllerEvent(new ControllerEvent(
                $data['junction_id'],
                $data['command_id'],
                $data['actual_signals'] ?? null,
                $data['event_type'],
            ))
            : $this->traffic->controllerStatus($data['junction_id'], $data['event_type'], $data['reason'] ?? null);

        if ($result->outcome === ControllerEventOutcome::NO_SUCH_JUNCTION) {
            return $this->error('NOT_FOUND', 'Unknown junction.', 404);
        }
        if ($result->outcome === ControllerEventOutcome::UNKNOWN_COMMAND) {
            return $this->error('INVALID_CONTROLLER_EVENT', 'Unknown command.', 422);
        }

        return response()->json(['data' => [
            'junction_id' => $result->junctionId,
            'command_id' => $result->commandId,
            'outcome' => $result->outcome->value,
        ]], 202);
    }

    public function history(HistoryRequest $request, string $id): JsonResponse
    {
        $junction = $this->read->find($id);
        if ($junction === null) {
            return $this->error('NOT_FOUND', 'Unknown junction.', 404);
        }

        return response()->json(['data' => $this->read->history(
            $junction,
            (int) $request->validated('limit', 50),
            $request->validated('type'),
        )]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
