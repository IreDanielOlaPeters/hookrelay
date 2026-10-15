<?php

namespace App\Http\Requests;

use App\Enums\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSourceRequest extends FormRequest
{
    public function rules(): array
    {
        $source = $this->route('source');
        $required = $source ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'slug' => [$required, 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9_-]*$/i', Rule::unique('sources', 'slug')->ignore($source)],
            'provider' => ['sometimes', Rule::enum(Provider::class)],
            'signing_secret' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'dedupe_window_seconds' => ['sometimes', 'integer', 'min:0', 'max:31536000'],
            'rate_limit_rps' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
