<?php

namespace App\Services\Security;

/**
 * SSRF guard for destination URLs.
 *
 * A destination may not point at private, loopback, link-local (incl. cloud metadata),
 * CGNAT, multicast/reserved or IPv6 unique-local addresses, either literally or via DNS.
 * Self-hosters can allow internal targets explicitly with RELAY_SSRF_ALLOWLIST
 * (hostnames, "*.suffix" wildcards and CIDR ranges), e.g. "mock-receiver,10.1.0.0/16".
 *
 * Checked when a destination is saved and again at delivery time; at delivery the request is
 * pinned to the checked IP so a DNS answer can't change between the check and the connect.
 */
class UrlGuard
{
    /** CIDR => reason */
    private const BLOCKED_V4 = [
        '0.0.0.0/8' => 'this-network',
        '10.0.0.0/8' => 'private',
        '100.64.0.0/10' => 'carrier-grade NAT',
        '127.0.0.0/8' => 'loopback',
        '169.254.0.0/16' => 'link-local / cloud metadata',
        '172.16.0.0/12' => 'private',
        '192.0.0.0/24' => 'IETF protocol assignments',
        '192.168.0.0/16' => 'private',
        '198.18.0.0/15' => 'benchmarking',
        '224.0.0.0/4' => 'multicast',
        '240.0.0.0/4' => 'reserved',
    ];

    private const BLOCKED_V6 = [
        '::/128' => 'unspecified',
        '::1/128' => 'loopback',
        'fc00::/7' => 'unique-local',
        'fe80::/10' => 'link-local',
        'ff00::/8' => 'multicast',
    ];

    private const BLOCKED_HOSTNAMES = ['localhost', 'metadata', 'metadata.google.internal', 'instance-data'];

    /** @param  list<string>  $allowlist */
    public function __construct(
        private readonly DnsResolver $resolver,
        private readonly array $allowlist = [],
    ) {}

    /**
     * Validate a URL for saving. Unresolvable hosts are accepted here (DNS may be temporarily
     * unavailable) because delivery re-checks; hosts that resolve to blocked addresses are not.
     *
     * @throws BlockedDestination
     */
    public function assertAllowed(string $url): void
    {
        $host = $this->host($url);
        if ($this->isAllowlistedHost($host)) {
            return;
        }
        $this->assertHostname($host);

        $ips = $this->literalIp($host) !== null ? [$this->literalIp($host)] : $this->resolver->resolve($host);
        foreach ($ips as $ip) {
            $this->assertIp($ip, $host);
        }
    }

    /**
     * Resolve and check a URL for delivery. Returns the IP to connect to, or null when the host
     * is allowlisted by name (then the system resolver is used as-is).
     *
     * @throws BlockedDestination
     */
    public function resolveForDelivery(string $url): ?string
    {
        $host = $this->host($url);
        if ($this->isAllowlistedHost($host)) {
            return null;
        }
        $this->assertHostname($host);

        if (($ip = $this->literalIp($host)) !== null) {
            $this->assertIp($ip, $host);

            return $ip;
        }

        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            throw new BlockedDestination("Could not resolve host {$host}.", permanent: false);
        }
        // Every address must be safe, otherwise a round-robin answer could still reach a blocked one.
        foreach ($ips as $ip) {
            $this->assertIp($ip, $host);
        }

        return $ips[0];
    }

    /** Why an IP is blocked, or null when it's a public address (or allowlisted). */
    public function blockedReason(string $ip): ?string
    {
        if ($this->ipAllowlisted($ip)) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::BLOCKED_V4 as $cidr => $reason) {
                if (self::inCidr($ip, $cidr)) {
                    return $reason;
                }
            }

            return null;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'not an IP address';
        }

        // IPv6 forms that embed an IPv4 address: judge the embedded address.
        $bin = inet_pton($ip);
        $embedded = match (true) {
            str_starts_with($bin, str_repeat("\0", 10)."\xff\xff") => substr($bin, 12, 4), // ::ffff:a.b.c.d
            str_starts_with($bin, "\x00\x64\xff\x9b".str_repeat("\0", 8)) => substr($bin, 12, 4), // 64:ff9b::/96 NAT64
            str_starts_with($bin, "\x20\x02") => substr($bin, 2, 4), // 2002::/16 6to4
            default => null,
        };
        if ($embedded !== null) {
            $reason = $this->blockedReason(inet_ntop($embedded));

            return $reason ? "{$reason} (IPv4 embedded in IPv6)" : null;
        }

        foreach (self::BLOCKED_V6 as $cidr => $reason) {
            if (self::inCidr($ip, $cidr)) {
                return $reason;
            }
        }

        return null;
    }

    private function host(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new BlockedDestination('Destination URL must be http(s) with a host.');
        }

        return strtolower(trim($parts['host'], '[]'));
    }

    private function literalIp(string $host): ?string
    {
        return filter_var($host, FILTER_VALIDATE_IP) ? $host : null;
    }

    private function assertHostname(string $host): void
    {
        $bare = rtrim($host, '.');
        if (in_array($bare, self::BLOCKED_HOSTNAMES, true) || str_ends_with($bare, '.localhost') || str_ends_with($bare, '.internal')) {
            throw new BlockedDestination("Host {$host} is not allowed (internal name). Add it to RELAY_SSRF_ALLOWLIST to permit it.");
        }
    }

    private function assertIp(string $ip, string $host): void
    {
        if (($reason = $this->blockedReason($ip)) !== null) {
            $via = $ip === $host ? '' : " ({$host} resolves to {$ip})";
            throw new BlockedDestination("Destination address is blocked: {$reason}{$via}. Add it to RELAY_SSRF_ALLOWLIST to permit it.");
        }
    }

    private function isAllowlistedHost(string $host): bool
    {
        foreach ($this->allowlist as $entry) {
            $entry = strtolower($entry);
            if ($entry === $host || (str_starts_with($entry, '*.') && str_ends_with($host, substr($entry, 1)))) {
                return true;
            }
        }

        return false;
    }

    private function ipAllowlisted(string $ip): bool
    {
        foreach ($this->allowlist as $entry) {
            if ((str_contains($entry, '/') && self::inCidr($ip, $entry)) || $entry === $ip) {
                return true;
            }
        }

        return false;
    }

    /** A CURLOPT_RESOLVE entry ("host:port:ip") pinning the URL's host to an already-checked IP. */
    public static function curlResolveEntry(string $url, string $ip): string
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? (strtolower($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);
        $host = trim($parts['host'] ?? '', '[]');

        return $host.':'.$port.':'.(str_contains($ip, ':') ? "[{$ip}]" : $ip);
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return ($ipBin[$bytes] & $mask) === ($subnetBin[$bytes] & $mask);
    }
}
