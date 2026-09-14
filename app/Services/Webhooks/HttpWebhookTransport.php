<?php

namespace App\Services\Webhooks;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class HttpWebhookTransport implements WebhookTransport
{
    public function __construct(private WebhookDestinationPolicy $destinations) {}

    public function deliver(string $url,string $eventId,string $rawBody,string $secret): WebhookTransportResult
    {
        try {
            $destination=$this->destinations->validate($url); $timestamp=(string)time();
            $options=['allow_redirects'=>false];
            if(defined('CURLOPT_RESOLVE')) $options['curl']=[CURLOPT_RESOLVE=>array_map(
                fn($ip)=>$destination['host'].':'.$destination['port'].':'.$ip,$destination['ips'])];
            $response=Http::withOptions($options)->timeout((int)config('webhooks.timeout_seconds',8))
                ->withHeaders(['X-SunFacturation-Event-Id'=>$eventId,'X-SunFacturation-Timestamp'=>$timestamp,
                    'X-SunFacturation-Signature'=>WebhookSignature::sign($secret,$timestamp,$rawBody),
                    'User-Agent'=>config('webhooks.user_agent'),'Content-Type'=>'application/json'])
                ->withBody($rawBody,'application/json')->send('POST',$url);
            $status=$response->status(); $excerpt=$this->excerpt($response->body());
            if($status>=200&&$status<300) return new WebhookTransportResult(true,false,$status,null,$excerpt);
            if($status===429) return new WebhookTransportResult(false,true,$status,'rate_limited',$excerpt,$this->retryAfter($response->header('Retry-After')));
            if(in_array($status,[408,425],true)||$status>=500) return new WebhookTransportResult(false,true,$status,'consumer_transient',$excerpt);
            $category=match($status){401,403=>'consumer_authentication',404,410=>'endpoint_missing',400,422=>'payload_rejected',default=>'consumer_4xx'};
            return new WebhookTransportResult(false,false,$status,$category,$excerpt);
        } catch(ConnectionException) {
            return new WebhookTransportResult(false,true,null,'network_failure',null);
        } catch(\InvalidArgumentException) {
            return new WebhookTransportResult(false,false,null,'unsafe_destination',null);
        } catch(\Throwable) {
            return new WebhookTransportResult(false,true,null,'transport_failure',null);
        }
    }

    private function excerpt(string $body): ?string
    {
        $clean=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$body);
        $clean=preg_replace('/\b(authorization|access[_-]?token|token|secret|password)\s*[:=]\s*["\']?[^\s,"\'<>]+/iu','$1=[redacted]',$clean);
        return $clean===''?null:mb_substr(strip_tags($clean),0,(int)config('webhooks.max_response_excerpt',4096));
    }
    private function retryAfter(?string $value): ?int
    {
        if($value===null) return null;
        $seconds=ctype_digit(trim($value))?(int)trim($value):(($at=strtotime($value))===false?0:$at-time());
        return $seconds>0?min($seconds,21600):null;
    }
}
