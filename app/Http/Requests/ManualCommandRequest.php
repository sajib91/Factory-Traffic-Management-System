<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

final class ManualCommandRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'command' => ['required', Rule::in(['GREEN_NS', 'GREEN_EW', 'RETURN_TO_AUTOMATIC'])],
        ];
    }
}
