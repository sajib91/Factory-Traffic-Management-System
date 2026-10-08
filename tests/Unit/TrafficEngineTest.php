<?php

namespace Tests\Unit;

use App\Domain\Traffic\ConflictingGreenException;
use App\Domain\Traffic\FakeClock;
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

class TrafficEngineTest extends TestCase
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

    public function test_full_cycle_timing_and_signal_progression(): void
    {
        $state = JunctionState::initial('A', $this->config, $this->clock->now());
        $commands = [];

        $state = $this->tickAfter($state, 'NS_GREEN', 3, QueueSnapshot::of(PhaseQueue::empty(), PhaseQueue::empty()), $commands);
        $this->assertCommand($commands[0], Step::NS_GREEN, 'GREEN', ['NORTH', 'SOUTH']);

        $ewWaiting = QueueSnapshot::of(PhaseQueue::empty(), new PhaseQueue(5, 50, 0.0));

        $state = $this->tickAfter($state, 'NS_YELLOW', 10, $ewWaiting, $commands);
        $this->assertCommand($commands[1], Step::NS_YELLOW, 'YELLOW', ['NORTH', 'SOUTH']);

        $state = $this->tickAfter($state, 'ALL_RED', 5, $ewWaiting, $commands);
        $this->assertCommand($commands[2], Step::ALL_RED, 'RED', ['NORTH', 'SOUTH', 'EAST', 'WEST']);

        $state = $this->tickAfter($state, 'EW_GREEN', 2, $ewWaiting, $commands);
        $this->assertCommand($commands[3], Step::EW_GREEN, 'GREEN', ['EAST', 'WEST']);

        $nsWaiting = QueueSnapshot::of(new PhaseQueue(5, 50, 0.0), new PhaseQueue(1, 1, 0.0));

        $state = $this->tickAfter($state, 'EW_YELLOW', 10, $nsWaiting, $commands);
        $this->assertCommand($commands[4], Step::EW_YELLOW, 'YELLOW', ['EAST', 'WEST']);

        $state = $this->tickAfter($state, 'ALL_RED', 5, $nsWaiting, $commands);
        $this->assertCommand($commands[5], Step::ALL_RED, 'RED', ['NORTH', 'SOUTH', 'EAST', 'WEST']);

        $state = $this->tickAfter($state, 'NS_GREEN', 2, $nsWaiting, $commands);
        $this->assertCommand($commands[6], Step::NS_GREEN, 'GREEN', ['NORTH', 'SOUTH']);

        $this->assertSame(
            ['NS_GREEN', 'NS_YELLOW', 'ALL_RED', 'EW_GREEN', 'EW_YELLOW', 'ALL_RED', 'NS_GREEN'],
            array_map(fn (SendControllerCommand $command) => $command->step->value, $commands),
            'The step machine must follow the fixed safe sequence.',
        );
        $this->assertSame(Phase::NORTH_SOUTH, $state->step->phase());
    }

    public function test_min_green_blocks_early_switch(): void
    {
        $state = JunctionState::initial('A', $this->config, $this->clock->now());
        $commands = [];
        $state = $this->tickAfter($state, 'NS_GREEN', 3, QueueSnapshot::of(PhaseQueue::empty(), PhaseQueue::empty()), $commands);

        $ewWaiting = QueueSnapshot::of(PhaseQueue::empty(), new PhaseQueue(5, 50, 0.0));

        $this->clock->advance(9);
        $result = $this->engine->tick($state, $ewWaiting, $this->clock->now());
        $this->assertSame(Step::NS_GREEN, $result->state->step, 'Switch must be blocked before the 10s minimum green.');
        $this->assertCount(0, $result->effects());

        $this->clock->advance(1);
        $result = $this->engine->tick($result->state, $ewWaiting, $this->clock->now());
        $this->assertSame(Step::NS_YELLOW, $result->state->step, 'Switch must happen once the 10s minimum is reached.');
        $this->assertCount(1, $result->effects());
    }

    public function test_engine_throws_when_config_would_produce_conflicting_greens(): void
    {
        $config = new JunctionConfig(
            directions: ['NORTH', 'SOUTH', 'EAST', 'WEST'],
            phases: [
                new PhaseConfig(Phase::NORTH_SOUTH, ['NORTH', 'SOUTH'], 30),
                new PhaseConfig(Phase::EAST_WEST, ['EAST', 'NORTH'], 30),
            ],
            minGreenSeconds: 10,
            yellowSeconds: 5,
            allRedSeconds: 2,
        );
        $engine = new TrafficEngine($config, new Scheduler($config));
        $state = JunctionState::initial('A', $config, $this->clock->now());

        $this->expectException(ConflictingGreenException::class);

        $this->clock->advance(3);
        $engine->tick(
            $state,
            QueueSnapshot::of(
                PhaseQueue::empty(),
                new PhaseQueue(1, 1, 0.0),
            ),
            $this->clock->now(),
        );
    }

    public function test_idle_ticks_produce_no_effects(): void
    {
        $state = JunctionState::initial('A', $this->config, $this->clock->now());

        $result = $this->engine->tick($state, QueueSnapshot::of(PhaseQueue::empty(), PhaseQueue::empty()), $this->clock->now());

        $this->assertSame(Step::ALL_RED, $result->state->step);
        $this->assertCount(0, $result->effects());
    }

    public function test_engine_rejects_degraded_mode(): void
    {
        $state = JunctionState::initial('A', $this->config, $this->clock->now(), Mode::DEGRADED);

        $this->expectException(\LogicException::class);

        $this->engine->tick($state, QueueSnapshot::of(PhaseQueue::empty(), PhaseQueue::empty()), $this->clock->now());
    }

    public function test_controller_ack_is_an_idle_noop(): void
    {
        $state = JunctionState::initial('A', $this->config, $this->clock->now());
        $result = $this->engine->handleControllerAck($state, $state->desiredSignals);

        $this->assertSame($state, $result->state);
        $this->assertCount(0, $result->effects());
    }

    private function tickAfter(
        JunctionState $state,
        string $targetStep,
        int $advanceSeconds,
        QueueSnapshot $snapshot,
        array &$commands,
    ): JunctionState {
        $this->clock->advance($advanceSeconds);
        $result = $this->engine->tick($state, $snapshot, $this->clock->now());

        foreach ($result->effects() as $effect) {
            if ($effect instanceof SendControllerCommand) {
                $commands[] = $effect;
            }
        }

        $this->assertSame(Step::from($targetStep), $result->state->step);

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
