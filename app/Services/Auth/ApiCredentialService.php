<?php

namespace App\Services\Auth;

use App\Models\ApiCredential;
use App\Models\Empresa;
use App\Models\McrApiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApiCredentialService
{
    public function issue(int $clientId, array $companyIds, array $permissions): array
    {
        $companyIds = array_values(array_unique(array_map('intval', $companyIds)));
        $permissions = array_values(array_unique($permissions));
        if ($companyIds === [] || $permissions === [] || array_diff($permissions, ['read', 'emit', 'admin']) !== []
            || ! McrApiClient::whereKey($clientId)->where('McrIsActive', true)->where('SecStatus', true)->exists()
            || Empresa::whereIn('McrCompanyConfigID', $companyIds)->where('McrIsActive', true)->where('SecStatus', true)->count() !== count($companyIds)) {
            throw ValidationException::withMessages(['credential' => 'Active client, companies and valid permissions are required.']);
        }
        $token = 'nf_'.bin2hex(random_bytes(32));
        $credential = DB::transaction(function () use ($clientId, $companyIds, $permissions, $token) {
            $credential = ApiCredential::create(['client_id' => $clientId, 'token_hash' => hash('sha256', $token),
                'permissions' => $permissions, 'created_at' => now()]);
            DB::table('api_credential_companies')->insert(array_map(fn ($id) => ['credential_id' => $credential->id, 'company_id' => $id], $companyIds));

            return $credential;
        });

        return ['credential' => $credential, 'token' => $token];
    }
}
