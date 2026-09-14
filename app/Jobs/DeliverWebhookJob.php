<?php

namespace App\Jobs;

use App\Services\Webhooks\WebhookBackoffPolicy;
use App\Services\Webhooks\WebhookTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries=1;
    public function __construct(public int $deliveryId) {}

    public function handle(WebhookTransport $transport,WebhookBackoffPolicy $backoff): void
    {
        $claim=DB::transaction(function(){
            $delivery=DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID',$this->deliveryId)->lockForUpdate()->first();
            if(!$delivery||!in_array($delivery->McrStatus,['pending','retrying'],true)) return null;
            if($delivery->McrNextAttemptAt!==null&&now()->isBefore($delivery->McrNextAttemptAt)) return null;
            if($delivery->McrClaimedAt!==null&&now()->diffInSeconds($delivery->McrClaimedAt,true)<(int)config('webhooks.claim_lease_seconds',120)) return null;
            $subscription=DB::table('McrWebhookSubscription')->where('McrWebhookSubscriptionID',$delivery->McrWebhookSubscriptionID)->first();
            $event=DB::table('McrOutboxEvent')->where('McrOutboxEventID',$delivery->McrOutboxEventID)->first();
            if(!$subscription||!$subscription->McrIsEnabled||!$event){
                DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID',$this->deliveryId)->update(['McrStatus'=>'dead_letter','McrErrorCategory'=>'subscription_unavailable','McrUpdatedAt'=>now()]); return null;
            }
            $token=(string)Str::uuid(); $attempt=(int)$delivery->McrAttemptCount+1; $cycle=(int)$delivery->McrCycleAttemptCount+1;
            DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID',$this->deliveryId)->update([
                'McrAttemptCount'=>$attempt,'McrCycleAttemptCount'=>$cycle,'McrClaimedAt'=>now(),'McrClaimToken'=>$token,
                'McrLastAttemptAt'=>now(),'McrNextAttemptAt'=>null,'McrUpdatedAt'=>now()]);
            return [$delivery,$subscription,$event,$token,$attempt,$cycle,now()];
        });
        if(!$claim) return;
        [$delivery,$subscription,$event,$token,$attempt,$cycle,$started]=$claim;
        try{$secret=Crypt::decryptString($subscription->McrEncryptedSecret);
            $result=$transport->deliver($subscription->McrUrl,$event->McrOutboxEventID,$event->McrPayloadBody,$secret);
        }catch(\Throwable){$result=new \App\Services\Webhooks\WebhookTransportResult(false,false,null,'secret_decryption_failed',null);}
        DB::transaction(function()use($result,$token,$attempt,$cycle,$started,$backoff,$event,$subscription){
            $current=DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID',$this->deliveryId)->where('McrClaimToken',$token)->lockForUpdate()->first();
            if(!$current) return;
            $status=$result->delivered?'delivered':(($result->retryable&&$cycle<WebhookBackoffPolicy::MAX_ATTEMPTS)?'retrying':'dead_letter');
            $delay=$result->retryAfterSeconds??$backoff->delay($cycle-1); $due=$status==='retrying'?now()->addSeconds($delay):null;
            DB::table('McrWebhookDeliveryAttempt')->insert(['McrWebhookDeliveryID'=>$this->deliveryId,'McrAttemptNumber'=>$attempt,
                'McrStartedAt'=>$started,'McrCompletedAt'=>now(),'McrHttpStatus'=>$result->httpStatus,'McrOutcome'=>$status,
                'McrErrorCategory'=>$result->errorCategory,'McrResponseExcerpt'=>$result->responseExcerpt]);
            DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID',$this->deliveryId)->update(['McrStatus'=>$status,
                'McrNextAttemptAt'=>$due,'McrDeliveredAt'=>$result->delivered?now():null,'McrHttpStatus'=>$result->httpStatus,
                'McrErrorCategory'=>$result->errorCategory,'McrResponseExcerpt'=>$result->responseExcerpt,
                'McrClaimedAt'=>null,'McrClaimToken'=>null,'McrUpdatedAt'=>now()]);
            Log::info('webhook.delivery.completed',['event_id'=>$event->McrOutboxEventID,'delivery_id'=>$this->deliveryId,
                'subscription_id'=>$subscription->McrWebhookSubscriptionID,'client_id'=>$event->McrApiClientID,
                'company_id'=>$event->McrCompanyConfigID,'document_id'=>$event->McrDocumentID,'event_type'=>$event->McrEventType,
                'attempt'=>$attempt,'http_status'=>$result->httpStatus,'outcome'=>$status]);
            if($status==='retrying') self::dispatch($this->deliveryId)->onConnection('documents')->delay($due);
        });
    }
}
