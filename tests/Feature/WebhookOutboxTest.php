<?php

use App\Enums\DocumentState;
use App\Jobs\DeliverWebhookJob;
use App\Models\McrDocument;
use App\Services\Documents\AdmitElectronicDocument;
use App\Services\Documents\DocumentLifecycle;
use App\Services\Documents\ProcessingResult;
use App\Services\Webhooks\DnsResolver;
use App\Services\Webhooks\WebhookBackoffPolicy;
use App\Services\Webhooks\WebhookDestinationPolicy;
use App\Services\Webhooks\WebhookSignature;
use App\Services\Webhooks\WebhookTransport;
use App\Services\Webhooks\WebhookTransportResult;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Support/PipelineDatabase.php';

beforeEach(function(){bootPipelineDatabase();Queue::fake();});
afterEach(function(){if(isset($this->pipelineSchema)){DB::statement('DROP SCHEMA "'.$this->pipelineSchema.'" CASCADE');DB::disconnect('pipeline_test');}});

function completedWebhookDocument(string $state='accepted',string $key='webhook-event'): array
{
    $op=app(AdmitElectronicDocument::class)->execute(pipelineContext($key,'ERP-'.$key),pipelinePayload())->toArray();
    $attempt=app(DocumentLifecycle::class)->claim($op['document_id'],$op['submission_id']);
    app(DocumentLifecycle::class)->finish($op['document_id'],$op['submission_id'],$attempt,new ProcessingResult(DocumentState::from($state),'0','SUNAT result'),1);
    return $op;
}
function webhookSubscription(array $types=['document.accepted'],?int $client=null,?int $company=null): int
{
    return (int)DB::table('McrWebhookSubscription')->insertGetId(['McrApiClientID'=>$client??DB::table('McrApiClient')->value('McrApiClientID'),
        'McrCompanyConfigID'=>$company??DB::table('McrCompanyConfig')->value('McrCompanyConfigID'),'McrUrl'=>'https://consumer.example.test/hook/'.bin2hex(random_bytes(3)),
        'McrIsEnabled'=>true,'McrEncryptedSecret'=>Crypt::encryptString('test-secret'),'McrEventTypes'=>json_encode($types),
        'McrCreatedAt'=>now(),'McrUpdatedAt'=>now()],'McrWebhookSubscriptionID');
}
function fanoutWebhookEvent(): array
{
    $op=completedWebhookDocument(); $event=DB::table('McrOutboxEvent')->first(); app(\App\Services\Webhooks\WebhookFanoutService::class)->fanout($event);
    return [$op,$event,DB::table('McrWebhookDelivery')->first()];
}

it('persists fiscal state and event in one transaction and rolls both back',function(){
    $op=app(AdmitElectronicDocument::class)->execute(pipelineContext('atomic-event'),pipelinePayload())->toArray();
    $attempt=app(DocumentLifecycle::class)->claim($op['document_id'],$op['submission_id']);
    try{DB::transaction(function()use($op,$attempt){app(DocumentLifecycle::class)->finish($op['document_id'],$op['submission_id'],$attempt,new ProcessingResult(DocumentState::Accepted,'0','Accepted'),1);throw new RuntimeException('rollback');});}catch(RuntimeException){}
    expect(McrDocument::find($op['document_id'])->McrStatus)->toBe('processing')->and(DB::table('McrOutboxEvent')->count())->toBe(0);
    app(DocumentLifecycle::class)->finish($op['document_id'],$op['submission_id'],$attempt,new ProcessingResult(DocumentState::Accepted,'0','Accepted'),1);
    expect(McrDocument::find($op['document_id'])->McrStatus)->toBe('accepted')->and(DB::table('McrOutboxEvent')->count())->toBe(1);
});

it('deduplicates a logical transition and keeps an immutable versioned envelope',function(){
    $op=completedWebhookDocument(); $event=DB::table('McrOutboxEvent')->first();
    $inserted=DB::table('McrOutboxEvent')->insertOrIgnore((array)$event);
    $payload=json_decode($event->McrPayloadBody,true);
    expect($inserted)->toBe(0)->and(DB::table('McrOutboxEvent')->count())->toBe(1)
        ->and($payload['event_id'])->toBe($event->McrOutboxEventID)->and($payload['event_version'])->toBe(1)
        ->and($payload['state_version'])->toBe(3)->and($payload['data']['document_id'])->toBe($op['document_id']);
});

