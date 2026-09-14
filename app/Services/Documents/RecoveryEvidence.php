<?php

namespace App\Services\Documents;

use App\Enums\DocumentState;
use App\Enums\FailureCategory;
use App\Enums\ProcessingCheckpoint;

final readonly class RecoveryEvidence
{
    public function __construct(
        public DocumentState $documentState,
        public string $transport,
        public ProcessingCheckpoint $checkpoint,
        public ?FailureCategory $failureCategory = null,
        public ?string $ticket = null,
        public bool $ambiguous = false,
        public bool $hasFiscalResult = false,
        public bool $artifactsMissing = false,
        public int $retryCount = 0,
        public int $pollCount = 0,
        public int $reconciliationCount = 0,
    ) {}
}
