<?php

namespace App\Services\Documents;

use App\Models\Empresa;
use App\Models\McrApiClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class LegacyAdmissionContextResolver
{
    public function resolve(Request $request): AdmissionContext
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if (! preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key)) {
            throw new UnprocessableEntityHttpException('Idempotency-Key is required and must contain 8–128 safe characters.');
        }

        $clientCode = trim((string) $request->header('X-Client-Code', config('services.billing.legacy_client_code', 'legacy')));
        $client = McrApiClient::where('McrCode', $clientCode)->where('McrIsActive', true)->where('SecStatus', true)->first();
        if (! $client) {
            throw new UnprocessableEntityHttpException('The API client is unknown or inactive.');
        }

        $companyId = $request->header('X-Company-Id', config('services.billing.legacy_company_id'));
        $companies = Empresa::where('McrIsActive', true)->where('SecStatus', true)
            ->where('McrEnvironment', config('sunat.production') ? 'production' : 'beta');
        if ($companyId !== null && $companyId !== '') {
            $companies->whereKey((int) $companyId);
        } elseif ((clone $companies)->count() !== 1) {
            throw new UnprocessableEntityHttpException('X-Company-Id is required when more than one active company exists.');
        }
        $company = $companies->first();
        if (! $company) {
            throw new UnprocessableEntityHttpException('The company is unknown, inactive, or belongs to another environment.');
        }

        $externalReference = $request->input('external_reference');

        return new AdmissionContext(
            (int) $client->getKey(),
            (int) $company->getKey(),
            $key,
            is_string($externalReference) && $externalReference !== '' ? $externalReference : null,
        );
    }
}
