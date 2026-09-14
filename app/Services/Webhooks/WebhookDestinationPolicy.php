<?php

namespace App\Services\Webhooks;

use InvalidArgumentException;

final class WebhookDestinationPolicy
{
    public function __construct(private DnsResolver $dns) {}

    public function validate(string $url): array
    {
        $parts=parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']))
            throw new InvalidArgumentException('Invalid webhook URL.');
        $unsafe=(bool)config('webhooks.allow_unsafe_local',false);
        if (strtolower($parts['scheme'])!=='https' && !($unsafe && strtolower($parts['scheme'])==='http'))
            throw new InvalidArgumentException('Webhook URL must use HTTPS.');
        $host=strtolower(rtrim($parts['host'],'.'));
        $ips=$this->dns->resolve($host);
        if ($ips===[]) throw new InvalidArgumentException('Webhook destination could not be resolved.');
        if (!$unsafe) foreach($ips as $ip) if (!$this->isPublic($ip)) throw new InvalidArgumentException('Webhook destination is not public.');
        return ['host'=>$host,'port'=>(int)($parts['port']??(strtolower($parts['scheme'])==='https'?443:80)),'ips'=>$ips];
    }

    private function isPublic(string $ip): bool
    {
        return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false
            && !in_array($ip,['169.254.169.254','100.100.100.200'],true);
    }
}
