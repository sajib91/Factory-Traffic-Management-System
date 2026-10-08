<?php

namespace Tests\Unit;

use App\Domain\Traffic\JunctionConfig;
use App\Domain\Traffic\Phase;
use App\Domain\Traffic\PhaseQueue;
use App\Domain\Traffic\QueueSnapshot;
use App\Domain\Traffic\Scheduler;
use PHPUnit\Framework\TestCase;

class SchedulerTest extends TestCase
{
    private Scheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduler = new Scheduler(JunctionConfig::default());
    }

    public function test_never_switches_before_min_green(): void
    {
        $snapshot = QueueSnapshot::of(
            northSouth: PhaseQueue::empty(),
            eastWest: new PhaseQueue(20, 500, 120.0),
        );

        $this->assertFalse(
            $this->scheduler->shouldSwitchTo(Phase::NORTH_SOUTH, $snapshot, 9),
            'No switch allowed before the 10s minimum green.',
        );
    }

    public function test_no_switch_when_other_phase_is_empty(): void
    {
        $snapshot = QueueSnapshot::of(
            northSouth: new PhaseQueue(20, 500, 0.0),
            eastWest: PhaseQueue::empty(),
        );

        $this->assertFalse($this->scheduler->shouldSwitchTo(Phase::NORTH_SOUTH, $snapshot, 30));
    }

    public function test_anti_flapping_blocks_a_weak_switch_candidate(): void
    {
        $snapshot = QueueSnapshot::of(
            northSouth: new PhaseQueue(10, 100, 0.0),
            eastWest: new PhaseQueue(5, 60, 0.0),
        );

        $this->assertFalse(
            $this->scheduler->shouldSwitchTo(Phase::NORTH_SOUTH, $snapshot, 15),
            '60 is not greater than 1.2 x 100, so the switch must be blocked.',
        );
    }

    public function test_switch_allowed_when_candidate_exceeds_anti_flap_factor(): void
    {
        $snapshot = QueueSnapshot::of(
            northSouth: new PhaseQueue(10, 100, 0.0),
            eastWest: new PhaseQueue(5, 130, 0.0),
        );

        $this->assertTrue(
            $this->scheduler->shouldSwitchTo(Phase::NORTH_SOUTH, $snapshot, 15),
            '130 is greater than 1.2 x 100, so the switch must happen.',
        );
    }

    public function test_starvation_bypasses_the_anti_flap_factor(): void
    {
        $snapshot = QueueSnapshot::of(
            northSouth: new PhaseQueue(10, 500, 0.0),
            eastWest: new PhaseQueue(2, 6, 120.0),
        );

        $this->assertTrue(
            $this->scheduler->shouldSwitchTo(Phase::NORTH_SOUTH, $snapshot, 15),
            'A queue waiting over 90s must win even against a much higher score.',
        );
    }

    public function test_normal_green_duration_forces_switch_when_other_waiting(): void
    {
        $snapshot = QueueSnapshot::of(
            northSouth: new PhaseQueue(10, 500, 0.0),
            eastWest: new PhaseQueue(1, 1, 1.0),
        );

        $this->assertTrue(
            $this->scheduler->shouldSwitchTo(Phase::NORTH_SOUTH, $snapshot, 30),
            'The 30s normal green must time-box the current phase.',
        );
    }

    public function test_all_red_picks_the_highest_scoring_phase(): void
    {
        $snapshot = QueueSnapshot::of(
            northSouth: new PhaseQueue(3, 9, 10.0),
            eastWest: new PhaseQueue(10, 50, 20.0),
        );

        $this->assertSame(Phase::EAST_WEST, $this->scheduler->pickNextPhaseAfterAllRed($snapshot));
    }

    public function test_all_red_falls_back_to_the_sequence_when_both_empty(): void
    {
        $snapshot = QueueSnapshot::of(PhaseQueue::empty(), PhaseQueue::empty());

        $this->assertSame(Phase::NORTH_SOUTH, $this->scheduler->pickNextPhaseAfterAllRed($snapshot));
    }

    public function test_score_includes_half_the_oldest_wait_seconds(): void
    {
        $snapshot = QueueSnapshot::of(
            northSouth: new PhaseQueue(1, 40, 0.0),
            eastWest: new PhaseQueue(1, 0, 100.0),
        );

        $this->assertSame(
            Phase::EAST_WEST,
            $this->scheduler->pickNextPhaseAfterAllRed($snapshot),
            'Seniority (0.5 x 100s = 50) outweighs the 40 weight of the fresh queue.',
        );
    }
}
