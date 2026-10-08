<?php

namespace Tests\Unit;

use App\Domain\Traffic\ConflictingGreenException;
use App\Domain\Traffic\JunctionConfig;
use App\Domain\Traffic\SignallingGuard;
use PHPUnit\Framework\TestCase;

class SignallingGuardTest extends TestCase
{
    private JunctionConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = JunctionConfig::default();
    }

    public function test_single_phase_greens_are_allowed(): void
    {
        $signals = [
            'NORTH' => 'GREEN',
            'SOUTH' => 'GREEN',
            'EAST' => 'RED',
            'WEST' => 'RED',
        ];

        SignallingGuard::assertNoConflictingGreens($signals, $this->config);

        $this->assertTrue(true);
    }

    public function test_all_red_is_allowed(): void
    {
        $signals = [
            'NORTH' => 'RED',
            'SOUTH' => 'RED',
            'EAST' => 'RED',
            'WEST' => 'RED',
        ];

        SignallingGuard::assertNoConflictingGreens($signals, $this->config);

        $this->assertTrue(true);
    }

    public function test_conflicting_greens_across_phases_throw(): void
    {
        $signals = [
            'NORTH' => 'GREEN',
            'SOUTH' => 'RED',
            'EAST' => 'GREEN',
            'WEST' => 'RED',
        ];

        $this->expectException(ConflictingGreenException::class);
        $this->expectExceptionMessage('Conflicting GREEN');

        SignallingGuard::assertNoConflictingGreens($signals, $this->config);
    }
}
