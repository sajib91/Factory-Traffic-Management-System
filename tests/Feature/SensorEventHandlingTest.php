<?php

namespace Tests\Feature;

use App\Application\Traffic\SensorEventHandler;
use App\Application\Traffic\SensorEventOutcome;
use App\Domain\Traffic\FakeClock;
use App\Infrastructure\Models\AuditLog;
use App\Infrastructure\Models\ControllerCommand;
use App\Infrastructure\Models\Junction;
use App\Infrastructure\Models\ProcessedEvent;
use App\Infrastructure\Models\QueuedVehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SensorEventHandlingTest extends TestCase
{
    use RefreshDatabase;

    private SensorEventHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->handler = new SensorEventHandler(FakeClock::at('2026-01-01 00:00:00'));
    }

    public function test_duplicate_event_id_is_rejected_without_queue_change(): void
    {
        $result = $this->handler->handlePayload($this->payload());

        $this->assertSame(SensorEventOutcome::ARRIVAL_APPLIED, $result->outcome);
        $this->assertSame(1, QueuedVehicle::count());

        $duplicate = $this->handler->handlePayload($this->payload());

        $this->assertSame(SensorEventOutcome::DUPLICATE, $duplicate->outcome);
        $this->assertSame(1, QueuedVehicle::count(), 'A duplicate must not change the queue.');
        $this->assertSame(1, ProcessedEvent::where('event_id', 'evt-1')->count());

        $audit = AuditLog::query()->latest('id')->first();
        $this->assertSame('duplicate event_id', $audit->payload['reason']);
    }

    public function test_out_of_order_sequence_is_rejected(): void
    {
        $this->handler->handlePayload($this->payload(['event_id' => 'evt-1', 'vehicle_id' => 'v-1', 'sequence_no' => 3]));

        $result = $this->handler->handlePayload($this->payload(['event_id' => 'evt-2', 'vehicle_id' => 'v-2', 'sequence_no' => 2]));

        $this->assertSame(SensorEventOutcome::OUT_OF_ORDER, $result->outcome);
        $this->assertSame(1, QueuedVehicle::count(), 'An out-of-order event must not enter the queue.');

        $junction = Junction::query()->findOrFail('A');
        $this->assertSame(3, $junction->last_sequences['NORTH'], 'The cursor must not move on a rejected event.');

        $audit = AuditLog::query()->latest('id')->first();
        $this->assertSame('out of order sequence', $audit->payload['reason']);
    }

    public function test_stale_event_is_rejected(): void
    {
        $result = $this->handler->handlePayload($this->payload([
            'event_id' => 'evt-1',
            'occurred_at' => '2025-12-31 23:55:00',
        ]));

        $this->assertSame(SensorEventOutcome::STALE, $result->outcome);
        $this->assertSame(0, QueuedVehicle::count(), 'A stale event must not enter the queue.');
        $this->assertSame('stale event', AuditLog::query()->latest('id')->first()->payload['reason']);
    }

    public function test_unknown_junction_is_rejected_and_audited(): void
    {
        $result = $this->handler->handlePayload($this->payload(['junction_id' => 'ZZZ']));

        $this->assertSame(SensorEventOutcome::INVALID, $result->outcome);
        $this->assertSame('unknown junction', $result->reason);
        $this->assertNull($result->queueCountAfter);
        $this->assertSame(0, QueuedVehicle::count());
        $this->assertSame(0, ProcessedEvent::count());

        $audit = AuditLog::query()->where('event_type', 'VEHICLE_ARRIVED')->first();
        $this->assertSame('ZZZ', $audit->junction_id);
        $this->assertSame('unknown junction', $audit->payload['reason']);
    }

    public function test_clear_without_arrival_is_rejected(): void
    {
        $result = $this->handler->handlePayload($this->payload([
            'event_id' => 'evt-1',
            'event_type' => 'VEHICLE_CLEARED',
            'vehicle_id' => 'v-999',
        ]));

        $this->assertSame(SensorEventOutcome::CLEARED_WITHOUT_ARRIVAL, $result->outcome);
        $this->assertSame(0, QueuedVehicle::count());
        $this->assertSame('no arrival exists', AuditLog::query()->latest('id')->first()->payload['reason']);
    }

    public function test_queue_never_goes_negative(): void
    {
        $this->handler->handlePayload($this->payload(['event_id' => 'evt-1', 'event_type' => 'VEHICLE_CLEARED', 'vehicle_id' => 'v-999', 'sequence_no' => 1]));
        $this->assertSame(0, QueuedVehicle::count());

        $this->handler->handlePayload($this->payload(['event_id' => 'evt-2', 'event_type' => 'VEHICLE_CLEARED', 'vehicle_id' => 'v-999', 'sequence_no' => 2]));
        $this->assertSame(0, QueuedVehicle::count(), 'Queue must not dip below zero.');

        $this->handler->handlePayload($this->payload(['event_id' => 'evt-3', 'vehicle_id' => 'v-1', 'sequence_no' => 3]));
        $this->assertSame(1, QueuedVehicle::count());

        $this->handler->handlePayload($this->payload(['event_id' => 'evt-4', 'event_type' => 'VEHICLE_CLEARED', 'vehicle_id' => 'v-1', 'sequence_no' => 4]));
        $this->assertSame(0, QueuedVehicle::count());

        $this->handler->handlePayload($this->payload(['event_id' => 'evt-5', 'event_type' => 'VEHICLE_CLEARED', 'vehicle_id' => 'v-1', 'sequence_no' => 5]));
        $this->assertSame(0, QueuedVehicle::count(), 'Second clear must be rejected, not take the queue negative.');
    }

    public function test_emergency_arrival_preempts_conflicting_green_and_records_command(): void
    {
        Junction::query()->whereKey('A')->update([
            'phase' => 'EW_GREEN',
            'step_started_at' => '2026-01-01 00:00:00',
            'desired_signals' => [
                'NORTH' => 'RED',
                'SOUTH' => 'RED',
                'EAST' => 'GREEN',
                'WEST' => 'GREEN',
            ],
        ]);

        $result = $this->handler->handlePayload($this->payload([
            'vehicle_type' => 'EMERGENCY',
            'vehicle_id' => 'amb-1',
        ]));

        $this->assertSame(SensorEventOutcome::ARRIVAL_APPLIED, $result->outcome);

        $junction = Junction::query()->findOrFail('A');
        $this->assertSame('EMERGENCY', $junction->mode);
        $this->assertSame('EW_YELLOW', $junction->phase);
        $this->assertSame('amb-1', $junction->emergency['active']['vehicle_id']);
        $this->assertEquals(['NORTH' => 'RED', 'SOUTH' => 'RED', 'EAST' => 'YELLOW', 'WEST' => 'YELLOW'], $junction->desired_signals);

        $this->assertSame(1, ControllerCommand::count());
        $command = ControllerCommand::query()->first();
        $this->assertEquals(['NORTH' => 'RED', 'SOUTH' => 'RED', 'EAST' => 'YELLOW', 'WEST' => 'YELLOW'], $command->desired_signals);
        $this->assertSame('DISPATCHED', $command->status);
        $this->assertSame('2026-01-01 00:00:00', $command->sent_at->format('Y-m-d H:i:s'));
        $this->assertSame($result->commandIds[0] ?? null, $command->command_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'event_id' => 'evt-1',
            'junction_id' => 'A',
            'direction' => 'NORTH',
            'vehicle_type' => 'TRUCK',
            'event_type' => 'VEHICLE_ARRIVED',
            'vehicle_id' => 'v-1',
            'sequence_no' => 1,
            'occurred_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }
}
