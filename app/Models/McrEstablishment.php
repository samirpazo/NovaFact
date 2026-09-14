<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class McrEstablishment extends Model
{
    protected $table = 'McrEstablishment';

    protected $primaryKey = 'McrEstablishmentID';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['McrIsDefault' => 'boolean', 'McrIsActive' => 'boolean', 'SecStatus' => 'boolean'];
    }

    public function snapshot(): array
    {
        return [
            'external_code' => $this->McrExternalCode,
            'sunat_code' => $this->McrSunatCode,
            'name' => $this->McrName,
            'trade_name' => $this->McrTradeName,
            'address' => $this->McrAddress,
            'address_reference' => $this->McrAddressReference,
            'ubigeo' => $this->McrUbigeo,
            'department' => $this->McrDepartment,
            'province' => $this->McrProvince,
            'district' => $this->McrDistrict,
            'country_code' => $this->McrCountryCode,
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(McrDocument::class, 'McrEstablishmentID', 'McrEstablishmentID');
    }
}
