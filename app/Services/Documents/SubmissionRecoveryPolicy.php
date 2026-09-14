<?php

namespace App\Services\Documents;

use App\Enums\DocumentState;
use App\Enums\FailureCategory;
use App\Enums\RecoveryAction;

final class SubmissionRecoveryPolicy
{
    public function decide(RecoveryEvidence $evidence): RecoveryAction
    {
        if ($evidence->hasFiscalResult || in_array($evidence->documentState, [
            DocumentState::Accepted, DocumentState::AcceptedWithObservations, DocumentState::Rejected,
        ], true)) {
            return $evidence->artifactsMissing && $evidence->documentState !== DocumentState::Rejected
                ? RecoveryAction::RegenerateArtifacts
                : RecoveryAction::MarkTerminal;
        }
        if ($evidence->ticket !== null) {
            return $evidence->pollCount >= RetryBackoffPolicy::MAX_POLLS
                ? RecoveryAction::ManualReview
                : RecoveryAction::PollTicket;
        }
        if ($evidence->ambiguous || $evidence->failureCategory === FailureCategory::AmbiguousSubmission
            || $evidence->checkpoint->contactedRemote()) {
            return $evidence->reconciliationCount >= RetryBackoffPolicy::MAX_RECONCILIATIONS
                ? RecoveryAction::ManualReview
                : RecoveryAction::Reconcile;
        }
        if ($evidence->failureCategory === FailureCategory::Validation
            || $evidence->failureCategory === FailureCategory::LocalNonRetryable) {
            return RecoveryAction::ManualReview;
        }
        if (in_array($evidence->failureCategory, [FailureCategory::LocalRetryable, FailureCategory::RemoteTransientSafe], true)) {
            return $evidence->retryCount >= RetryBackoffPolicy::MAX_PROCESSING_RETRIES
                ? RecoveryAction::ManualReview
                : RecoveryAction::RetrySubmission;
        }
        if (in_array($evidence->documentState, [DocumentState::Created, DocumentState::Queued, DocumentState::RetryPending], true)) {
            return RecoveryAction::Process;
        }
        if ($evidence->documentState === DocumentState::Processing && ! $evidence->checkpoint->contactedRemote()) {
            return $evidence->retryCount >= RetryBackoffPolicy::MAX_PROCESSING_RETRIES ? RecoveryAction::ManualReview : RecoveryAction::RetrySubmission;
        }

        return RecoveryAction::ManualReview;
    }
}
