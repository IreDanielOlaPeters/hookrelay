<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListEventsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'source_id' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
