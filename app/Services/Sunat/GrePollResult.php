<?php
namespace App\Services\Sunat;
final readonly class GrePollResult {
    public function __construct(public string $code, public ?string $cdrZip = null, public ?string $errorCode = null, public ?string $error = null) {}
    public function pending(): bool { return $this->code === '98'; }
}
