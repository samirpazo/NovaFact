<?php

namespace App\Repositories;

use App\Models\Serie;

class SerieRepository
{
    public function getByTipo(string $tipo, string $serie): ?Serie
    {
        return Serie::where('McrDocumentType', $tipo)
                    ->where('McrSeriesCode', $serie)
                    ->where('McrIsActive', true)
                    ->where('SecStatus', true)
                    ->first();
    }
}
