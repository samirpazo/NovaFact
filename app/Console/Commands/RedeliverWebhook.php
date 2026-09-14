<?php

namespace App\Console\Commands;

use App\Jobs\DeliverWebhookJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RedeliverWebhook extends Command
{
    protected $signature='webhooks:redeliver {deliveryId}';
    protected $description='Rearm one dead-letter webhook delivery without creating a new event';
    public function handle(): int
    {
        $id=(int)$this->argument('deliveryId');
        $updated=DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID',$id)->where('McrStatus','dead_letter')->update([
            'McrStatus'=>'pending','McrCycleAttemptCount'=>0,'McrNextAttemptAt'=>now(),'McrClaimedAt'=>null,'McrClaimToken'=>null,
            'McrErrorCategory'=>null,'McrUpdatedAt'=>now()]);
        if($updated!==1){$this->error('Delivery is not in dead_letter.');return self::FAILURE;}
        DeliverWebhookJob::dispatch($id)->onConnection('documents'); $this->info('Delivery rearmed with its original event_id and payload.'); return self::SUCCESS;
    }
}
