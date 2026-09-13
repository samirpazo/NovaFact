<?php

namespace App\Jobs;

use App\Enums\DocumentState;
use App\Models\McrDocument;
use App\Services\Documents\DocumentLifecycle;
use App\Services\Documents\DocumentProcessorResolver;
use App\Services\Documents\PayloadCodec;
use App\Services\Documents\ProcessingResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessElectronicDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Automatic resend after an ambiguous transport failure is unsafe. Phase 5
    // introduces classified retry/reconciliation using this same document number.
    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $documentId, public int $submissionId) {}

    public function handle(DocumentProcessorResolver $resolver, DocumentLifecycle $lifecycle): void
    {
        $attemptId = $lifecycle->claim($this->documentId, $this->submissionId);
        if ($attemptId === null) {
            return;
        }
        $started = hrtime(true);
        try {
            $document = McrDocument::findOrFail($this->documentId);
            $stored = DB::table('McrDocumentPayload')->where('McrDocumentID', $this->documentId)->first();
            if (! $stored) {
                throw new \LogicException('Missing persisted payload.');
            }
            $payload = json_decode($stored->McrPayload, true, flags: JSON_THROW_ON_ERROR);
            // PostgreSQL jsonb may change key order/spacing; compare canonical data.
            if (! hash_equals($stored->McrPayloadHash, PayloadCodec::hash($payload))) {
                throw new \LogicException('Persisted payload integrity check failed.');
            }
            $result = $resolver->resolve($document->McrDocumentType)->process($document, $payload);
        } catch (\Throwable $e) {
            // Never store arbitrary transport exception text (may contain credentials).
            Log::error('document.processing.failed', ['document_id' => $this->documentId, 'submission_id' => $this->submissionId, 'exception_type' => get_class($e)]);
            $checkpoint = McrDocument::find($this->documentId)?->McrProcessingResult;
            $result = $checkpoint ? ProcessingResult::fromArray(json_decode($checkpoint, true, flags: JSON_THROW_ON_ERROR))
                : new ProcessingResult(DocumentState::Failed, error: 'Technical processing failure. Reconcile the original document before resending.');
        }
        $lifecycle->finish($this->documentId, $this->submissionId, $attemptId, $result, (int) ((hrtime(true) - $started) / 1_000_000));
        if ($result->state === DocumentState::AwaitingSunat && $result->ticket) {
            PollSunatSubmissionJob::dispatch($this->submissionId)->onConnection('documents')
                ->delay(now()->addSeconds(PollSunatSubmissionJob::BACKOFF[0]));
        }
    }

    public function failed(\Throwable $exception): void
    {
        DB::transaction(function (): void {
            $doc = McrDocument::lockForUpdate()->find($this->documentId);
            if (! $doc || ! in_array($doc->McrStatus, ['queued', 'processing', 'retry_pending'], true)) {
                return;
            }
            $lifecycle = app(DocumentLifecycle::class);
            $attemptId = DB::table('McrSunatAttempt')->where('McrSunatSubmissionID', $this->submissionId)->where('McrStatus', 'processing')->value('McrSunatAttemptID');
            if ($attemptId) {
                $result = $doc->McrProcessingResult
                    ? ProcessingResult::fromArray(json_decode($doc->McrProcessingResult, true, flags: JSON_THROW_ON_ERROR))
                    : new ProcessingResult(DocumentState::Failed, error: 'Worker interrupted; SUNAT outcome may be unknown. Reconciliation required.');
                $lifecycle->finish($this->documentId, $this->submissionId, (int) $attemptId, $result, $this->timeout * 1000);
            } else {
                $lifecycle->transition($this->documentId, $this->submissionId, DocumentState::Failed);
                DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->update(['McrCompletedAt' => now(), 'McrError' => 'Worker could not start processing.']);
            }
        });
    }
}
