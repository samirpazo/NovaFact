<?php

namespace App\Services\Documents;

final readonly class AdmissionContext
{
    public function __construct(
        public int $clientId,
        public int $companyId,
        public string $idempotencyKey,
        public ?string $externalReference = null,
    ) {}
}
