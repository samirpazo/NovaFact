<?php

namespace App\Services\Documents;

use App\Models\Empresa;
use App\Models\McrEstablishment;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class EstablishmentResolver
{
    public function resolve(Empresa $company, array $payload): McrEstablishment
    {
        $query = McrEstablishment::where('McrCompanyConfigID', $company->getKey())
            ->where('McrIsActive', true)->where('SecStatus', true);
        $externalCode = isset($payload['establishment']) ? trim((string) $payload['establishment']) : '';
        if ($externalCode !== '') {
            $establishment = (clone $query)->where('McrExternalCode', $externalCode)->first();
        } else {
            $defaults = (clone $query)->where('McrIsDefault', true)->get();
            $establishment = $defaults->count() === 1 ? $defaults->first() : null;
        }
        if (! $establishment) {
            throw new UnprocessableEntityHttpException($externalCode !== ''
                ? 'The establishment does not exist, is inactive, or belongs to another company.'
                : 'establishment is required when there is no single active default establishment.');
        }
        if (! preg_match('/^\d{4}$/D', (string) $establishment->McrSunatCode)
            || trim((string) $establishment->McrAddress) === ''
            || ! preg_match('/^\d{6}$/D', (string) $establishment->McrUbigeo)) {
            throw new UnprocessableEntityHttpException('The establishment fiscal code, address, or ubigeo is incomplete.');
        }

        return $establishment;
    }
}
