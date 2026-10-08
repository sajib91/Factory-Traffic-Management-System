<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

final class CreateJunctionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:64', 'unique:junctions,id'],
            'name' => ['required', 'string', 'max:255'],
            'config' => ['required', 'array'],
            'config.directions' => ['required', 'array', 'min:2'],
            'config.directions.*' => ['required', Rule::in(['NORTH', 'SOUTH', 'EAST', 'WEST'])],
            'config.phases' => ['required', 'array', 'min:1'],
            'config.phases.*.name' => ['required', Rule::in(['NORTH_SOUTH', 'EAST_WEST'])],
            'config.phases.*.green' => ['required', 'array', 'min:1'],
            'config.phases.*.green.*' => [Rule::in(['NORTH', 'SOUTH', 'EAST', 'WEST'])],
            'config.phases.*.duration' => ['required', 'integer', 'min:1'],
            'config.timings' => ['required', 'array'],
            'config.timings.min_green' => ['required', 'integer', 'min:0'],
            'config.timings.yellow' => ['required', 'integer', 'min:0'],
            'config.timings.all_red' => ['required', 'integer', 'min:0'],
        ];
    }
}
