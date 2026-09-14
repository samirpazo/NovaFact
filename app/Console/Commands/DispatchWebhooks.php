<?php

namespace App\Console\Commands;

use App\Jobs\DeliverWebhookJob;
use App\Services\Webhooks\WebhookFanoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DispatchWebhooks extends Command
{
    protected $signature='webhooks:dispatch {--limit=100}';
    protected $description='Fan out pending outbox events and recover due webhook deliveries';
    public function handle(WebhookFanoutService $fanout): int
    {
        $limit=min(max((int)$this->option('limit'),1),1000); $events=0; $created=0;
        while($events<$limit){
            $result=DB::transaction(function()use($fanout){
                $query=DB::table('McrOutboxEvent')->whereNull('McrFannedOutAt')->orderBy('McrOccurredAt')->limit(1);
                DB::getDriverName()==='pgsql'?$query->lock('for update skip locked'):$query->lockForUpdate();
                $event=$query->first(); if(!$event)return null;
                $count=$fanout->fanout($event); DB::table('McrOutboxEvent')->where('McrOutboxEventID',$event->McrOutboxEventID)
                    ->update(['McrFannedOutAt'=>now(),'McrClaimedAt'=>null,'McrClaimToken'=>null]); return $count;
            });
            if($result===null)break; $events++; $created+=$result;
        }
        $due=DB::transaction(function()use($limit){
            $query=DB::table('McrWebhookDelivery')->whereIn('McrStatus',['pending','retrying'])
                ->where(fn($q)=>$q->whereNull('McrNextAttemptAt')->orWhere('McrNextAttemptAt','<=',now()))
                ->where(fn($q)=>$q->whereNull('McrClaimedAt')->orWhere('McrClaimedAt','<=',now()->subSeconds((int)config('webhooks.claim_lease_seconds',120))))
                ->orderBy('McrNextAttemptAt')->limit($limit);
            DB::getDriverName()==='pgsql'?$query->lock('for update skip locked'):$query->lockForUpdate();
            return $query->pluck('McrWebhookDeliveryID')->map(fn($id)=>(int)$id)->all();
        });
        foreach($due as $id)DeliverWebhookJob::dispatch($id)->onConnection('documents');
        $this->table(['metric','count'],[['events_fanned_out',$events],['deliveries_created',$created],['deliveries_queued',count($due)]]);
        return self::SUCCESS;
    }
}
