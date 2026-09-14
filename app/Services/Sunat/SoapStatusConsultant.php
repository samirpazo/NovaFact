<?php

namespace App\Services\Sunat;

use App\Models\Empresa;
use Greenter\Model\Response\StatusCdrResult;

interface SoapStatusConsultant
{
    public function consult(Empresa $company, string $type, string $series, int $correlative): StatusCdrResult;
}
