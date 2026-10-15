<?php

namespace App\Support;

/**
 * Production must not run with the development defaults from docker-compose.yml.
 */
class ProductionGuard
{
    public const DEFAULTS = [
        'APP_KEY' => ['', 'base64:Hr3B76dIUXyBys2e3c2r1nGACL3Fjlhu1DtO0l/nENk='],
        'ADMIN_TOKEN' => ['', 'dev-admin-token'],
        'RELAY_OUTBOUND_SECRET' => ['', 'dev-outbound-secret'],
    ];

    /** @return list<string> names of settings that still use a default (only in production) */
    public static function violations(string $environment, array $values): array
    {
        if ($environment !== 'production') {
            return [];
        }

        $bad = [];
        foreach (self::DEFAULTS as $name => $defaults) {
            if (in_array((string) ($values[$name] ?? ''), $defaults, true)) {
                $bad[] = $name;
            }
        }

        return $bad;
    }

    public static function current(): array
    {
        return self::violations((string) config('app.env'), [
            'APP_KEY' => config('app.key'),
            'ADMIN_TOKEN' => config('relay.admin_token'),
            'RELAY_OUTBOUND_SECRET' => config('relay.outbound_secret'),
        ]);
    }
}