it('fans one event to all matching scoped subscriptions only',function(){
    $client=(int)DB::table('McrApiClient')->value('McrApiClientID');$company=(int)DB::table('McrCompanyConfig')->value('McrCompanyConfigID');
    webhookSubscription();webhookSubscription();webhookSubscription();
    $otherClient=(int)DB::table('McrApiClient')->insertGetId(['McrCode'=>'other','McrName'=>'Other','McrIsActive'=>true,'SecStatus'=>true,'CreateDate'=>now()],'McrApiClientID');
    $otherCompany=(int)DB::table('McrCompanyConfig')->insertGetId(['McrRuc'=>'20987654321','McrBusinessName'=>'Other company','McrEnvironment'=>'beta',
        'McrIsActive'=>true,'SecStatus'=>true,'CreateDate'=>now()],'McrCompanyConfigID');
    webhookSubscription(['document.accepted'],$otherClient,$company);webhookSubscription(['document.accepted'],$client,$otherCompany);
    webhookSubscription(['document.rejected'],$client,$company);
    completedWebhookDocument();$event=DB::table('McrOutboxEvent')->first();
    expect(app(\App\Services\Webhooks\WebhookFanoutService::class)->fanout($event))->toBe(3)
        ->and(DB::table('McrWebhookDelivery')->count())->toBe(3);
});

it('signs exact bytes and detects body or timestamp tampering',function(){
    $body='{"event_id":"fixed","data":{"value":1}}';$sig=WebhookSignature::sign('secret','1726250000',$body);
    expect($sig)->toStartWith('v1=')->and(WebhookSignature::verify('secret','1726250000',$body,$sig))->toBeTrue()
        ->and(WebhookSignature::verify('secret','1726250000',$body.' ',$sig))->toBeFalse()
        ->and(WebhookSignature::verify('secret','1726250001',$body,$sig))->toBeFalse();
});

it('sends the exact signed body event id timestamp and identifiable user agent',function(){
    $dns=Mockery::mock(DnsResolver::class);$dns->shouldReceive('resolve')->once()->andReturn(['93.184.216.34']);$body='{"event_id":"evt-1","value":"á"}';
    Http::fake(function($request)use($body){$timestamp=$request->header('X-SunFacturation-Timestamp')[0];$signature=$request->header('X-SunFacturation-Signature')[0];
        expect($request->body())->toBe($body)->and($request->header('X-SunFacturation-Event-Id')[0])->toBe('evt-1')
            ->and(WebhookSignature::verify('secret',$timestamp,$request->body(),$signature))->toBeTrue()
            ->and($request->header('User-Agent')[0])->toBe('SunFacturation-Webhooks/1.0');return Http::response('',204);});
    expect((new \App\Services\Webhooks\HttpWebhookTransport(new WebhookDestinationPolicy($dns)))->deliver('https://example.com/hook','evt-1',$body,'secret')->delivered)->toBeTrue();
});

