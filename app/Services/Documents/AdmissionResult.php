<?php

namespace App\Services\Documents;

final readonly class AdmissionResult
{
    public function __construct(
        public int $documentId,
        public int $submissionId,
        public string $status,
        public ?string $seriesCode = null,
        public ?int $correlative = null,
        public ?string $documentNumber = null,
    ) {}

    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'submission_id' => $this->submissionId,
            'status' => $this->status,
            'series_code' => $this->seriesCode,
            'correlative' => $this->correlative,
            'document_number' => $this->documentNumber,
        ];
    }
}
