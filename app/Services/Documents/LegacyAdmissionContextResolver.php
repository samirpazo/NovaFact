<?php

namespace App\Services\Documents;

use App\Models\McrDocument;
use App\Services\Auth\ApiScope;
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

        $scope = ApiScope::from($request);
        $client = $scope->client;
        $company = $scope->company;

        if (($request->input('reference.kind')) === 'internal') {
            abort_unless($scope->documents(McrDocument::query())->whereKey($request->input('reference.document_id'))->exists(), 404, 'Referenced document not found.');
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
