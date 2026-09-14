<?php

namespace App\Services\Documents;

use App\Enums\DocumentState;
use App\Enums\FailureCategory;
use App\Enums\ProcessingCheckpoint;
use App\Models\McrDocument;

final class RecoveryEvidenceFactory
{
    public function make(object $submission, McrDocument $document): RecoveryEvidence
    {
        $state = DocumentState::from($document->McrStatus);
        return new RecoveryEvidence(
            $state,
            $submission->McrTransport,
            ProcessingCheckpoint::tryFrom($submission->McrCheckpoint ?? '') ?? ProcessingCheckpoint::Admitted,
            FailureCategory::tryFrom($submission->McrFailureCategory ?? ''),
            $submission->McrTicket,
            (bool) ($submission->McrIsAmbiguous ?? false),
            $document->McrProcessingResult !== null,
            $document->McrArtifactError !== null || ($state !== DocumentState::Rejected && ! $document->McrPdfPath),
            (int) ($submission->McrRetryCount ?? 0),
            (int) ($submission->McrPollCount ?? 0),
            (int) ($submission->McrReconciliationCount ?? 0),
        );
    }
}
