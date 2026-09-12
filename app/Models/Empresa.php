<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empresa extends Model
{
    protected $table = 'GenCompany';
    protected $guarded = [];

    /*
     * Campos esperados:
     * CpyRuc, CpyTradename, CpyBusinessName, CpyAddress,
     * ubigeo, departamento, provincia, distrito, urbanizacion
     */
}
