<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Services\Sunat\CertificateService;
use Illuminate\Console\Command;

class ConfigureSunatCredentials extends Command
{
    protected $signature = 'sunat:credentials {--company= : Explicit company ID}';

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

        $empresa = Empresa::whereKey((int) $this->option('company'))->where('McrIsActive', true)->where('SecStatus', true)->first();
        if (! $empresa) {
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
        try {
            app(CertificateService::class)->getCertificate($empresa->McrCertificateName, $pfxPassword)
                ?? throw new \RuntimeException('Certificate not found.');
            $this->info('Certificate Read: PASS');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Certificate Read: FAIL');

            return self::FAILURE;
        }
    }
}
