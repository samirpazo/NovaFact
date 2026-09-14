<?php

namespace App\Services\Webhooks;

use Illuminate\Support\Facades\DB;

final class WebhookFanoutService
{
    public function fanout(object $event): int
    {
        $subscriptions=DB::table('McrWebhookSubscription')->where('McrApiClientID',$event->McrApiClientID)
            ->where('McrCompanyConfigID',$event->McrCompanyConfigID)->where('McrIsEnabled',true)->get();
        $created=0;
        foreach($subscriptions as $subscription){
            $types=is_array($subscription->McrEventTypes)
                ? $subscription->McrEventTypes
                : json_decode($subscription->McrEventTypes,true,flags:JSON_THROW_ON_ERROR);
            if(!in_array($event->McrEventType,$types,true)) continue;
            $created+=DB::table('McrWebhookDelivery')->insertOrIgnore(['McrOutboxEventID'=>$event->McrOutboxEventID,
                'McrWebhookSubscriptionID'=>$subscription->McrWebhookSubscriptionID,'McrStatus'=>'pending','McrNextAttemptAt'=>now(),
                'McrCreatedAt'=>now(),'McrUpdatedAt'=>now()]);
        }
        return $created;
    }
}
