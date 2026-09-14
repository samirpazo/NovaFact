<?php

namespace App\Jobs;

use App\Enums\FailureCategory;
use App\Models\McrDocument;
use App\Services\Documents\ArtifactRecoveryService;
use App\Services\Documents\PayloadCodec;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RecoverDocumentArtifactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;
    public function __construct(public int $documentId, public int $submissionId) {}

    public function handle(ArtifactRecoveryService $recovery): void
    {
        DB::transaction(function () use ($recovery): void {
            $submission = DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->lockForUpdate()->first();
            $document = McrDocument::lockForUpdate()->findOrFail($this->documentId);
            if (! $submission || (int) $submission->McrDocumentID !== $document->getKey() || ! $document->McrProcessingResult || ! $document->McrArtifactError) return;
            $stored = DB::table('McrDocumentPayload')->where('McrDocumentID', $document->getKey())->first();
            $payload = json_decode($stored->McrPayload, true, flags: JSON_THROW_ON_ERROR);
            if (! hash_equals($stored->McrPayloadHash, PayloadCodec::hash($payload))) throw new \LogicException('Persisted payload integrity check failed.');
            try {
                $recovery->recover($document, $payload);
            } catch (\Throwable $exception) {
                DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->update([
                    'McrFailureCategory' => FailureCategory::ArtifactFailure->value, 'McrManualReviewReason' => 'artifact_recovery_failed', 'McrUpdatedAt' => now(),
                ]);
                Log::error('document.artifact.recovery_failed', ['document_id' => $this->documentId, 'exception_type' => get_class($exception)]);
            }
        });
    }
}
