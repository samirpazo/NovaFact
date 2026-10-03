<?php
// Local, isolated receiver. Accepts only the test subscription's HMAC.
$root = '/private/tmp/novafact-gre-storage';
$secretFile = $root.'/webhook-secret';
$body = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_SUNFACTURATION_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_SUNFACTURATION_SIGNATURE'] ?? '';
$secret = is_file($secretFile) ? trim(file_get_contents($secretFile)) : '';
$valid = $secret !== '' && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300
    && hash_equals('v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret), $signature);
if (! $valid) { http_response_code(401); exit; }
$event = json_decode($body, true);
if (! is_array($event)) { http_response_code(400); exit; }
file_put_contents($root.'/webhook-events.jsonl', json_encode(['event_id' => $_SERVER['HTTP_X_SUNFACTURATION_EVENT_ID'] ?? null,
    'event_type' => $event['event_type'] ?? null, 'document_id' => $event['data']['document_id'] ?? null,
    'state_version' => $event['state_version'] ?? null, 'hmac_valid' => true], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND|LOCK_EX);
http_response_code(204);
