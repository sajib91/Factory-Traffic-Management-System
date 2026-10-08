<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedEvent extends Model
{
    protected $table = 'processed_events';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }
}
