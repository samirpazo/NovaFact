<?php

namespace App\Services\Webhooks;

final readonly class WebhookTransportResult
{
    public function __construct(public bool $delivered,public bool $retryable,public ?int $httpStatus,
        public ?string $errorCategory,public ?string $responseExcerpt,public ?int $retryAfterSeconds=null) {}
}
