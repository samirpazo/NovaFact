<?php

namespace App\Services\Webhooks;

interface DnsResolver
{
    public function resolve(string $host): array;
}
