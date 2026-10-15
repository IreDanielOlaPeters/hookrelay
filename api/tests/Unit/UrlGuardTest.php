<?php

use App\Services\Security\BlockedDestination;
use App\Services\Security\UrlGuard;
use Tests\Support\FakeDnsResolver;

function guard(array $records = [], array $allowlist = [], ?string $default = '93.184.216.34'): UrlGuard
{
    return new UrlGuard(new FakeDnsResolver($records, $default), $allowlist);
}

it('blocks private, loopback, link-local, metadata and other internal addresses', function (string $ip) {
    expect(guard()->blockedReason($ip))->not->toBeNull();
})->with([
    '10.0.0.1', '10.255.255.255', '172.16.0.1', '172.31.255.254', '192.168.1.1', '127.0.0.1', '127.8.8.8',
    '169.254.169.254' /* AWS/GCP/Azure metadata */, '169.254.0.1', '100.100.100.200' /* Alibaba metadata */,
    '0.0.0.0', '224.0.0.1', '255.255.255.255', '198.18.0.1',
    '::1', '::', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'fd00:ec2::254' /* AWS IPv6 metadata */, 'ff02::1',
    '::ffff:127.0.0.1', '::ffff:169.254.169.254', '::ffff:10.0.0.1', '64:ff9b::a9fe:a9fe', '2002:7f00:1::1',
]);

it('allows public addresses', function (string $ip) {
    expect(guard()->blockedReason($ip))->toBeNull();
})->with(['93.184.216.34', '8.8.8.8', '1.1.1.1', '172.32.0.1', '172.15.255.255', '192.0.2.1', '2606:4700:4700::1111', '::ffff:8.8.8.8']);

it('rejects URLs whose host is a blocked IP literal', function (string $url) {
    expect(fn () => guard()->assertAllowed($url))->toThrow(BlockedDestination::class);
})->with([
    'http://127.0.0.1/hook', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]:8080/x',
    'http://[fd00:ec2::254]/', 'https://10.1.2.3/', 'http://[::ffff:7f00:1]/',
]);

it('rejects internal hostnames', function (string $url) {
    expect(fn () => guard()->assertAllowed($url))->toThrow(BlockedDestination::class, 'internal name');
})->with(['http://localhost/x', 'http://LOCALHOST:3000/x', 'http://api.localhost/x', 'http://metadata.google.internal/computeMetadata/v1/', 'http://metadata/']);

it('rejects hostnames that resolve to a blocked address, even if only one of several does', function () {
    $g = guard(['evil.example' => ['127.0.0.1'], 'mixed.example' => ['93.184.216.34', '10.0.0.5'], 'decimal' => ['127.0.0.1']]);

    expect(fn () => $g->assertAllowed('https://evil.example/hook'))->toThrow(BlockedDestination::class, 'evil.example resolves to 127.0.0.1')
        ->and(fn () => $g->assertAllowed('https://mixed.example/hook'))->toThrow(BlockedDestination::class, 'private')
        ->and(fn () => $g->resolveForDelivery('http://decimal/'))->toThrow(BlockedDestination::class);
});

it('rejects non-http schemes', function (string $url) {
    expect(fn () => guard()->assertAllowed($url))->toThrow(BlockedDestination::class, 'http(s)');
})->with(['file:///etc/passwd', 'gopher://example.com/', 'ftp://example.com/', '//example.com/hook']);

it('accepts unresolvable hosts on save but blocks them at delivery', function () {
    $g = guard([], [], null);

    $g->assertAllowed('https://not-yet-in-dns.example/hook');
    expect(fn () => $g->resolveForDelivery('https://not-yet-in-dns.example/hook'))->toThrow(BlockedDestination::class, 'Could not resolve');
});

it('returns the checked IP to pin at delivery time', function () {
    $g = guard(['hooks.example.com' => ['93.184.216.34', '93.184.216.35']]);

    expect($g->resolveForDelivery('https://hooks.example.com/x'))->toBe('93.184.216.34')
        ->and($g->resolveForDelivery('http://8.8.8.8/x'))->toBe('8.8.8.8');
});

it('honours the allowlist: hostnames, wildcards, IPs and CIDR ranges', function () {
    $g = guard(['svc.corp.example' => ['10.1.2.3'], 'other.example' => ['10.9.9.9']], ['mock-receiver', '*.corp.example', '10.1.0.0/16', '192.168.7.7']);

    $g->assertAllowed('http://mock-receiver:4000/ok');
    $g->assertAllowed('http://svc.corp.example/hook');
    $g->assertAllowed('http://10.1.200.4/hook');
    $g->assertAllowed('http://192.168.7.7/hook');

    expect($g->resolveForDelivery('http://mock-receiver:4000/ok'))->toBeNull()
        ->and(fn () => $g->assertAllowed('http://other.example/hook'))->toThrow(BlockedDestination::class)
        ->and(fn () => $g->assertAllowed('http://192.168.7.8/hook'))->toThrow(BlockedDestination::class)
        ->and(fn () => $g->assertAllowed('http://localhost/'))->toThrow(BlockedDestination::class);
});

it('formats cURL resolve pins for IPv4, IPv6 and explicit ports', function () {
    expect(UrlGuard::curlResolveEntry('https://hooks.example.com/x', '93.184.216.34'))->toBe('hooks.example.com:443:93.184.216.34')
        ->and(UrlGuard::curlResolveEntry('http://hooks.example.com:8080/x', '2606:4700::1'))->toBe('hooks.example.com:8080:[2606:4700::1]')
        ->and(UrlGuard::curlResolveEntry('http://hooks.example.com/x', '1.2.3.4'))->toBe('hooks.example.com:80:1.2.3.4');
});
