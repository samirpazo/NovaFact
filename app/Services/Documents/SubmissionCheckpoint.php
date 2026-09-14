<?php

namespace App\Services\Documents;

use App\Enums\ProcessingCheckpoint;
use Illuminate\Support\Facades\DB;

final class SubmissionCheckpoint
{
    public function __construct(private DocumentLifecycle $lifecycle) {}

    public function forDocument(int $documentId, ProcessingCheckpoint $checkpoint, bool $remoteStarted = false): void
    {
        $submissionId = DB::table('McrSunatSubmission')->where('McrDocumentID', $documentId)
            ->where('McrStatus', 'processing')->orderByDesc('McrSunatSubmissionID')->value('McrSunatSubmissionID');
        if ($submissionId !== null) $this->lifecycle->checkpoint((int) $submissionId, $checkpoint, $remoteStarted);
    }
}
