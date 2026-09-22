<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use Illuminate\Console\Command;

class ConfigureSunatCredentials extends Command
{
    protected $signature = 'sunat:credentials';

    protected $description = 'Configura de forma interactiva y segura las credenciales SOL y la contraseña del certificado PFX.';

    public function handle(): int
    {
        $this->info('=== Configuración de Credenciales SUNAT (Modo Interactivo) ===');
        $this->newLine();

        $solUser = trim((string) $this->ask('SOL User'));
        if ($solUser === '') {
            $this->error('ERROR: SOL User no puede estar vacío.');
            return self::FAILURE;
        }

        $solPassword = (string) $this->secret('SOL Password');
        if ($solPassword === '') {
            $this->error('ERROR: SOL Password no puede estar vacía.');
            return self::FAILURE;
        }

        $pfxPassword = (string) $this->secret('PFX Password');
        if ($pfxPassword === '') {
            $this->error('ERROR: PFX Password no puede estar vacía.');
            return self::FAILURE;
        }

        $empresa = Empresa::where('McrIsActive', true)->where('SecStatus', true)->first();
        if (!$empresa) {
            $this->error('ERROR: No se encontró una empresa activa configurada.');
            return self::FAILURE;
        }

        // Guardar valores mediante los casts oficiales de Eloquent ('encrypted')
        $empresa->McrSolUser = $solUser;
        $empresa->McrSolPassword = $solPassword;
        $empresa->McrCertificatePassword = $pfxPassword;
        $empresa->save();

        $this->newLine();
        $this->info('Credenciales guardadas correctamente en la base de datos local (cifradas con APP_KEY).');
        $this->line('SOL User: SAVED');
        $this->line('SOL Password: ENCRYPTED & SAVED');
        $this->line('PFX Password: ENCRYPTED & SAVED');

        // Validar apertura del certificado PFX
        $certName = $empresa->McrCertificateName;
        $certPath = storage_path('app/certificates/' . $certName);

        if (!$certName || !file_exists($certPath)) {
            $this->error("Archivo PFX no encontrado en: {$certPath}");
            return self::FAILURE;
        }

        $certs = [];
        $content = file_get_contents($certPath);
        $readSuccess = openssl_pkcs12_read($content, $certs, $pfxPassword);

        if ($readSuccess && !empty($certs['cert'])) {
            $this->info('PFX Certificate Read: PASS');
            return self::SUCCESS;
        } else {
            $this->error('PFX Certificate Read: FAIL (No se pudo abrir el certificado con la contraseña ingresada)');
            return self::FAILURE;
        }
    }
}
