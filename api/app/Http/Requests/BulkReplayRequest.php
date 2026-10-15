<?php

namespace App\Http\Requests;

use App\Enums\DeliveryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkReplayRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in([
                DeliveryStatus::Dead->value, DeliveryStatus::Failed->value, DeliveryStatus::Succeeded->value,
            ])],
            'destination_id' => ['sometimes', 'nullable', 'uuid'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
