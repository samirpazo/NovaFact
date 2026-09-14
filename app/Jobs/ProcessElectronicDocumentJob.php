<?php

namespace App\Jobs;

use App\Enums\DocumentState;
use App\Enums\FailureCategory;
use App\Enums\ProcessingCheckpoint;
use App\Exceptions\ClassifiedSubmissionException;
use App\Models\McrDocument;
use App\Services\Documents\DocumentLifecycle;
use App\Services\Documents\DocumentProcessorResolver;
use App\Services\Documents\PayloadCodec;
use App\Services\Documents\ProcessingResult;
use App\Services\Documents\RetryBackoffPolicy;
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
            $lifecycle->checkpoint($this->submissionId, ProcessingCheckpoint::PayloadLoaded);
            $result = $resolver->resolve($document->McrDocumentType)->process($document, $payload);
        } catch (ClassifiedSubmissionException $e) {
            Log::warning('document.processing.classified', $this->logContext() + ['operation' => 'submission', 'error_category' => $e->category->value]);
            $state = $e->ambiguous ? DocumentState::ReconciliationPending : ($e->retryable ? DocumentState::RetryPending : DocumentState::ManualReview);
            $result = new ProcessingResult($state, error: $e->ambiguous ? 'SUNAT outcome is ambiguous; reconciliation is required.' : 'Classified local processing failure.',
                failureCategory: $e->category, retryable: $e->retryable, ambiguous: $e->ambiguous);
        } catch (\Throwable $e) {
            // Never store arbitrary transport exception text (may contain credentials).
            Log::error('document.processing.failed', $this->logContext() + ['operation' => 'submission', 'error_category' => FailureCategory::Unknown->value, 'exception_type' => get_class($e)]);
            $checkpoint = McrDocument::find($this->documentId)?->McrProcessingResult;
            $result = $checkpoint ? ProcessingResult::fromArray(json_decode($checkpoint, true, flags: JSON_THROW_ON_ERROR))
                : $this->classifyLocalFailure($e);
        }
        $lifecycle->finish($this->documentId, $this->submissionId, $attemptId, $result, (int) ((hrtime(true) - $started) / 1_000_000));
        if ($result->state === DocumentState::AwaitingSunat && $result->ticket) {
            PollSunatSubmissionJob::dispatch($this->submissionId)->onConnection('documents')
                ->delay(now()->addSeconds(app(RetryBackoffPolicy::class)->polling(0)));
        } elseif ($result->state === DocumentState::ReconciliationPending) {
            $delay = app(RetryBackoffPolicy::class)->reconciliation(0);
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)
                ->update(['McrNextAttemptAt' => now()->addSeconds($delay), 'McrUpdatedAt' => now()]);
            ReconcileSunatSubmissionJob::dispatch($this->submissionId)->onConnection('documents')->delay(now()->addSeconds($delay));
        } elseif ($result->state === DocumentState::RetryPending) {
            $submission = DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->first();
            $delay = app(RetryBackoffPolicy::class)->processing((int) ($submission->McrRetryCount ?? 0));
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->update([
                'McrRetryCount' => DB::raw('"McrRetryCount" + 1'), 'McrNextAttemptAt' => now()->addSeconds($delay), 'McrUpdatedAt' => now(),
            ]);
            self::dispatch($this->documentId, $this->submissionId)->onConnection('documents')->delay(now()->addSeconds($delay));
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
                    : $this->interruptedResult();
                $lifecycle->finish($this->documentId, $this->submissionId, (int) $attemptId, $result, $this->timeout * 1000);
            } else {
                $lifecycle->transition($this->documentId, $this->submissionId, DocumentState::ManualReview);
                DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->update(['McrCompletedAt' => now(), 'McrFailureCategory' => FailureCategory::WorkerInterrupted->value, 'McrManualReviewReason' => 'worker_interrupted_before_attempt', 'McrError' => 'Worker could not start processing.']);
            }
        });
    }

    private function classifyLocalFailure(\Throwable $exception): ProcessingResult
    {
        $submission = DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->first();
        $checkpoint = ProcessingCheckpoint::tryFrom($submission->McrCheckpoint ?? '') ?? ProcessingCheckpoint::Admitted;
        if ($checkpoint->contactedRemote()) {
            return new ProcessingResult(DocumentState::ReconciliationPending, error: 'SUNAT outcome is ambiguous; reconciliation is required.',
                failureCategory: FailureCategory::AmbiguousSubmission, ambiguous: true);
        }
        if ($exception instanceof \LogicException || $exception instanceof \InvalidArgumentException) {
            return new ProcessingResult(DocumentState::ManualReview, error: 'Persisted document validation failed.',
                failureCategory: FailureCategory::Validation);
        }

        return new ProcessingResult(DocumentState::RetryPending, error: 'Recoverable local processing failure.',
            failureCategory: FailureCategory::LocalRetryable, retryable: true);
    }

    private function interruptedResult(): ProcessingResult
    {
        $submission = DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->first();
        $checkpoint = ProcessingCheckpoint::tryFrom($submission->McrCheckpoint ?? '') ?? ProcessingCheckpoint::Admitted;

        return $checkpoint->contactedRemote()
            ? new ProcessingResult(DocumentState::ReconciliationPending, error: 'Worker stopped after remote contact; reconciliation is required.', failureCategory: FailureCategory::WorkerInterrupted, ambiguous: true)
            : new ProcessingResult(DocumentState::RetryPending, error: 'Worker stopped before remote contact.', failureCategory: FailureCategory::WorkerInterrupted, retryable: true);
    }

    private function logContext(): array
    {
        $document = McrDocument::find($this->documentId);

        return ['document_id' => $this->documentId, 'submission_id' => $this->submissionId,
            'company_id' => $document?->McrCompanyConfigID, 'establishment_id' => $document?->McrEstablishmentID,
            'establishment_external_code' => $document?->McrEstablishmentSnapshot['external_code'] ?? null,
            'document_type' => $document?->McrDocumentType,
            'external_reference' => $document?->McrExternalReference,
            'attempt' => DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->value('McrAttemptNumber')];
    }
}
