<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class WebhookStatus extends Command
{
    protected $signature='webhooks:status'; protected $description='Show durable outbox and webhook delivery counts';
    public function handle(): int
    {
        $rows=[['events_pending',DB::table('McrOutboxEvent')->whereNull('McrFannedOutAt')->count()]];
        foreach(['pending','retrying','delivered','dead_letter'] as $status)$rows[]=['deliveries_'.$status,DB::table('McrWebhookDelivery')->where('McrStatus',$status)->count()];
        $this->table(['metric','count'],$rows);return self::SUCCESS;
    }
}
