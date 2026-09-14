<?php

namespace App\Services\Webhooks;

final class WebhookBackoffPolicy
{
    public const MAX_ATTEMPTS=7;
    private const DELAYS=[10,30,120,600,1800,7200,21600];
    public function delay(int $completed): int { return self::DELAYS[min(max($completed,0),count(self::DELAYS)-1)]; }
}
