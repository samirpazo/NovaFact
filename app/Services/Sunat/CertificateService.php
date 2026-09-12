<?php

namespace App\Services\Sunat;

use Illuminate\Support\Facades\Storage;

class CertificateService
{
    public function getCertificate($filename, ?string $password = null): ?string
    {
        $path = storage_path('app/certificates/' . $filename);
        
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Si es PFX o P12, extraemos el contenido PEM usando la contraseña
        if (in_array($extension, ['pfx', 'p12'])) {
            $certs = [];
            if (openssl_pkcs12_read($content, $certs, $password)) {
                return $certs['cert'] . "\n" . $certs['pkey'];
            }
            throw new \Exception("No se pudo leer el certificado PFX: Verifica la contraseña.");
        }

        return $content;
    }
}
