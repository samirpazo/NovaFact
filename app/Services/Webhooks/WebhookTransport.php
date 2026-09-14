<?php

namespace App\Services\Webhooks;

interface WebhookTransport
{
    public function deliver(string $url,string $eventId,string $rawBody,string $secret): WebhookTransportResult;
}
