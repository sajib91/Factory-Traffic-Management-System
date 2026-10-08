<?php

namespace Tests\Unit;

use App\Domain\Traffic\CommandRequest;
use App\Domain\Traffic\Direction;
use App\Domain\Traffic\FakeClock;
use App\Domain\Traffic\InvalidDirectionException;
use App\Domain\Traffic\JunctionConfig;
use App\Domain\Traffic\JunctionState;
use App\Domain\Traffic\Mode;
use App\Domain\Traffic\Phase;
use App\Domain\Traffic\PhaseConfig;
use App\Domain\Traffic\PhaseQueue;
use App\Domain\Traffic\QueueSnapshot;
use App\Domain\Traffic\Scheduler;
use App\Domain\Traffic\SendControllerCommand;
use App\Domain\Traffic\SignalColor;
use App\Domain\Traffic\SignallingGuard;
use App\Domain\Traffic\Step;
use App\Domain\Traffic\TrafficEngine;
use PHPUnit\Framework\TestCase;

class EmergencyModeTest extends TestCase
{
    private FakeClock $clock;

    private JunctionConfig $config;

    private TrafficEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = FakeClock::at('2026-01-01 00:00:00');
        $this->config = JunctionConfig::default();
        $this->engine = new TrafficEngine($this->config, new Scheduler($this->config));
    }

    public function test_emergency_preempts_conflicting_green_through_safe_sequence(): void
    {
        $state = $this->stateAt(Step::EW_GREEN);

        $result = $this->engine->handleEmergencyDetected($state, Direction::NORTH, 'amb-1', $this->clock->now());

        $this->assertSame(Mode::EMERGENCY, $result->state->mode);
        $this->assertSame(Step::EW_YELLOW, $result->state->step, 'Conflicting green must give way to yellow immediately.');
        $this->assertCommand($result->effects()[0], Step::EW_YELLOW, 'YELLOW', ['EAST', 'WEST']);

        $state = $this->tickAfter($result->state, Step::ALL_RED, 5);
        $state = $this->tickAfter($state, Step::NS_GREEN, 2);

        $this->assertSame(Mode::EMERGENCY, $state->mode);
        $this->assertSame('amb-1', $state->emergency?->active?->vehicleId);
    }

    public function test_emergency_survives_cleared_and_returns_to_automatic(): void
    {
        $state = $this->stateAt(Step::EW_GREEN);

        $state = $this->engine->handleEmergencyDetected($state, Direction::NORTH, 'amb-1', $this->clock->now())->state;
        $state = $this->tickAfter($state, Step::ALL_RED, 5);
        $state = $this->tickAfter($state, Step::NS_GREEN, 2);

        $result = $this->engine->handleEmergencyCleared($state, $this->clock->now(), 'amb-1');

        $this->assertSame(Mode::AUTOMATIC, $result->state->mode);
        $this->assertNull($result->state->emergency);
        $this->assertSame(Step::NS_GREEN, $result->state->step, 'Clear must not drop an already served green.');
        $this->assertCount(0, $result->effects());
    }

    public function test_emergency_during_manual_overrides_and_drops_manual(): void
    {
        $state = $this->stateAt(Step::EW_GREEN);

        $state = $this->engine->handleCommand(
            $state,
            CommandRequest::manualGreen(Direction::EAST),
            $this->clock->now(),
        )->state;
        $this->assertSame(Mode::MANUAL, $state->mode);

        $result = $this->engine->handleEmergencyDetected($state, Direction::NORTH, 'amb-1', $this->clock->now());

        $this->assertSame(Mode::EMERGENCY, $result->state->mode);
        $this->assertNull($result->state->manual);
        $this->assertSame(Step::EW_YELLOW, $result->state->step);
    }

    public function test_duplicate_emergency_is_a_noop(): void
    {
        $state = $this->engine->handleEmergencyDetected(
            $this->stateAt(Step::ALL_RED),
            Direction::NORTH,
            'amb-1',
            $this->clock->now(),
        )->state;

        $result = $this->engine->handleEmergencyDetected($state, Direction::NORTH, 'amb-1', $this->clock->now());

        $this->assertSame($state, $result->state, 'Duplicate active emergency must not change state.');
        $this->assertCount(0, $result->effects());
    }

    public function test_duplicate_queued_emergency_is_a_noop(): void
    {
        $state = $this->engine->handleEmergencyDetected(
            $this->stateAt(Step::EW_GREEN),
            Direction::NORTH,
            'amb-1',
            $this->clock->now(),
        )->state;

        $state = $this->engine->handleEmergencyDetected($state, Direction::EAST, 'amb-2', $this->clock->now())->state;
        $result = $this->engine->handleEmergencyDetected($state, Direction::EAST, 'amb-2', $this->clock->now());

        $this->assertSame($state, $result->state, 'Duplicate queued emergency must not be re-queued.');
        $this->assertCount(1, $result->state->emergency?->waiting);
    }

    public function test_conflicting_emergencies_are_served_fifo(): void
    {
        $state = $this->stateAt(Step::ALL_RED);

        $state = $this->engine->handleEmergencyDetected($state, Direction::NORTH, 'amb-1', $this->clock->now())->state;
        $state = $this->tickAfter($state, Step::NS_GREEN, 2);

        $state = $this->engine->handleEmergencyDetected($state, Direction::EAST, 'amb-2', $this->clock->now())->state;
        $state = $this->engine->handleEmergencyDetected($state, Direction::SOUTH, 'amb-3', $this->clock->now())->state;

        $this->assertSame('amb-1', $state->emergency?->active?->vehicleId);
        $this->assertSame(
            ['amb-2', 'amb-3'],
            array_map(fn ($request) => $request->vehicleId, $state->emergency->waiting),
            'Second and third emergencies must queue FIFO.',
        );

        $state = $this->engine->handleEmergencyCleared($state, $this->clock->now(), 'amb-1')->state;

        $this->assertSame('amb-2', $state->emergency?->active?->vehicleId);
        $this->assertCount(1, $state->emergency?->waiting);
        $this->assertSame(Step::NS_YELLOW, $state->step, 'Handoff must preempt the now-conflicting green.');
    }

    public function test_cleared_with_wrong_vehicle_id_is_a_noop(): void
    {
        $state = $this->engine->handleEmergencyDetected(
            $this->stateAt(Step::ALL_RED),
            Direction::NORTH,
            'amb-1',
            $this->clock->now(),
        )->state;

        $result = $this->engine->handleEmergencyCleared($state, $this->clock->now(), 'amb-9');

        $this->assertSame($state, $result->state);
        $this->assertCount(0, $result->effects());
    }

    public function test_emergency_times_out_after_90_seconds_of_target_green(): void
    {
        $state = $this->stateAt(Step::EW_GREEN);

        $state = $this->engine->handleEmergencyDetected($state, Direction::NORTH, 'amb-1', $this->clock->now())->state;
        $state = $this->tickAfter($state, Step::ALL_RED, 5);
        $state = $this->tickAfter($state, Step::NS_GREEN, 2);

        $this->clock->advance(82);
        $state = $this->engine->tick($state, QueueSnapshot::of(PhaseQueue::empty(), PhaseQueue::empty()), $this->clock->now())->state;
        $this->assertSame(Mode::EMERGENCY, $state->mode, 'Emergency must still be active before the 90s timeout.');

        $this->clock->advance(1);
        $result = $this->engine->tick($state, QueueSnapshot::of(PhaseQueue::empty(), PhaseQueue::empty()), $this->clock->now());

        $this->assertSame(Mode::AUTOMATIC, $result->state->mode, 'Emergency must be cleared after 90s of served green.');
        $this->assertSame(Step::NS_GREEN, $result->state->step);
    }

    public function test_emergency_with_unknown_direction_is_rejected(): void
    {
        $config = new JunctionConfig(
            directions: ['NORTH', 'SOUTH', 'EAST'],
            phases: [
                new PhaseConfig(Phase::NORTH_SOUTH, ['NORTH', 'SOUTH'], 30),
                new PhaseConfig(Phase::EAST_WEST, ['EAST'], 30),
            ],
            minGreenSeconds: 10,
            yellowSeconds: 5,
            allRedSeconds: 2,
        );
        $engine = new TrafficEngine($config, new Scheduler($config));
        $state = JunctionState::initial('A', $config, $this->clock->now());

        $this->expectException(InvalidDirectionException::class);

        $engine->handleEmergencyDetected($state, Direction::WEST, 'amb-1', $this->clock->now());
    }

    private function stateAt(Step $step): JunctionState
    {
        return new JunctionState(
            id: 'A',
            mode: Mode::AUTOMATIC,
            modeStartedAt: $this->clock->now(),
            step: $step,
            stepStartedAt: $this->clock->now(),
            desiredSignals: $this->config->desiredSignalsFor($step),
        );
    }

    private function tickAfter(JunctionState $state, Step $targetStep, int $advanceSeconds): JunctionState
    {
        $this->clock->advance($advanceSeconds);
        $result = $this->engine->tick($state, QueueSnapshot::of(PhaseQueue::empty(), PhaseQueue::empty()), $this->clock->now());

        $this->assertSame($targetStep, $result->state->step);

        return $result->state;
    }

    private function assertCommand(
        SendControllerCommand $command,
        Step $step,
        string $color,
        array $greenDirections,
    ): void {
        $this->assertSame($step, $command->step);
        $this->assertSame('A', $command->junctionId);

        $expected = [];
        foreach ($this->config->directions() as $direction) {
            $expected[$direction->value] = in_array($direction->value, $greenDirections, true)
                ? $color
                : SignalColor::RED->value;
        }

        $this->assertEquals($expected, $command->desiredSignals);

        SignallingGuard::assertNoConflictingGreens($command->desiredSignals, $this->config);
    }
}
