<?php

namespace App\Repositories;

use App\Models\Empresa;

class EmpresaRepository
{
    public function getActive(): ?Empresa
    {
        if (!config('sunat.production')) {
            // MODO DEMO: Retornar objeto virtual con datos de .env
            $empresa = new Empresa();
            $empresa->forceFill([
                'CpyRuc' => '20000000001', // RUC ESTÁNDAR DE PRUEBAS
                'CpyBusinessName' => 'EMPRESA DE PRUEBA S.A.C',
                'CpyTradename' => 'EMPRESA DE PRUEBA DEMO',
                'ubigeo' => '150101',
                'departamento' => 'LIMA',
                'provincia' => 'LIMA',
                'distrito' => 'LIMA',
                'urbanizacion' => '-',
                'CpyAddress' => 'AV. LAS PRUEBAS 123',
                'CpyUserSol' => 'MODDATOS',
                'CpyPasswordSol' => 'MODDATOS',
                'CpyNameCertificate' => 'certificate_full_demo.pem',
                'CpyPasswordCertificate' => '12345678',
                'CpyClientId' => 'test-85e5b0ae-255c-4891-a595-0b98c65c9854',
                'CpyClientSecret' => 'test-Hty/M6QshYvPgItX2P0+Kw==',
            ]);
            return $empresa;
        }

        // MODO PRODUCCIÓN: Consultar base de datos GENCOMPANY
        $company = Empresa::where('SecStatus', 1)->first() ?? Empresa::first();
        return $company;
    }
}
