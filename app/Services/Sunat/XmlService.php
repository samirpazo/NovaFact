<?php

namespace App\Services\Sunat;

use Greenter\Model\DocumentInterface;
use Illuminate\Support\Facades\Storage;

class XmlService
{
    public function save(string $xml, string $filename): string
    {
        $path = 'facturacion/xml/' . $filename;
        Storage::disk('local')->put($path, $xml);
        
        return $path;
    }

    public function saveCdr(string $zip, string $filename): string
    {
        $path = 'facturacion/cdr/' . $filename;
        Storage::disk('local')->put($path, $zip);
        
        return $path;
    }
}
