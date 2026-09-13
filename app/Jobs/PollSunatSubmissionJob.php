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

final class PollSunatSubmissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;
    public const BACKOFF = [5, 15, 30, 60];
    public function __construct(public int $submissionId) {}

    public function handle(GreTransport $transport, GreCdrParser $parser, DocumentLifecycle $lifecycle, ManagedFileService $files): void
    {
        $claim = DB::transaction(function () {
            $sub=DB::table('McrSunatSubmission')->where('McrSunatSubmissionID',$this->submissionId)->lockForUpdate()->first();
            if (!$sub || $sub->McrStatus!==DocumentState::AwaitingSunat->value || !$sub->McrTicket) return null;
            $number=(int)$sub->McrAttemptNumber+1;
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID',$this->submissionId)->update(['McrAttemptNumber'=>$number,'McrUpdatedAt'=>now()]);
            $id=DB::table('McrSunatAttempt')->insertGetId(['McrSunatSubmissionID'=>$this->submissionId,'McrAttemptNumber'=>$number,
                'McrTransport'=>'gre_poll','McrStatus'=>'processing','McrStartedAt'=>now()],'McrSunatAttemptID');
            return [(int)$sub->McrDocumentID,$sub->McrTicket,$number,(int)$id];
        });
        if (!$claim) return;
        [$documentId,$ticket,$attemptNo,$attemptId]=$claim; $started=hrtime(true);
        try {
            $doc=McrDocument::findOrFail($documentId); $company=Empresa::findOrFail($doc->McrCompanyConfigID);
            $status=$transport->poll($company,$ticket);
            if ($status->pending()) { $this->recordPending($attemptId,$attemptNo,'98',null,$started); return; }
            if ($status->cdrZip) {
                $cdr=base64_decode($status->cdrZip,true); if ($cdr===false) throw new \RuntimeException('Invalid GRE CDR encoding.');
                $result=$parser->parse($cdr); $result=new ProcessingResult($result->state,$result->code,$result->description,$result->notes,$result->error,$ticket);
                $name='R-'.$company->McrRuc.'-'.$doc->McrDocumentType.'-'.$doc->McrSeriesCode.'-'.$doc->McrCorrelative.'.zip';
                $path='facturacion/'.$company->getKey().'/'.$doc->McrDocumentType.'/'.$doc->McrSeriesCode.'/'.$doc->McrCorrelative.'/'.$name;
                Storage::disk('local')->put($path,$cdr); $fileId=$files->register($path,$name,'application/zip');
                $doc->update(['McrCdrPath'=>$path,'McrCdrFilID'=>$fileId]);
                $lifecycle->finish($documentId,$this->submissionId,$attemptId,$result,(int)((hrtime(true)-$started)/1_000_000));
                DB::table('McrSunatResponse')->where('McrSunatSubmissionID',$this->submissionId)->latest('McrSunatResponseID')->limit(1)->update(['McrCdrFilID'=>$fileId]);
                return;
            }
            if ($status->code==='99') {
                $result=new ProcessingResult(DocumentState::Rejected,$status->errorCode,$status->error ?: 'SUNAT rejected the GRE.',ticket:$ticket);
                $lifecycle->finish($documentId,$this->submissionId,$attemptId,$result,(int)((hrtime(true)-$started)/1_000_000)); return;
            }
            $this->recordPending($attemptId,$attemptNo,$status->code,'Ambiguous SUNAT GRE response; polling remains recoverable.',$started);
        } catch (\Throwable) {
            $this->recordPending($attemptId,$attemptNo,null,'Temporary GRE polling failure.',$started);
        }
    }

    private function recordPending(int $attemptId,int $attemptNo,?string $code,?string $error,int $started): void
    {
        DB::table('McrSunatAttempt')->where('McrSunatAttemptID',$attemptId)->update(['McrStatus'=>DocumentState::AwaitingSunat->value,
            'McrCompletedAt'=>now(),'McrDurationMs'=>(int)((hrtime(true)-$started)/1_000_000),'McrResponseCode'=>$code,'McrError'=>$error]);
        $pollIndex=$attemptNo-2;
        if ($pollIndex < count(self::BACKOFF)-1) {
            self::dispatch($this->submissionId)->onConnection('documents')->delay(now()->addSeconds(self::BACKOFF[$pollIndex+1]));
        } else {
            DB::table('McrSunatSubmission')->where('McrSunatSubmissionID',$this->submissionId)->update([
                'McrError'=>'GRE polling limit reached; SUNAT outcome remains pending and can be resumed safely.','McrUpdatedAt'=>now()]);
        }
    }
}
