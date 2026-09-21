<?php

namespace App\Services\Facturacion;

use Greenter\Report\XmlUtils;
use Illuminate\Support\Facades\Storage;

final class SignedXmlDigestValue
{
    public function extract(string $xml): ?string
    {
        $digest = (new XmlUtils)->getHashSign($xml);
        $digest = is_string($digest) ? trim($digest) : '';

        return $digest === '' ? null : $digest;
    }

    public function extractFromStorage(?string $path): ?string
    {
        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return $this->extract(Storage::disk('local')->get($path));
    }
}
