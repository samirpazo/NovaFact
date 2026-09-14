<?php

namespace App\Jobs;

use App\Enums\DocumentState;
use App\Models\Empresa;
use App\Models\McrDocument;
use App\Services\Documents\DocumentLifecycle;
use App\Services\Documents\ProcessingResult;
use App\Services\Facturacion\ManagedFileService;
use App\Services\Sunat\GreCdrParser;
use App\Services\Sunat\GreTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Enums\FailureCategory;
use App\Enums\ProcessingCheckpoint;
use App\Services\Documents\RetryBackoffPolicy;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

final class PollSunatSubmissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;
    public function __construct(public int $submissionId) {}

    public function handle(GreTransport $transport, GreCdrParser $parser, DocumentLifecycle $lifecycle, ManagedFileService $files): void
    {
        $claim = DB::transaction(function () {
            $sub=DB::table('McrSunatSubmission')->where('McrSunatSubmissionID',$this->submissionId)->lockForUpdate()->first();
            if (!$sub || $sub->McrStatus!==DocumentState::AwaitingSunat->value || !$sub->McrTicket) return null;
            if ($sub->McrClaimedAt !== null && now()->diffInSeconds($sub->McrClaimedAt, true) < 120) return null;
            if ($sub->McrNextAttemptAt !== null && now()->isBefore($sub->McrNextAttemptAt)) return null;
            if ((int)$sub->McrPollCount >= RetryBackoffPolicy::MAX_POLLS) {
                DB::table('McrSunatSubmission')->where('McrSunatSubmissionID',$this->submissionId)->update([
                    'McrStatus'=>DocumentState::ManualReview->value,'McrManualReviewReason'=>'gre_poll_limit',
                    'McrFailureCategory'=>FailureCategory::RemotePending->value,'McrCompletedAt'=>now(),'McrUpdatedAt'=>now()]);
                DB::table('McrDocument')->where('McrDocumentID',$sub->McrDocumentID)->update(['McrStatus'=>DocumentState::ManualReview->value,'UpdateDate'=>now()]);
                return null;
            }
            $number=(int)$sub->McrAttemptNumber+1;
            $token=(string)Str::uuid();
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID',$this->submissionId)->update(['McrAttemptNumber'=>$number,
                'McrPollCount'=>(int)$sub->McrPollCount+1,'McrClaimedAt'=>now(),'McrClaimToken'=>$token,'McrNextAttemptAt'=>null,'McrUpdatedAt'=>now()]);
            $id=DB::table('McrSunatAttempt')->insertGetId(['McrSunatSubmissionID'=>$this->submissionId,'McrAttemptNumber'=>$number,
                'McrTransport'=>'gre_poll','McrOperation'=>'poll_ticket','McrStatus'=>'processing','McrCheckpoint'=>ProcessingCheckpoint::SubmissionStarted->value,
                'McrTicket'=>$sub->McrTicket,'McrStartedAt'=>now()],'McrSunatAttemptID');
            return [(int)$sub->McrDocumentID,$sub->McrTicket,(int)$sub->McrPollCount,(int)$id,$token];
        });
        if (!$claim) return;
        [$documentId,$ticket,$pollCount,$attemptId,$claimToken]=$claim; $started=hrtime(true);
        try {
            $doc=McrDocument::findOrFail($documentId); $company=Empresa::findOrFail($doc->McrCompanyConfigID);
            $status=$transport->poll($company,$ticket);
            if ($status->pending()) { $this->recordPending($attemptId,$pollCount,'98',null,$started,$claimToken); return; }
            if ($status->cdrZip) {
                $cdr=base64_decode($status->cdrZip,true); if ($cdr===false) throw new \RuntimeException('Invalid GRE CDR encoding.');
                $result=$parser->parse($cdr); $result=new ProcessingResult($result->state,$result->code,$result->description,$result->notes,$result->error,$ticket);
                $name='R-'.$company->McrRuc.'-'.$doc->McrDocumentType.'-'.$doc->McrSeriesCode.'-'.$doc->McrCorrelative.'.zip';
                $path='facturacion/'.$company->getKey().'/'.$doc->McrDocumentType.'/'.$doc->McrSeriesCode.'/'.$doc->McrCorrelative.'/'.$name;
                Storage::disk('local')->put($path,$cdr); $fileId=$files->register($path,$name,'application/zip');
                $doc->update(['McrCdrPath'=>$path,'McrCdrFilID'=>$fileId]);
                $lifecycle->finish($documentId,$this->submissionId,$attemptId,$result,(int)((hrtime(true)-$started)/1_000_000));
                DB::table('McrSunatResponse')->where('McrSunatSubmissionID',$this->submissionId)->latest('McrSunatResponseID')->limit(1)->update(['McrCdrFilID'=>$fileId]);
                if (in_array($result->state,[DocumentState::Accepted,DocumentState::AcceptedWithObservations],true)) {
                    $doc->update(['McrArtifactError'=>'GRE PDF generation pending after fiscal outcome.']);
                    RecoverDocumentArtifactsJob::dispatch($documentId,$this->submissionId)->onConnection('documents');
                }
                return;
            }
            if ($status->code==='99') {
                $result=new ProcessingResult(DocumentState::Rejected,$status->errorCode,$status->error ?: 'SUNAT rejected the GRE.',ticket:$ticket);
                $lifecycle->finish($documentId,$this->submissionId,$attemptId,$result,(int)((hrtime(true)-$started)/1_000_000)); return;
            }
            $this->recordPending($attemptId,$pollCount,$status->code,'Unexpected GRE status; polling remains recoverable.',$started,$claimToken);
        } catch (\Throwable) {
            Log::warning('document.gre_poll.failed',['document_id'=>$documentId,'submission_id'=>$this->submissionId,
                'company_id'=>McrDocument::find($documentId)?->McrCompanyConfigID,'operation'=>'poll_ticket',
                'attempt'=>$pollCount + 1,'error_category'=>FailureCategory::RemotePending->value]);
            $this->recordPending($attemptId,$pollCount,null,'Temporary GRE polling failure.',$started,$claimToken);
        }
    }

    private function recordPending(int $attemptId,int $pollCount,?string $code,?string $error,int $started,string $claimToken): void
    {
        DB::table('McrSunatAttempt')->where('McrSunatAttemptID',$attemptId)->update(['McrStatus'=>DocumentState::AwaitingSunat->value,
            'McrCompletedAt'=>now(),'McrDurationMs'=>(int)((hrtime(true)-$started)/1_000_000),'McrResponseCode'=>$code,'McrError'=>$error]);
        if ($pollCount + 1 < RetryBackoffPolicy::MAX_POLLS) {
            $seconds=app(RetryBackoffPolicy::class)->polling($pollCount + 1); $due=now()->addSeconds($seconds);
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID',$this->submissionId)->where('McrClaimToken',$claimToken)->update([
                'McrClaimedAt'=>null,'McrClaimToken'=>null,'McrNextAttemptAt'=>$due,'McrFailureCategory'=>FailureCategory::RemotePending->value,'McrUpdatedAt'=>now()]);
            self::dispatch($this->submissionId)->onConnection('documents')->delay($due);
        } else {
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID',$this->submissionId)->update([
                'McrClaimedAt'=>null,'McrClaimToken'=>null,'McrNextAttemptAt'=>now(),
                'McrError'=>'GRE polling limit reached; SUNAT outcome remains pending.','McrUpdatedAt'=>now()]);
        }
    }
}
