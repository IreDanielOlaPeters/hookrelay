<?php

use App\Support\ProductionGuard;

it('sends security headers on API responses, and HSTS only over HTTPS', function () {
    $http = $this->getJson('/api/sources', adminHeaders());
    $http->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy');
    expect($http->headers->has('Strict-Transport-Security'))->toBeFalse();

    $this->getJson('https://localhost/api/sources', adminHeaders())
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('flags development-default secrets only in production', function () {
    $defaults = [
        'APP_KEY' => 'base64:Hr3B76dIUXyBys2e3c2r1nGACL3Fjlhu1DtO0l/nENk=',
        'ADMIN_TOKEN' => 'dev-admin-token',
        'RELAY_OUTBOUND_SECRET' => 'dev-outbound-secret',
    ];

    expect(ProductionGuard::violations('local', $defaults))->toBe([])
        ->and(ProductionGuard::violations('production', $defaults))->toBe(['APP_KEY', 'ADMIN_TOKEN', 'RELAY_OUTBOUND_SECRET'])
        ->and(ProductionGuard::violations('production', ['APP_KEY' => 'base64:real', 'ADMIN_TOKEN' => '', 'RELAY_OUTBOUND_SECRET' => 'real']))->toBe(['ADMIN_TOKEN'])
        ->and(ProductionGuard::violations('production', ['APP_KEY' => 'base64:real', 'ADMIN_TOKEN' => 'real', 'RELAY_OUTBOUND_SECRET' => 'real']))->toBe([]);
});

it('makes relay:preflight fail in production with default secrets', function () {
    $this->artisan('relay:preflight')->assertSuccessful(); // testing env

    config(['app.env' => 'production', 'relay.admin_token' => 'dev-admin-token']);
    $this->artisan('relay:preflight')->expectsOutputToContain('ADMIN_TOKEN')->assertFailed();

    config(['relay.admin_token' => 'a-real-token', 'relay.outbound_secret' => 'a-real-secret', 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $this->artisan('relay:preflight')->assertSuccessful();
});
