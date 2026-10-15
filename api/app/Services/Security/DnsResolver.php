<?php

namespace App\Services\Security;

/** Resolves a hostname to all of its IPv4 and IPv6 addresses. Swappable in tests. */
class DnsResolver
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        // gethostbynamel() honours /etc/hosts and Docker's embedded DNS; AAAA comes from DNS.
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
