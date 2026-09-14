<?php

namespace App\Services\Webhooks;

final class WebhookSignature
{
    public static function sign(string $secret,string $timestamp,string $rawBody): string
    {
        return 'v1='.hash_hmac('sha256',$timestamp.'.'.$rawBody,$secret);
    }
    public static function verify(string $secret,string $timestamp,string $rawBody,string $signature): bool
    {
        return hash_equals(self::sign($secret,$timestamp,$rawBody),$signature);
    }
}
