<?php

return [
    'timeout_seconds'=>(int)env('WEBHOOK_TIMEOUT_SECONDS', 8),
    'allow_unsafe_local'=>(bool)env('WEBHOOK_ALLOW_UNSAFE_LOCAL', false),
    'max_response_excerpt'=>4096,
    'user_agent'=>'SunFacturation-Webhooks/1.0',
    'claim_lease_seconds'=>120,
];
