<?php

namespace App\Services\Webhooks;

final class NativeDnsResolver implements DnsResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) return [$host];
        $records=dns_get_record($host, DNS_A|DNS_AAAA);
        return array_values(array_unique(array_filter(array_map(fn(array $r)=>$r['ip']??$r['ipv6']??null,$records ?: []))));
    }
}
