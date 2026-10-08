<?php

namespace Database\Seeders;

use App\Infrastructure\Models\Junction;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Junction::query()->create([
            'id' => 'A',
            'name' => 'Main Gate Junction',
            'config' => [
                'directions' => ['NORTH', 'SOUTH', 'EAST', 'WEST'],
                'phases' => [
                    ['name' => 'NORTH_SOUTH', 'green' => ['NORTH', 'SOUTH'], 'duration' => 30],
                    ['name' => 'EAST_WEST', 'green' => ['EAST', 'WEST'], 'duration' => 30],
                ],
                'timings' => [
                    'min_green' => 10,
                    'yellow' => 5,
                    'all_red' => 2,
                ],
            ],
            'mode' => 'AUTOMATIC',
            'phase' => null,
            'step_started_at' => null,
            'mode_started_at' => null,
            'desired_signals' => [
                'NORTH' => 'RED',
                'SOUTH' => 'RED',
                'EAST' => 'RED',
                'WEST' => 'RED',
            ],
            'actual_signals' => [
                'NORTH' => 'RED',
                'SOUTH' => 'RED',
                'EAST' => 'RED',
                'WEST' => 'RED',
            ],
            'controller_status' => 'ONLINE',
            'last_sequences' => [
                'NORTH' => 0,
                'SOUTH' => 0,
                'EAST' => 0,
                'WEST' => 0,
            ],
        ]);
    }
}
