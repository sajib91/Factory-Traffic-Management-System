<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

final class HistoryRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'type' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
