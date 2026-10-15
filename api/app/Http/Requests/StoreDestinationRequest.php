<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDestinationRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->route('destination') ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            // TODO(security): block private/link-local targets (SSRF) once the relay is multi-tenant.
            'url' => [$required, 'string', 'max:2048', 'url:http,https'],
            'auth_header' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'timeout_ms' => ['sometimes', 'integer', 'min:100', 'max:60000'],
            'rate_limit_rps' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'max_attempts' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! $this->user()?->isDemo() || ! $this->filled('url')) {
                return;
            }
            $allowed = config('relay.demo.allowed_destination_hosts', []);
            $host = strtolower((string) parse_url((string) $this->input('url'), PHP_URL_HOST));
            if (! in_array($host, $allowed, true)) {
                $validator->errors()->add('url', 'In demo mode, destinations must point at the mock receiver ('.implode(', ', $allowed).'), e.g. http://mock-receiver:4000/ok.');
            }
        }];
    }
}
