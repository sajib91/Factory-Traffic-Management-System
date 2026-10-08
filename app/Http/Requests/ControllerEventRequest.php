<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

final class ControllerEventRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'junction_id' => ['required', 'string'],
            'event_type' => ['required', Rule::in(['ACK', 'ONLINE', 'OFFLINE'])],
            'command_id' => ['required_if:event_type,ACK', 'nullable', 'string'],
            'actual_signals' => ['nullable', 'array'],
            'actual_signals.*' => [Rule::in(['RED', 'YELLOW', 'GREEN', 'UNKNOWN'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
