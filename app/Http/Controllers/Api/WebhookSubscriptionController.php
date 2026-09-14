<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\McrWebhookSubscription;
use App\Services\Webhooks\WebhookSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebhookSubscriptionController extends Controller
{
    public function __construct(private WebhookSubscriptionService $service) {}

    public function index(Request $request): JsonResponse
    {
        [$client,$company]=$this->scope($request);
        return response()->json(['data'=>McrWebhookSubscription::where('McrApiClientID',$client)->where('McrCompanyConfigID',$company)->get()->map(fn($s)=>$this->view($s))]);
    }
    public function store(Request $request): JsonResponse
    {
        [$client,$company]=$this->scope($request); $data=$request->validate(['url'=>['required','string','max:2048'],'event_types'=>['required','array','min:1'],'event_types.*'=>['string']]);
        [$subscription,$secret]=$this->service->create($client,$company,$data['url'],$data['event_types']);
        return response()->json(['data'=>$this->view($subscription),'secret'=>$secret],201);
    }
    public function show(Request $request,int $id): JsonResponse { return response()->json(['data'=>$this->view($this->owned($request,$id))]); }
    public function update(Request $request,int $id): JsonResponse
    {
        $data=$request->validate(['url'=>['sometimes','string','max:2048'],'event_types'=>['sometimes','array','min:1'],'event_types.*'=>['string'],'enabled'=>['sometimes','boolean']]);
        return response()->json(['data'=>$this->view($this->service->update($this->owned($request,$id),$data))]);
    }
    public function destroy(Request $request,int $id): JsonResponse { $s=$this->owned($request,$id); $s->update(['McrIsEnabled'=>false,'McrUpdatedAt'=>now()]); return response()->json([],204); }
    public function rotate(Request $request,int $id): JsonResponse { $s=$this->owned($request,$id); return response()->json(['data'=>$this->view($s->fresh()),'secret'=>$this->service->rotate($s)]); }

    private function scope(Request $request): array
    {
        $client=$request->header('X-Api-Client-Id'); $company=$request->header('X-Company-Id');
        abort_unless(is_string($client)&&ctype_digit($client)&&is_string($company)&&ctype_digit($company),422,'Explicit client and company scope is required.');
        $this->service->scope((int)$client,(int)$company); return [(int)$client,(int)$company];
    }
    private function owned(Request $request,int $id): McrWebhookSubscription
    {
        [$client,$company]=$this->scope($request); return McrWebhookSubscription::whereKey($id)->where('McrApiClientID',$client)->where('McrCompanyConfigID',$company)->firstOrFail();
    }
    private function view(McrWebhookSubscription $s): array
    {
        return ['id'=>$s->getKey(),'client_id'=>(int)$s->McrApiClientID,'company_id'=>(int)$s->McrCompanyConfigID,
            'url'=>$s->McrUrl,'enabled'=>(bool)$s->McrIsEnabled,'event_types'=>$s->McrEventTypes,
            'created_at'=>$s->McrCreatedAt?->toIso8601String(),'updated_at'=>$s->McrUpdatedAt?->toIso8601String()];
    }
}
