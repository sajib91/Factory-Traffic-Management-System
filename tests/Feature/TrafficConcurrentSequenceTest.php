<?php

namespace Tests\Feature;

use App\Application\Traffic\CommandOutcome;
use App\Application\Traffic\ControllerEvent;
use App\Application\Traffic\ControllerEventOutcome;
use App\Application\Traffic\EffectDispatcher;
use App\Application\Traffic\SensorEvent;
use App\Application\Traffic\SensorEventOutcome;
use App\Application\Traffic\TickResult;
use App\Application\Traffic\TrafficService;
use App\Domain\Traffic\CommandRequest;
use App\Domain\Traffic\Direction;
use App\Domain\Traffic\FakeClock;
use App\Domain\Traffic\JunctionConfig;
use App\Domain\Traffic\SendControllerCommand;
use App\Domain\Traffic\SignallingGuard;
use App\Domain\Traffic\Step;
use App\Infrastructure\Models\AuditLog;
use App\Infrastructure\Models\ControllerCommand;
use App\Infrastructure\Models\Junction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrafficConcurrentSequenceTest extends TestCase
{
    use RefreshDatabase;

    private FakeClock $clock;

    private RecordingEffectDispatcher $dispatcher;

    private TrafficService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->clock = FakeClock::at('2026-01-01 00:00:00');
        $this->dispatcher = new RecordingEffectDispatcher;
        $this->service = new TrafficService(clock: $this->clock, dispatcher: $this->dispatcher);
    }

    public function test_truck_emergency_manual_duplicate_and_ack_never_show_conflicting_greens(): void
    {
        $config = JunctionConfig::fromArray(Junction::query()->findOrFail('A')->config);
        $this->startServingNorthSouth($config);

        $truck = $this->service->sensorEvent($this->sensor(['event_id' => 'evt-1', 'vehicle_id' => 'truck-1', 'sequence_no' => 1]));
        $this->assertSame(SensorEventOutcome::ARRIVAL_APPLIED, $truck->outcome);

        $emergency = $this->service->sensorEvent($this->sensor([
            'event_id' => 'evt-2',
            'vehicle_type' => 'EMERGENCY',
            'vehicle_id' => 'amb-1',
            'direction' => 'EAST',
            'sequence_no' => 1,
        ]));
        $this->assertSame(SensorEventOutcome::ARRIVAL_APPLIED, $emergency->outcome);

        $junction = Junction::query()->findOrFail('A');
        $this->assertSame('EMERGENCY', $junction->mode);
        $this->assertSame('NS_YELLOW', $junction->phase);
        $this->assertEquals(['NORTH' => 'YELLOW', 'SOUTH' => 'YELLOW', 'EAST' => 'RED', 'WEST' => 'RED'], $junction->desired_signals);
        $this->assertCount(1, $this->dispatcher->dispatched);

        $manual = $this->service->command('A', CommandRequest::manualGreen(Direction::SOUTH));
        $this->assertSame(CommandOutcome::REJECTED_DURING_EMERGENCY, $manual->outcome);

        $duplicate = $this->service->sensorEvent($this->sensor([
            'event_id' => 'evt-3',
            'vehicle_type' => 'EMERGENCY',
            'vehicle_id' => 'amb-1',
            'direction' => 'EAST',
            'sequence_no' => 2,
        ]));
        $this->assertSame(SensorEventOutcome::ARRIVAL_APPLIED, $duplicate->outcome);

        $junction = Junction::query()->findOrFail('A');
        $this->assertSame('NS_YELLOW', $junction->phase);
        $this->assertCount(1, $this->dispatcher->dispatched, 'Duplicate emergencies must never emit another command.');

        $command = ControllerCommand::query()->firstOrFail();
        $ack = $this->service->controllerEvent(new ControllerEvent('A', $command->command_id));
        $this->assertSame(ControllerEventOutcome::ACKED, $ack->outcome);

        $junction = Junction::query()->findOrFail('A');
        $this->assertEquals(['NORTH' => 'YELLOW', 'SOUTH' => 'YELLOW', 'EAST' => 'RED', 'WEST' => 'RED'], $junction->actual_signals);
        $this->assertSame('ACKED', $command->refresh()->status);

        $this->assertNoConflictingGreenEver($config);
    }

    public function test_tick_advances_the_step_and_dispatches_effects_after_commit(): void
    {
        $config = JunctionConfig::fromArray(Junction::query()->findOrFail('A')->config);

        Junction::query()->whereKey('A')->update([
            'mode' => 'AUTOMATIC',
            'phase' => 'ALL_RED',
            'step_started_at' => '2026-01-01 00:00:00',
            'desired_signals' => ['NORTH' => 'RED', 'SOUTH' => 'RED', 'EAST' => 'RED', 'WEST' => 'RED'],
            'actual_signals' => ['NORTH' => 'RED', 'SOUTH' => 'RED', 'EAST' => 'RED', 'WEST' => 'RED'],
        ]);

        $this->clock->advance(2);
        $result = $this->service->tick('A');

        $this->assertInstanceOf(TickResult::class, $result);
        $this->assertTrue($result->transitioned);
        $this->assertSame(Step::NS_GREEN, $result->step);
        $this->assertSame(['A'], array_map(fn (SendControllerCommand $command) => $command->junctionId, array_values($this->dispatcher->dispatched)));

        $this->assertCount(1, ControllerCommand::query()->get());
        $this->assertSame(1, AuditLog::query()->where('event_type', 'TICK')->count());

        $junction = Junction::query()->findOrFail('A');
        $this->assertSame('NS_GREEN', $junction->phase);
        $this->assertNoConflictingGreenEver($config);
    }

    private function startServingNorthSouth(JunctionConfig $config): void
    {
        $desired = $config->desiredSignalsFor(Step::NS_GREEN);

        Junction::query()->whereKey('A')->update([
            'mode' => 'AUTOMATIC',
            'phase' => 'NS_GREEN',
            'step_started_at' => '2026-01-01 00:00:00',
            'desired_signals' => $desired,
            'actual_signals' => $desired,
        ]);
    }

    private function assertNoConflictingGreenEver(JunctionConfig $config): void
    {
        $junction = Junction::query()->findOrFail('A');

        SignallingGuard::assertNoConflictingGreens($junction->desired_signals, $config);
        SignallingGuard::assertNoConflictingGreens($junction->actual_signals, $config);

        foreach (ControllerCommand::query()->get() as $command) {
            SignallingGuard::assertNoConflictingGreens($command->desired_signals, $config);
        }

        foreach (AuditLog::query()->get() as $log) {
            $signals = $log->payload['desired_signals'] ?? null;

            if (is_array($signals)) {
                SignallingGuard::assertNoConflictingGreens($signals, $config);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function sensor(array $overrides): SensorEvent
    {
        return SensorEvent::fromArray(array_merge([
            'event_id' => 'evt-0',
            'junction_id' => 'A',
            'direction' => 'NORTH',
            'vehicle_type' => 'TRUCK',
            'event_type' => 'VEHICLE_ARRIVED',
            'vehicle_id' => 'v-0',
            'sequence_no' => 1,
            'occurred_at' => '2026-01-01 00:00:00',
        ], $overrides));
    }
}

final class RecordingEffectDispatcher implements EffectDispatcher
{
    /** @var array<string, SendControllerCommand> */
    public array $dispatched = [];

    public function dispatch(string $commandId, SendControllerCommand $effect): void
    {
        $this->dispatched[$commandId] = $effect;
    }
}
