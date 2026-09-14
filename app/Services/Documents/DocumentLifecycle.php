<?php

namespace App\Services\Documents;

use App\Enums\DocumentState;
use App\Enums\ProcessingCheckpoint;
use App\Models\McrDocument;
use Illuminate\Support\Facades\DB;
use LogicException;

class DocumentLifecycle
{
    public function transition(int $documentId, int $submissionId, DocumentState $next): void
    {
        DB::transaction(function () use ($documentId, $submissionId, $next): void {
            $doc = McrDocument::lockForUpdate()->findOrFail($documentId);
            $submission = DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $submissionId)->lockForUpdate()->first();
            if (! $submission || (int) $submission->McrDocumentID !== $documentId) {
                throw new LogicException('Submission does not belong to document.');
            }
            $current = DocumentState::from($doc->McrStatus);
            if (! $current->canTransitionTo($next)) {
                throw new LogicException("Invalid document transition: {$current->value} -> {$next->value}");
            }
            $doc->update(['McrStatus' => $next->value, 'UpdateDate' => now()]);
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $submissionId)
                ->update(['McrStatus' => $next->value, 'McrUpdatedAt' => now()]);
        });
    }

    public function claim(int $documentId, int $submissionId): ?int
    {
        return DB::transaction(function () use ($documentId, $submissionId): ?int {
            $doc = McrDocument::lockForUpdate()->findOrFail($documentId);
            if (! in_array($doc->McrStatus, [DocumentState::Queued->value, DocumentState::RetryPending->value], true)) {
                return null;
            }
            $candidate = DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $submissionId)->lockForUpdate()->first();
            if (! $candidate || ($candidate->McrNextAttemptAt !== null && now()->isBefore($candidate->McrNextAttemptAt))) {
                return null;
            }
            $this->transition($documentId, $submissionId, DocumentState::Processing);
            $submission = DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $submissionId)->first();
            $attempt = $submission->McrAttemptNumber + 1;
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $submissionId)->update([
                'McrAttemptNumber' => $attempt, 'McrError' => null, 'McrNextAttemptAt' => null,
                'McrClaimedAt' => now(), 'McrClaimToken' => (string) \Illuminate\Support\Str::uuid(),
            ]);

            return (int) DB::table('McrSunatAttempt')->insertGetId([
                'McrSunatSubmissionID' => $submissionId, 'McrAttemptNumber' => $attempt,
                'McrTransport' => $submission->McrTransport, 'McrStatus' => DocumentState::Processing->value,
                'McrOperation' => 'submission', 'McrCheckpoint' => $submission->McrCheckpoint,
                'McrStartedAt' => now(),
            ], 'McrSunatAttemptID');
        });
    }

    public function finish(int $documentId, int $submissionId, int $attemptId, ProcessingResult $result, int $durationMs): void
    {
        DB::transaction(function () use ($documentId, $submissionId, $attemptId, $result, $durationMs): void {
            $this->transition($documentId, $submissionId, $result->state);
            $documentValues = ['McrSunatCode' => $result->code, 'McrSunatDescription' => $result->description];
            if (in_array($result->state, [DocumentState::Accepted, DocumentState::AcceptedWithObservations, DocumentState::Rejected], true)) {
                $documentValues['McrProcessingResult'] = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
            }
            McrDocument::whereKey($documentId)->update($documentValues);
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $submissionId)->update([
                'McrCompletedAt' => in_array($result->state, [DocumentState::AwaitingSunat, DocumentState::RetryPending, DocumentState::ReconciliationPending], true) ? null : now(), 'McrError' => $result->error,
                'McrTicket' => $result->ticket,
                'McrFailureCategory' => $result->failureCategory?->value,
                'McrIsAmbiguous' => $result->ambiguous,
                'McrClaimedAt' => null, 'McrClaimToken' => null,
                'McrMetadata' => json_encode($result->toArray(), JSON_THROW_ON_ERROR),
                'McrCheckpoint' => in_array($result->state, [DocumentState::Accepted, DocumentState::AcceptedWithObservations, DocumentState::Rejected], true)
                    ? ProcessingCheckpoint::Completed->value : DB::raw('"McrCheckpoint"'),
            ]);
            if ($result->ticket) McrDocument::whereKey($documentId)->update(['McrSunatTicket' => $result->ticket]);
            DB::table('McrSunatAttempt')->where('McrSunatAttemptID', $attemptId)
                ->where('McrSunatSubmissionID', $submissionId)->update([
                    'McrStatus' => $result->state->value, 'McrCompletedAt' => now(), 'McrDurationMs' => $durationMs,
                    'McrResponseCode' => $result->code, 'McrError' => $result->error,
                    'McrOutcome' => $result->failureCategory?->value ?? $result->state->value,
                    'McrRetryable' => $result->retryable, 'McrIsAmbiguous' => $result->ambiguous,
                    'McrTicket' => $result->ticket, 'McrErrorCategory' => $result->failureCategory?->value,
                    'McrResult' => json_encode($result->toArray(), JSON_THROW_ON_ERROR),
                ]);
            DB::table('McrSunatResponse')->insert([
                'McrSunatSubmissionID' => $submissionId, 'McrResponseCode' => $result->code,
                'McrDescription' => $result->description, 'McrNotes' => json_encode($result->notes, JSON_THROW_ON_ERROR),
                'McrRawResponse' => json_encode($result->toArray(), JSON_THROW_ON_ERROR),
                'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
            ]);
        });
    }

    public function checkpoint(int $submissionId, ProcessingCheckpoint $checkpoint, bool $remoteStarted = false): void
    {
        $values = [
            'McrCheckpoint' => $checkpoint->value,
            'McrUpdatedAt' => now(),
        ];
        if ($remoteStarted) $values['McrSentAt'] = now();
        DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $submissionId)->update($values);
        DB::table('McrSunatAttempt')->where('McrSunatSubmissionID', $submissionId)
            ->where('McrStatus', DocumentState::Processing->value)->update(['McrCheckpoint' => $checkpoint->value]);
    }
}
