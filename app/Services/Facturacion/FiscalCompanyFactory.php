<?php

namespace App\Services\Facturacion;

use App\Models\Empresa;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;

final class FiscalCompanyFactory
{
    public function make(Empresa $company, ?array $snapshot): Company
    {
        $snapshot ??= [
            'sunat_code' => '0000', 'trade_name' => $company->McrTradeName, 'name' => $company->McrTradeName,
            'address' => $company->McrAddress, 'ubigeo' => $company->McrUbigeo,
            'department' => $company->McrDepartment, 'province' => $company->McrProvince,
            'district' => $company->McrDistrict, 'address_reference' => null, 'country_code' => 'PE',
            'phone' => $company->McrPhone, 'email' => $company->McrEmail,
        ];

        $phone = $snapshot['phone'] ?? null;
        $email = $snapshot['email'] ?? null;

        if ((! $phone || ! $email) && $company->getKey()) {
            $est = \App\Models\McrEstablishment::where('McrCompanyConfigID', $company->getKey())
                ->where(function ($q) use ($snapshot): void {
                    if (! empty($snapshot['external_code'])) {
                        $q->where('McrExternalCode', $snapshot['external_code']);
                    } elseif (! empty($snapshot['sunat_code'])) {
                        $q->where('McrSunatCode', $snapshot['sunat_code']);
                    }
                })->first();

            if ($est) {
                $phone ??= $est->McrPhone;
                $email ??= $est->McrEmail;
            }
        }

        $phone ??= $company->McrPhone;
        $email ??= $company->McrEmail;

        return (new Company)->setRuc($company->McrRuc)->setRazonSocial($company->McrBusinessName)
            ->setNombreComercial(($snapshot['trade_name'] ?? null) ?: (($snapshot['name'] ?? null) ?: $company->McrTradeName))
            ->setTelephone($phone)
            ->setEmail($email)
            ->setAddress((new Address)->setUbigueo($snapshot['ubigeo'])->setDepartamento($snapshot['department'] ?? null)
                ->setProvincia($snapshot['province'] ?? null)->setDistrito($snapshot['district'] ?? null)
                ->setDireccion($snapshot['address'])->setCodLocal($snapshot['sunat_code']));
    }
}
