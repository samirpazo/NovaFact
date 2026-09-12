<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empresa extends Model
{
    protected $table = 'McrCompanyConfig';

    protected $primaryKey = 'McrCompanyConfigID';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'McrSolPassword' => 'encrypted',
            'McrCertificatePassword' => 'encrypted',
            'McrClientSecret' => 'encrypted',
            'McrIsActive' => 'boolean',
            'McrSpecialTaxRegime' => 'boolean',
            'SecStatus' => 'boolean',
        ];
    }

    public function getCpyRucAttribute(): ?string
    {
        return $this->McrRuc;
    }

    public function getCpyBusinessNameAttribute(): ?string
    {
        return $this->McrBusinessName;
    }

    public function getCpyTradenameAttribute(): ?string
    {
        return $this->McrTradeName;
    }

    public function getCpyAddressAttribute(): ?string
    {
        return $this->McrAddress;
    }

    public function getCpyUserSolAttribute(): ?string
    {
        return $this->McrSolUser;
    }

    public function getCpyPasswordSolAttribute(): ?string
    {
        return $this->McrSolPassword;
    }

    public function getCpyNameCertificateAttribute(): ?string
    {
        return $this->McrCertificateName;
    }

    public function getCpyPasswordCertificateAttribute(): ?string
    {
        return $this->McrCertificatePassword;
    }

    public function getCpyClientIdAttribute(): ?string
    {
        return $this->McrClientId;
    }

    public function getCpyClientSecretAttribute(): ?string
    {
        return $this->McrClientSecret;
    }

    public function getUbigeoAttribute(): ?string
    {
        return $this->McrUbigeo;
    }

    public function getDepartamentoAttribute(): ?string
    {
        return $this->McrDepartment;
    }

    public function getProvinciaAttribute(): ?string
    {
        return $this->McrProvince;
    }

    public function getDistritoAttribute(): ?string
    {
        return $this->McrDistrict;
    }

    public function getUrbanizacionAttribute(): ?string
    {
        return $this->McrUrbanization;
    }
}
