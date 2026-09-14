<?php

namespace App\Console\Commands;

use App\Jobs\DeliverWebhookJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReplayWebhookEvent extends Command
{
    protected $signature='webhooks:replay {eventId} {subscriptionId}';
    protected $description='Replay an existing immutable event to one in-scope subscription';
    public function handle(): int
    {
        $event=DB::table('McrOutboxEvent')->where('McrOutboxEventID',$this->argument('eventId'))->first();
        $subscription=DB::table('McrWebhookSubscription')->where('McrWebhookSubscriptionID',(int)$this->argument('subscriptionId'))->where('McrIsEnabled',true)->first();
        if(!$event||!$subscription||(int)$event->McrApiClientID!==(int)$subscription->McrApiClientID||(int)$event->McrCompanyConfigID!==(int)$subscription->McrCompanyConfigID){
            $this->error('Event and subscription do not share an enabled scope.');return self::FAILURE;
        }
        DB::table('McrWebhookDelivery')->insertOrIgnore(['McrOutboxEventID'=>$event->McrOutboxEventID,'McrWebhookSubscriptionID'=>$subscription->McrWebhookSubscriptionID,
            'McrStatus'=>'pending','McrNextAttemptAt'=>now(),'McrCreatedAt'=>now(),'McrUpdatedAt'=>now()]);
        $delivery=DB::table('McrWebhookDelivery')->where('McrOutboxEventID',$event->McrOutboxEventID)->where('McrWebhookSubscriptionID',$subscription->McrWebhookSubscriptionID)->first();
        DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID',$delivery->McrWebhookDeliveryID)->update(['McrStatus'=>'pending','McrCycleAttemptCount'=>0,
            'McrNextAttemptAt'=>now(),'McrClaimedAt'=>null,'McrClaimToken'=>null,'McrUpdatedAt'=>now()]);
        DeliverWebhookJob::dispatch((int)$delivery->McrWebhookDeliveryID)->onConnection('documents');
        $this->info('Existing event queued for replay.'); return self::SUCCESS;
    }
}
