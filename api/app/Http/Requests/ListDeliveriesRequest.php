<?php

namespace App\Http\Requests;

use App\Enums\DeliveryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDeliveriesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::enum(DeliveryStatus::class)],
            'destination_id' => ['sometimes', 'nullable', 'uuid'],
            'event_id' => ['sometimes', 'nullable', 'uuid'],
            'has_error' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
