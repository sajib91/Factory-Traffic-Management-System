<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class QueuedVehicle extends Model
{
    protected $table = 'queued_vehicles';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'arrived_at' => 'datetime',
        ];
    }
}
