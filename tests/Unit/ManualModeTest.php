<?php

namespace Tests\Unit;

use App\Domain\Traffic\CommandDuringEmergencyException;
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
use App\Domain\Traffic\Step;
use App\Domain\Traffic\TrafficEngine;
use PHPUnit\Framework\TestCase;

class ManualModeTest extends TestCase
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

    public function test_manual_uses_safe_sequence_to_reach_target(): void
    {
        $state = $this->stateAt(Step::ALL_RED);

        $state = $this->engine->handleCommand(
            $state,
            CommandRequest::manualGreen(Direction::EAST),
            $this->clock->now(),
        )->state;

        $this->assertSame(Mode::MANUAL, $state->mode);
        $this->assertSame(Direction::EAST, $state->manual?->direction);
        $this->assertSame(Step::ALL_RED, $state->step, 'No immediate jump allowed while in all-red.');

        $state = $this->tickAfter($state, Step::EW_GREEN, 2);

        $this->assertSame(Mode::MANUAL, $state->mode);
        $this->assertSame(Step::EW_GREEN, $state->step);
    }

    public function test_manual_preempts_conflicting_green_through_safe_sequence(): void
    {
        $state = $this->stateAt(Step::NS_GREEN);

        $state = $this->engine->handleCommand(
            $state,
            CommandRequest::manualGreen(Direction::EAST),
            $this->clock->now(),
        )->state;

        $this->assertSame(Step::NS_YELLOW, $state->step, 'Conflicting green must start yielding immediately.');

        $state = $this->tickAfter($state, Step::ALL_RED, 5);
        $state = $this->tickAfter($state, Step::EW_GREEN, 2);

        $this->assertSame(Mode::MANUAL, $state->mode);
        $this->assertSame(Step::EW_GREEN, $state->step);
    }

    public function test_manual_expires_after_five_minutes(): void
    {
        $state = $this->stateAt(Step::ALL_RED);

        $state = $this->engine->handleCommand(
            $state,
            CommandRequest::manualGreen(Direction::EAST),
            $this->clock->now(),
        )->state;
        $state = $this->tickAfter($state, Step::EW_GREEN, 2);

        $this->clock->advance(297);
        $state = $this->engine->tick($state, QueueSnapshot::of(PhaseQueue::empty(), PhaseQueue::empty()), $this->clock->now())->state;
        $this->assertSame(Mode::MANUAL, $state->mode, 'Manual must hold before the 5 minute expiry.');

        $this->clock->advance(1);
        $result = $this->engine->tick($state, QueueSnapshot::of(PhaseQueue::empty(), PhaseQueue::empty()), $this->clock->now());

        $this->assertSame(Mode::AUTOMATIC, $result->state->mode, 'Manual must expire back to automatic after 5 minutes.');
        $this->assertNull($result->state->manual);
    }

    public function test_return_to_automatic_command(): void
    {
        $state = $this->stateAt(Step::ALL_RED);

        $state = $this->engine->handleCommand(
            $state,
            CommandRequest::manualGreen(Direction::NORTH),
            $this->clock->now(),
        )->state;

        $result = $this->engine->handleCommand(
            $state,
            CommandRequest::returnToAutomatic(),
            $this->clock->now(),
        );

        $this->assertSame(Mode::AUTOMATIC, $result->state->mode);
        $this->assertNull($result->state->manual);
    }

    public function test_return_to_automatic_is_a_noop_when_already_automatic(): void
    {
        $state = $this->stateAt(Step::ALL_RED);

        $result = $this->engine->handleCommand(
            $state,
            CommandRequest::returnToAutomatic(),
            $this->clock->now(),
        );

        $this->assertSame($state, $result->state);
        $this->assertCount(0, $result->effects());
    }

    public function test_manual_with_invalid_direction_is_rejected(): void
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

        $engine->handleCommand(
            $state,
            CommandRequest::manualGreen(Direction::WEST),
            $this->clock->now(),
        );
    }

    public function test_manual_is_rejected_during_emergency(): void
    {
        $state = $this->stateAt(Step::ALL_RED);

        $state = $this->engine->handleEmergencyDetected(
            $state,
            Direction::NORTH,
            'amb-1',
            $this->clock->now(),
        )->state;
        $this->assertSame(Mode::EMERGENCY, $state->mode);

        $this->expectException(CommandDuringEmergencyException::class);

        $this->engine->handleCommand(
            $state,
            CommandRequest::manualGreen(Direction::EAST),
            $this->clock->now(),
        );
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
}
