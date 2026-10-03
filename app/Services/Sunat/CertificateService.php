<?php

namespace App\Services\Sunat;

use Illuminate\Support\Facades\Storage;

class CertificateService
{
    public function getCertificate($filename, ?string $password = null): ?string
    {
        if (! is_string($filename) || ! preg_match('/^[A-Za-z0-9._-]+$/D', $filename) || str_contains($filename, '..')) {
            return null;
        }
        $disk = Storage::disk('local');
        if ($disk->exists('certificates/'.$filename)) {
            $content = $disk->get('certificates/'.$filename);
        } else {
            // Existing installations stored certificates outside the private disk.
            $path = storage_path('app/certificates/'.$filename);
            if (! is_file($path)) {
                return null;
            }
            $content = file_get_contents($path);
        }
        if (in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['pfx', 'p12'], true)) {
            $certs = [];
            if (! openssl_pkcs12_read($content, $certs, $password ?? '') || empty($certs['cert']) || empty($certs['pkey'])) {
                throw new \RuntimeException('Certificate cannot be opened.');
            }

            return $certs['cert']."\n".$certs['pkey'];
        }

        return $content;
    }
}
