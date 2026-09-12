<?php

namespace App\Repositories;

use App\Models\Empresa;

class EmpresaRepository
{
    public function getActive(): ?Empresa
    {
        $environment = config('sunat.production') ? 'production' : 'beta';

        $company = Empresa::where('McrIsActive', true)
            ->where('SecStatus', true)
            ->where('McrEnvironment', $environment)
            ->first();
        return $company;
    }
}
