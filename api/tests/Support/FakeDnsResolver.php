<?php

namespace Tests\Support;

use App\Services\Security\DnsResolver;

/** Deterministic DNS for tests: unknown hosts resolve to a public documentation-safe address. */
class FakeDnsResolver extends DnsResolver
{
    /** @param  array<string, list<string>>  $records */
    public function __construct(public array $records = [], public ?string $default = '93.184.216.34') {}

    public function resolve(string $host): array
    {
        return $this->records[$host] ?? ($this->default ? [$this->default] : []);
    }
}
