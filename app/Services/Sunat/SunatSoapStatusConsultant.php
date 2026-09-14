<?php

namespace App\Services\Sunat;

use App\Models\Empresa;
use Greenter\Model\Response\StatusCdrResult;
use Greenter\Ws\Services\ConsultCdrService;
use Greenter\Ws\Services\SoapClient;
use Greenter\Ws\Services\SunatEndpoints;

final class SunatSoapStatusConsultant implements SoapStatusConsultant
{
    public function consult(Empresa $company, string $type, string $series, int $correlative): StatusCdrResult
    {
        if ($company->McrEnvironment !== 'production') {
            throw new \DomainException('Automatic SOAP CDR consultation is unavailable for this environment.');
        }
        $client = new SoapClient;
        $client->setCredentials($company->CpyRuc.$company->CpyUserSol, $company->CpyPasswordSol);
        $client->setService(SunatEndpoints::FE_CONSULTA_CDR);
        return (new ConsultCdrService)->setClient($client)->getStatusCdr(
            $company->CpyRuc, $type, $series, $correlative
        );
    }
}
