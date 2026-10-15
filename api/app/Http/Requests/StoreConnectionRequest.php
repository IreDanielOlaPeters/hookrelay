<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConnectionRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->route('connection')) {
            return ['enabled' => ['required', 'boolean']];
        }

        return [
            'source_id' => ['required', 'uuid', 'exists:sources,id'],
            'destination_id' => [
                'required', 'uuid', 'exists:destinations,id',
                Rule::unique('connections')->where('source_id', $this->input('source_id')),
            ],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['destination_id.unique' => 'This source is already connected to that destination.'];
    }
}
