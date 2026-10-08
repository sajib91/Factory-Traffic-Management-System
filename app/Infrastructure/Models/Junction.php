<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class Junction extends Model
{
    protected $table = 'junctions';

    public $incrementing = false;

    public $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'step_started_at' => 'datetime',
            'mode_started_at' => 'datetime',
            'desired_signals' => 'array',
            'actual_signals' => 'array',
            'emergency' => 'array',
            'manual' => 'array',
            'last_sequences' => 'array',
            'version' => 'integer',
            'pending' => 'array',
            'device_status' => 'array',
            'device_warning' => 'boolean',
        ];
    }
}
