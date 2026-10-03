<?php

namespace App\Http\Middleware;

use App\Models\ApiCredential;
use App\Models\Empresa;
use App\Models\McrApiClient;
use App\Services\Auth\ApiScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ValidateBillingToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! is_string($token) || $token === '') {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $credential = ApiCredential::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
        $client = $credential ? McrApiClient::whereKey($credential->client_id)->where('McrIsActive', true)->where('SecStatus', true)->first() : null;
        if (! $client) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        foreach (['X-Client-Code' => (string) $client->McrCode, 'X-Api-Client-Id' => (string) $client->getKey()] as $header => $expected) {
            abort_if($request->hasHeader($header) && $request->header($header) !== $expected, 403, 'Client scope is not authorized.');
        }
        $permission = $request->isMethod('GET') ? 'read'
            : ($request->is('api/*/configuracion/*', 'api/webhooks/*') ? 'admin' : 'emit');
        // Configuration reads also require administration; they expose operational metadata.
        if ($request->is('api/*/configuracion/*', 'api/webhooks/*')) {
            $permission = 'admin';
        }
        abort_unless(in_array($permission, $credential->permissions, true), 403, 'Permission denied.');
        $ids = DB::table('api_credential_companies')->where('credential_id', $credential->id)->pluck('company_id');
        $id = $request->header('X-Company-Id');
        if ($id === null && $ids->count() === 1) {
            $id = (string) $ids->first();
        }
        abort_unless(is_string($id) && ctype_digit($id) && (int) $id > 0, 422, 'X-Company-Id is required.');
        abort_unless($ids->contains((int) $id), 403, 'Company scope is not authorized.');
        $company = Empresa::whereKey((int) $id)->where('McrIsActive', true)->where('SecStatus', true)
            ->where('McrEnvironment', config('sunat.production') ? 'production' : 'beta')->firstOrFail();
        $request->attributes->set(ApiScope::class, new ApiScope($client, $company));

        return $next($request);
    }
}
