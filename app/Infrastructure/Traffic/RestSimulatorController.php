<?php

namespace App\Infrastructure\Traffic;

use App\Application\Traffic\ControllerEvent;
use App\Application\Traffic\TrafficService;
use App\Domain\Traffic\ControllerCommand as DomainControllerCommand;
use App\Domain\Traffic\ControllerPort;
use Illuminate\Support\Facades\DB;

final class RestSimulatorController implements ControllerPort
{
    public function __construct(
        private readonly TrafficService $service,
        private readonly bool $autoAck = false,
        private $ackHandler = null,
    ) {}

    public function send(DomainControllerCommand $command): void
    {
        // Store/update command as PENDING
        DB::table('controller_commands')->updateOrInsert(
            ['command_id' => $command->commandId],
            [
                'junction_id' => $command->junctionId,
                'desired_signals' => json_encode($command->desiredSignals),
                'status' => 'PENDING',
                'attempts' => DB::raw('COALESCE(attempts, 0)'),
            ]
        );

        if ($this->autoAck || (bool) env('TRAFFIC_AUTO_ACK', false)) {
            if ($this->ackHandler !== null) {
                ($this->ackHandler)($command);
            } else {
                $this->service->controllerEvent(new ControllerEvent(
                    $command->junctionId,
                    $command->commandId,
                    $command->desiredSignals
                ));
            }
        }
    }
}
