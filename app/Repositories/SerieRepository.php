<?php

namespace App\Repositories;

use App\Models\Serie;

class SerieRepository
{
    public function getByTipo(string $tipo, string $serie): ?Serie
    {
        return Serie::where('tipo_comprobante_id', $tipo)
                    ->where('serie', $serie)
                    ->first();
    }
}
