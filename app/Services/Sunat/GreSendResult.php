<?php
namespace App\Services\Sunat;
final readonly class GreSendResult {
    public function __construct(public string $ticket, public ?string $receivedAt = null) {}
}
