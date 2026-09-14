<?php

namespace App\Services\Webhooks;

use App\Enums\DocumentIntegrationEvent;
use App\Models\Empresa;
use App\Models\McrApiClient;
use App\Models\McrWebhookSubscription;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

final class WebhookSubscriptionService
{
    public function __construct(private WebhookDestinationPolicy $destinations) {}

    public function create(int $clientId,int $companyId,string $url,array $types): array
    {
        $this->scope($clientId,$companyId); $types=$this->types($types); $this->destinations->validate($url);
        $secret=$this->secret();
        $subscription=McrWebhookSubscription::create(['McrApiClientID'=>$clientId,'McrCompanyConfigID'=>$companyId,
            'McrUrl'=>$url,'McrIsEnabled'=>true,'McrEncryptedSecret'=>Crypt::encryptString($secret),
            'McrEventTypes'=>json_encode($types,JSON_THROW_ON_ERROR),'McrCreatedAt'=>now(),'McrUpdatedAt'=>now()]);
        return [$subscription,$secret];
    }

    public function update(McrWebhookSubscription $subscription,array $data): McrWebhookSubscription
    {
        $values=[];
        if(array_key_exists('url',$data)){ $this->destinations->validate($data['url']); $values['McrUrl']=$data['url']; }
        if(array_key_exists('event_types',$data)) $values['McrEventTypes']=json_encode($this->types($data['event_types']),JSON_THROW_ON_ERROR);
        if(array_key_exists('enabled',$data)) $values['McrIsEnabled']=(bool)$data['enabled'];
        $subscription->update($values+['McrUpdatedAt'=>now()]); return $subscription->fresh();
    }

    public function rotate(McrWebhookSubscription $subscription): string
    {
        $secret=$this->secret(); $subscription->update(['McrEncryptedSecret'=>Crypt::encryptString($secret),'McrUpdatedAt'=>now()]); return $secret;
    }

    public function scope(int $clientId,int $companyId): void
    {
        if(!McrApiClient::whereKey($clientId)->where('McrIsActive',true)->where('SecStatus',true)->exists()
            || !Empresa::whereKey($companyId)->where('McrIsActive',true)->where('SecStatus',true)->exists())
            throw ValidationException::withMessages(['scope'=>'Active client and company are required.']);
    }

    private function types(array $types): array
    {
        $types=array_values(array_unique($types)); $known=array_column(DocumentIntegrationEvent::cases(),'value');
        if($types===[] || array_diff($types,$known)!==[]) throw ValidationException::withMessages(['event_types'=>'Unknown or empty event catalog selection.']);
        sort($types); return $types;
    }

    private function secret(): string { return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'='); }
}
