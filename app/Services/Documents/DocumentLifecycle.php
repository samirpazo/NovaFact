<?php

namespace App\Services\Documents;

use App\Enums\DocumentState;
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
            $this->transition($documentId, $submissionId, DocumentState::Processing);
            $submission = DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $submissionId)->first();
            $attempt = $submission->McrAttemptNumber + 1;
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $submissionId)->update([
                'McrAttemptNumber' => $attempt, 'McrSentAt' => now(), 'McrError' => null,
            ]);

            return (int) DB::table('McrSunatAttempt')->insertGetId([
                'McrSunatSubmissionID' => $submissionId, 'McrAttemptNumber' => $attempt,
                'McrTransport' => $submission->McrTransport, 'McrStatus' => DocumentState::Processing->value,
                'McrStartedAt' => now(),
            ], 'McrSunatAttemptID');
        });
    }

    public function finish(int $documentId, int $submissionId, int $attemptId, ProcessingResult $result, int $durationMs): void
    {
        DB::transaction(function () use ($documentId, $submissionId, $attemptId, $result, $durationMs): void {
            $this->transition($documentId, $submissionId, $result->state);
            McrDocument::whereKey($documentId)->update(['McrSunatCode' => $result->code, 'McrSunatDescription' => $result->description]);
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $submissionId)->update([
                'McrCompletedAt' => $result->state === DocumentState::AwaitingSunat ? null : now(), 'McrError' => $result->error,
                'McrTicket' => $result->ticket,
                'McrMetadata' => json_encode($result->toArray(), JSON_THROW_ON_ERROR),
            ]);
            if ($result->ticket) McrDocument::whereKey($documentId)->update(['McrSunatTicket' => $result->ticket]);
            DB::table('McrSunatAttempt')->where('McrSunatAttemptID', $attemptId)
                ->where('McrSunatSubmissionID', $submissionId)->update([
                    'McrStatus' => $result->state->value, 'McrCompletedAt' => now(), 'McrDurationMs' => $durationMs,
                    'McrResponseCode' => $result->code, 'McrError' => $result->error,
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
}
