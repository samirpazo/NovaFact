<?php

namespace App\Jobs;

use App\Enums\DocumentState;
use App\Enums\FailureCategory;
use App\Enums\RecoveryAction;
use App\Models\Empresa;
use App\Models\McrDocument;
use App\Services\Documents\DocumentLifecycle;
use App\Services\Documents\ProcessingResult;
use App\Services\Documents\RecoveryEvidenceFactory;
use App\Services\Documents\RetryBackoffPolicy;
use App\Services\Documents\SubmissionRecoveryPolicy;
use App\Services\Sunat\SoapStatusConsultant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

final class ReconcileSunatSubmissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;

    public function __construct(public int $submissionId) {}

    public function handle(SubmissionRecoveryPolicy $policy, RecoveryEvidenceFactory $factory,
        RetryBackoffPolicy $backoff, SoapStatusConsultant $soap, DocumentLifecycle $lifecycle): void
    {
        $claim = DB::transaction(function () use ($policy, $factory) {
            $sub = DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->lockForUpdate()->first();
            if (! $sub) return null;
            if ($sub->McrClaimedAt !== null && now()->diffInSeconds($sub->McrClaimedAt, true) < 120) return null;
            if ($sub->McrNextAttemptAt !== null && now()->isBefore($sub->McrNextAttemptAt)) return null;
            $document = McrDocument::findOrFail($sub->McrDocumentID);
            $action = $policy->decide($factory->make($sub, $document));
            $token = (string) Str::uuid();
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->update([
                'McrClaimedAt' => now(), 'McrClaimToken' => $token, 'McrNextAttemptAt' => null, 'McrUpdatedAt' => now(),
            ]);
            return [$sub, $document, $action, $token];
        });
        if (! $claim) return;
        [$sub, $document, $action, $token] = $claim;
        Log::info('document.recovery.decision', ['document_id'=>$document->getKey(),'submission_id'=>$this->submissionId,
            'company_id'=>$document->McrCompanyConfigID,'document_type'=>$document->McrDocumentType,
            'external_reference'=>$document->McrExternalReference,'attempt'=>$sub->McrAttemptNumber,
            'operation'=>'reconcile','recovery_action'=>$action->value,'error_category'=>$sub->McrFailureCategory]);

        match ($action) {
            RecoveryAction::PollTicket => $this->poll($token),
            RecoveryAction::RegenerateArtifacts => $this->artifacts($document, $token),
            RecoveryAction::MarkTerminal, RecoveryAction::DoNothing => $this->releaseClaim($token),
            RecoveryAction::Process, RecoveryAction::RetrySubmission => $this->retryKnownSafe($document, $sub, $token, $backoff),
            RecoveryAction::Reconcile => $this->reconcile($document, $sub, $token, $soap, $lifecycle, $backoff),
            RecoveryAction::ManualReview => $this->manualReview($document, $token, 'automatic_recovery_limit'),
        };
    }

    private function poll(string $token): void
    {
        $this->releaseClaim($token);
        PollSunatSubmissionJob::dispatch($this->submissionId)->onConnection('documents');
    }

    private function artifacts(McrDocument $document, string $token): void
    {
        $this->releaseClaim($token);
        RecoverDocumentArtifactsJob::dispatch($document->getKey(), $this->submissionId)->onConnection('documents');
    }

    private function retryKnownSafe(McrDocument $document, object $sub, string $token, RetryBackoffPolicy $backoff): void
    {
        if (($sub->McrCheckpoint ?? 'admitted') === 'submission_started' || (bool) ($sub->McrIsAmbiguous ?? false)) {
            $this->manualReview($document, $token, 'unsafe_retry_blocked'); return;
        }
        $count = (int) ($sub->McrRetryCount ?? 0);
        if ($count >= RetryBackoffPolicy::MAX_PROCESSING_RETRIES) { $this->manualReview($document, $token, 'processing_retry_limit'); return; }
        $due = now()->addSeconds($backoff->processing($count));
        DB::transaction(function () use ($document, $token, $count, $due): void {
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->where('McrClaimToken', $token)->update([
                'McrRetryCount' => $count + 1, 'McrStatus' => DocumentState::RetryPending->value,
                'McrNextAttemptAt' => $due, 'McrClaimedAt' => null, 'McrClaimToken' => null, 'McrUpdatedAt' => now(),
            ]);
            $document->update(['McrStatus' => DocumentState::RetryPending->value, 'UpdateDate' => now()]);
        });
        ProcessElectronicDocumentJob::dispatch($document->getKey(), $this->submissionId)->onConnection('documents')->delay($due);
    }

    private function reconcile(McrDocument $document, object $sub, string $token, SoapStatusConsultant $soap,
        DocumentLifecycle $lifecycle, RetryBackoffPolicy $backoff): void
    {
        if ($sub->McrTransport === 'gre_rest') {
            if ($sub->McrTicket) { $this->poll($token); return; }
            $this->manualReview($document, $token, 'gre_ambiguous_without_ticket'); return;
        }
        $count = (int) ($sub->McrReconciliationCount ?? 0) + 1;
        try {
            $result = $soap->consult(Empresa::findOrFail($document->McrCompanyConfigID), $document->McrDocumentType,
                $document->McrSeriesCode, (int) $document->McrCorrelative);
            if ($result->getCdrResponse() !== null) {
                $processing = ProcessingResult::fromBillResult($result);
                if ($result->getCdrZip()) $this->persistRecoveredCdr($document, $result->getCdrZip());
                $attempt = $this->recordReconciliationAttempt($sub, $count, $processing, $token);
                $lifecycle->finish($document->getKey(), $this->submissionId, $attempt, $processing, 0);
                return;
            }
            $this->rescheduleOrReview($document, $token, $count, $backoff, 'soap_result_not_yet_verifiable');
        } catch (\DomainException) {
            $company = Empresa::find($document->McrCompanyConfigID);
            if ($company && $company->McrEnvironment !== 'production') {
                $count = (int) ($sub->McrRetryCount ?? 0);
                if ($count < RetryBackoffPolicy::MAX_PROCESSING_RETRIES) {
                    $due = now()->addSeconds($backoff->processing($count));
                    DB::transaction(function () use ($document, $sub, $token, $count, $due): void {
                        DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->where('McrClaimToken', $token)->update([
                            'McrRetryCount' => $count + 1, 'McrStatus' => DocumentState::RetryPending->value,
                            'McrNextAttemptAt' => $due, 'McrClaimedAt' => null, 'McrClaimToken' => null, 'McrUpdatedAt' => now(),
                        ]);
                        $document->update(['McrStatus' => DocumentState::RetryPending->value, 'UpdateDate' => now()]);
                    });
                    ProcessElectronicDocumentJob::dispatch($document->getKey(), $this->submissionId)->onConnection('documents')->delay($due);
                    return;
                }
            }
            $this->manualReview($document, $token, 'soap_consult_unavailable_environment');
        } catch (\Throwable $exception) {
            Log::warning('document.soap_reconciliation.failed', ['document_id'=>$document->getKey(),'submission_id'=>$this->submissionId,
                'company_id'=>$document->McrCompanyConfigID,'document_type'=>$document->McrDocumentType,'operation'=>'reconcile',
                'error_category'=>FailureCategory::AmbiguousSubmission->value,'exception_type'=>get_class($exception)]);
            $this->rescheduleOrReview($document, $token, $count, $backoff, 'soap_consult_transient_failure');
        }
    }

    private function recordReconciliationAttempt(object $sub, int $count, ProcessingResult $result, string $token): int
    {
        return DB::transaction(function () use ($sub, $count, $result, $token): int {
            $number = (int) $sub->McrAttemptNumber + 1;
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->where('McrClaimToken', $token)->update([
                'McrAttemptNumber' => $number, 'McrReconciliationCount' => $count, 'McrLastReconciledAt' => now(),
            ]);
            return (int) DB::table('McrSunatAttempt')->insertGetId([
                'McrSunatSubmissionID' => $this->submissionId, 'McrAttemptNumber' => $number, 'McrTransport' => 'soap_cdr',
                'McrOperation' => 'reconcile', 'McrStatus' => 'processing', 'McrStartedAt' => now(),
            ], 'McrSunatAttemptID');
        });
    }

    private function rescheduleOrReview(McrDocument $document, string $token, int $count, RetryBackoffPolicy $backoff, string $reason): void
    {
        if ($count >= RetryBackoffPolicy::MAX_RECONCILIATIONS) { $this->manualReview($document, $token, 'soap_reconciliation_limit'); return; }
        $due = now()->addSeconds($backoff->reconciliation($count));
        DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->where('McrClaimToken', $token)->update([
            'McrReconciliationCount' => $count, 'McrLastReconciledAt' => now(), 'McrNextAttemptAt' => $due,
            'McrFailureCategory' => FailureCategory::AmbiguousSubmission->value, 'McrError' => $reason,
            'McrClaimedAt' => null, 'McrClaimToken' => null, 'McrUpdatedAt' => now(),
        ]);
        self::dispatch($this->submissionId)->onConnection('documents')->delay($due);
    }

    private function manualReview(McrDocument $document, string $token, string $reason): void
    {
        DB::transaction(function () use ($document, $token, $reason): void {
            app(DocumentLifecycle::class)->transition($document->getKey(), $this->submissionId, DocumentState::ManualReview);
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->where('McrClaimToken', $token)->update([
                'McrStatus' => DocumentState::ManualReview->value, 'McrManualReviewReason' => $reason,
                'McrClaimedAt' => null, 'McrClaimToken' => null, 'McrNextAttemptAt' => null, 'McrCompletedAt' => now(), 'McrUpdatedAt' => now(),
            ]);
        });
    }

    private function releaseClaim(string $token): void
    {
        DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->where('McrClaimToken', $token)
            ->update(['McrClaimedAt' => null, 'McrClaimToken' => null, 'McrUpdatedAt' => now()]);
    }

    private function persistRecoveredCdr(McrDocument $document, string $bytes): void
    {
        $company=Empresa::findOrFail($document->McrCompanyConfigID);
        $name='R-'.$company->CpyRuc.'-'.$document->McrDocumentType.'-'.$document->McrSeriesCode.'-'.$document->McrCorrelative.'.zip';
        $path='facturacion/'.$company->getKey().'/'.$document->McrDocumentType.'/'.$document->McrSeriesCode.'/'.$document->McrCorrelative.'/'.$name;
        \Illuminate\Support\Facades\Storage::disk('local')->put($path,$bytes);
        $document->update(['McrCdrPath'=>$path]);
    }
}