it('retries at least once with the same event and delivery then accepts 204',function(){
    webhookSubscription();[$op,$event,$delivery]=fanoutWebhookEvent();$transport=Mockery::mock(WebhookTransport::class);
    $ids=[];$transport->shouldReceive('deliver')->times(3)->andReturnUsing(function($url,$id)use(&$ids){$ids[]=$id;return count($ids)<3
        ?new WebhookTransportResult(false,true,500,'consumer_transient','error'):new WebhookTransportResult(true,false,204,null,null);});
    $job=new DeliverWebhookJob($delivery->McrWebhookDeliveryID);
    foreach(range(1,3)as$i){$job->handle($transport,app(WebhookBackoffPolicy::class));DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID',$delivery->McrWebhookDeliveryID)->update(['McrNextAttemptAt'=>now()->subSecond()]);}
    $row=DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID',$delivery->McrWebhookDeliveryID)->first();
    expect($ids)->each->toBe($event->McrOutboxEventID)->and($row->McrStatus)->toBe('delivered')->and((int)$row->McrAttemptCount)->toBe(3)
        ->and(DB::table('McrWebhookDeliveryAttempt')->count())->toBe(3)->and(McrDocument::find($op['document_id'])->McrStatus)->toBe('accepted');
});

it('respects bounded Retry-After and rejects redirects as non-success',function(){
    $dns=Mockery::mock(DnsResolver::class);$dns->shouldReceive('resolve')->andReturn(['93.184.216.34']);
    $transport=new \App\Services\Webhooks\HttpWebhookTransport(new WebhookDestinationPolicy($dns));
    Http::fakeSequence()->push('',429,['Retry-After'=>'3600'])->push('',302,['Location'=>'https://127.0.0.1']);
    $limited=$transport->deliver('https://example.com/hook','id','{}','secret');$redirect=$transport->deliver('https://example.com/hook','id','{}','secret');
    expect($limited->retryable)->toBeTrue()->and($limited->retryAfterSeconds)->toBe(3600)
        ->and($redirect->delivered)->toBeFalse()->and($redirect->retryable)->toBeFalse();
});

it('classifies permanent and transient HTTP responses explicitly',function(int $status,bool $retryable,string $category){
    $dns=Mockery::mock(DnsResolver::class);$dns->shouldReceive('resolve')->once()->andReturn(['93.184.216.34']);
    Http::fake(['*'=>Http::response('response',$status)]);$result=(new \App\Services\Webhooks\HttpWebhookTransport(new WebhookDestinationPolicy($dns)))
        ->deliver('https://example.com/hook','id','{}','secret');
    expect($result->retryable)->toBe($retryable)->and($result->errorCategory)->toBe($category);
})->with([[401,false,'consumer_authentication'],[403,false,'consumer_authentication'],[404,false,'endpoint_missing'],[410,false,'endpoint_missing'],
    [400,false,'payload_rejected'],[500,true,'consumer_transient'],[503,true,'consumer_transient']]);

it('moves permanent failures to dead letter and redelivers the same event',function(){
    webhookSubscription();[$op,$event,$delivery]=fanoutWebhookEvent();$transport=Mockery::mock(WebhookTransport::class);
    $transport->shouldReceive('deliver')->once()->andReturn(new WebhookTransportResult(false,false,401,'consumer_authentication',null));
    (new DeliverWebhookJob($delivery->McrWebhookDeliveryID))->handle($transport,app(WebhookBackoffPolicy::class));
    expect(DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID',$delivery->McrWebhookDeliveryID)->first()->McrStatus)->toBe('dead_letter')
        ->and(McrDocument::find($op['document_id'])->McrStatus)->toBe('accepted');
    $this->artisan('webhooks:redeliver '.$delivery->McrWebhookDeliveryID)->assertSuccessful();
    expect(DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID',$delivery->McrWebhookDeliveryID)->first()->McrOutboxEventID)->toBe($event->McrOutboxEventID)
        ->and(DB::table('McrWebhookDeliveryAttempt')->count())->toBe(1);
});

it('dead letters retryable failures after the bounded cycle without changing fiscal state',function(){
    webhookSubscription();[$op,$event,$delivery]=fanoutWebhookEvent();$transport=Mockery::mock(WebhookTransport::class);
    $seen=[];$transport->shouldReceive('deliver')->times(WebhookBackoffPolicy::MAX_ATTEMPTS)->andReturnUsing(function($url,$id)use(&$seen){$seen[]=$id;return new WebhookTransportResult(false,true,null,'network_failure',null);});
    $job=new DeliverWebhookJob($delivery->McrWebhookDeliveryID);
    foreach(range(1,WebhookBackoffPolicy::MAX_ATTEMPTS)as$_){$job->handle($transport,app(WebhookBackoffPolicy::class));DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID',$delivery->McrWebhookDeliveryID)->update(['McrNextAttemptAt'=>now()->subSecond()]);}
    $row=DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID',$delivery->McrWebhookDeliveryID)->first();
    expect($row->McrStatus)->toBe('dead_letter')->and((int)$row->McrAttemptCount)->toBe(WebhookBackoffPolicy::MAX_ATTEMPTS)
        ->and($seen)->each->toBe($event->McrOutboxEventID)->and(McrDocument::find($op['document_id'])->McrStatus)->toBe('accepted');
});

it('blocks unsafe destinations unless the explicit local flag is enabled',function(string $url,string $ip){
    $dns=Mockery::mock(DnsResolver::class);$dns->shouldReceive('resolve')->zeroOrMoreTimes()->andReturn([$ip]);$policy=new WebhookDestinationPolicy($dns);
    config(['webhooks.allow_unsafe_local'=>false]);expect(fn()=>$policy->validate($url))->toThrow(InvalidArgumentException::class);
})->with([['http://localhost/h','127.0.0.1'],['https://127.0.0.1/h','127.0.0.1'],['https://[::1]/h','::1'],['https://private.test/h','10.0.0.1'],
    ['https://private.test/h','172.16.0.1'],['https://private.test/h','192.168.1.1'],['https://metadata.test/h','169.254.169.254'],['file:///tmp/h','127.0.0.1']]);

it('allows an unsafe local destination only through explicit configuration',function(){
    $dns=Mockery::mock(DnsResolver::class);$dns->shouldReceive('resolve')->andReturn(['127.0.0.1']);config(['webhooks.allow_unsafe_local'=>true]);
    expect((new WebhookDestinationPolicy($dns))->validate('http://localhost:8080/h')['ips'])->toBe(['127.0.0.1']);
});

it('uses state_version so late delivery cannot hide event order',function(){
    $op=app(AdmitElectronicDocument::class)->execute(pipelineContext('ordered-event'),pipelinePayload())->toArray();
    app(DocumentLifecycle::class)->claim($op['document_id'],$op['submission_id']);
    app(DocumentLifecycle::class)->transition($op['document_id'],$op['submission_id'],DocumentState::AwaitingSunat);
    app(DocumentLifecycle::class)->transition($op['document_id'],$op['submission_id'],DocumentState::Accepted);
    $versions=DB::table('McrOutboxEvent')->orderBy('McrStateVersion')->pluck('McrStateVersion')->map(fn($v)=>(int)$v)->all();
    expect($versions)->toBe([3,4])->and(json_decode(DB::table('McrOutboxEvent')->orderByDesc('McrStateVersion')->value('McrPayloadBody'),true)['data']['status'])->toBe('accepted');
});

it('creates and rotates a secret once without exposing encrypted material in reads',function(){
    $dns=Mockery::mock(DnsResolver::class);$dns->shouldReceive('resolve')->once()->andReturn(['93.184.216.34']);app()->instance(DnsResolver::class,$dns);
    $headers=['X-Api-Client-Id'=>(string)DB::table('McrApiClient')->value('McrApiClientID'),'X-Company-Id'=>(string)DB::table('McrCompanyConfig')->value('McrCompanyConfigID')];
    $created=$this->withToken('test-token')->withHeaders($headers)->postJson('/api/webhooks/subscriptions',['url'=>'https://example.com/hook','event_types'=>['document.accepted']])->assertCreated();
    $id=$created->json('data.id');$secret=$created->json('secret');expect($secret)->toBeString()->not->toBe('');
    $this->withToken('test-token')->withHeaders($headers)->getJson('/api/webhooks/subscriptions/'.$id)->assertOk()->assertJsonMissingPath('secret')->assertJsonMissingPath('data.McrEncryptedSecret');
    $rotated=$this->withToken('test-token')->withHeaders($headers)->postJson('/api/webhooks/subscriptions/'.$id.'/rotate-secret')->assertOk()->json('secret');
    expect($rotated)->not->toBe($secret);
});

it('recovers a due delivery from PostgreSQL state when its delayed job is lost',function(){
    webhookSubscription();[,,$delivery]=fanoutWebhookEvent();Queue::fake();
    DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID',$delivery->McrWebhookDeliveryID)->update(['McrStatus'=>'retrying','McrNextAttemptAt'=>now()->subSecond()]);
    $this->artisan('webhooks:dispatch --limit=10')->assertSuccessful();
    Queue::assertPushed(DeliverWebhookJob::class,fn($job)=>$job->deliveryId===$delivery->McrWebhookDeliveryID);
});
