<?php

namespace Tests\Feature;

use App\Application\Traffic\ControllerEvent;
use App\Application\Traffic\TrafficService;
use App\Domain\Traffic\FakeClock;
use App\Infrastructure\Models\AuditLog;
use App\Infrastructure\Models\ControllerCommand;
use App\Infrastructure\Models\Junction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TrafficRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_restart_mid_yellow_cannot_desire_green_before_recovery_ack(): void
    {
        $this->seed();
        $clock = FakeClock::at('2026-01-01 00:00:00');
        $service = new TrafficService(clock: $clock);
        Junction::query()->whereKey('A')->update([
            'mode' => 'AUTOMATIC',
            'phase' => 'NS_YELLOW',
            'desired_signals' => ['NORTH' => 'YELLOW', 'SOUTH' => 'YELLOW', 'EAST' => 'RED', 'WEST' => 'RED'],
            'actual_signals' => ['NORTH' => 'YELLOW', 'SOUTH' => 'YELLOW', 'EAST' => 'RED', 'WEST' => 'RED'],
        ]);
        ControllerCommand::query()->create([
            'command_id' => 'old-command',
            'junction_id' => 'A',
            'desired_signals' => ['NORTH' => 'YELLOW', 'SOUTH' => 'YELLOW', 'EAST' => 'RED', 'WEST' => 'RED'],
            'status' => 'DISPATCHED',
            'attempts' => 0,
        ]);

        $recovery = $service->recover('A');
        $junction = Junction::query()->findOrFail('A');

        $this->assertSame('DEGRADED', $junction->mode);
        $this->assertEquals(['NORTH' => 'RED', 'SOUTH' => 'RED', 'EAST' => 'RED', 'WEST' => 'RED'], $junction->desired_signals);
        $this->assertEquals(['NORTH' => 'UNKNOWN', 'SOUTH' => 'UNKNOWN', 'EAST' => 'UNKNOWN', 'WEST' => 'UNKNOWN'], $junction->actual_signals);
        $this->assertSame('SUPERSEDED', ControllerCommand::query()->whereKey('old-command')->value('status'));
        $this->assertSame('RECOVERY_STARTED', AuditLog::query()->latest('id')->value('event_type'));

        $this->assertNotSame('GREEN', $junction->desired_signals['NORTH']);
        $service->controllerEvent(new ControllerEvent('A', $recovery['command_id']));

        $junction->refresh();
        $this->assertSame('AUTOMATIC', $junction->mode);
        $this->assertSame('ALL_RED', $junction->phase);
        $this->assertSame('2026-01-01 00:00:00', $junction->step_started_at->format('Y-m-d H:i:s'));
    }
}
