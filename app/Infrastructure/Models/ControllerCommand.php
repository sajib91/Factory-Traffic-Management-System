<?php

namespace App\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class ControllerCommand extends Model
{
    protected $table = 'controller_commands';

    protected $primaryKey = 'command_id';

    public $incrementing = false;

    public $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'desired_signals' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'acked_at' => 'datetime',
        ];
    }
}
