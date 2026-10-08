<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

final class SensorEventRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:255'],
            'junction_id' => ['required', 'string'],
            'direction' => ['required', Rule::in(['NORTH', 'SOUTH', 'EAST', 'WEST'])],
            'vehicle_type' => ['required', Rule::in(['CAR', 'TRUCK', 'BUS', 'MOTORCYCLE', 'EMERGENCY'])],
            'event_type' => ['required', Rule::in(['VEHICLE_ARRIVED', 'VEHICLE_CLEARED'])],
            'vehicle_id' => ['required', 'string', 'max:255'],
            'sequence_no' => ['required', 'integer', 'min:1'],
            'occurred_at' => ['required', 'date'],
        ];
    }
}
