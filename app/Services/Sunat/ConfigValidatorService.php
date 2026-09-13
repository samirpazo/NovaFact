<?php

namespace App\Services\Sunat;

use App\Repositories\EmpresaRepository;
use Illuminate\Support\Facades\Storage;

class ConfigValidatorService
{
    public function __construct(
        protected EmpresaRepository $empresaRepository
    ) {}

    /**
     * Valida la configuración de la empresa activa para facturación estándar.
     */
    public function validateFacturacion(): ?array
    {
        $empresa = $this->empresaRepository->getActive();

        if (!$empresa) {
            return [
                'success' => false,
                'message' => 'Configuración incompleta',
                'error' => 'No se encontró una empresa activa configurada en el sistema.',
                'field' => 'empresa_active'
            ];
        }

        if (empty($empresa->CpyRuc)) {
            return [
                'success' => false,
                'message' => 'Configuración incompleta',
                'error' => 'El RUC de la empresa no está configurado.',
                'field' => 'CpyRuc'
            ];
        }

        if (empty($empresa->CpyUserSol)) {
            return [
                'success' => false,
                'message' => 'Configuración incompleta',
                'error' => 'el Usuario SOL no está configurado.',
                'field' => 'CpyUserSol'
            ];
        }

        if (empty($empresa->CpyPasswordSol)) {
            return [
                'success' => false,
                'message' => 'Configuración incompleta',
                'error' => 'La Clave SOL no está configurada.',
                'field' => 'CpyPasswordSol'
            ];
        }

        if (empty($empresa->CpyNameCertificate)) {
            return [
                'success' => false,
                'message' => 'Configuración incompleta',
                'error' => 'El nombre del certificado no está configurado.',
                'field' => 'CpyNameCertificate'
            ];
        }

        // Validar existencia del archivo de certificado
        $certPath = storage_path('app/certificates/' . $empresa->CpyNameCertificate);
        if (!file_exists($certPath)) {
            return [
                'success' => false,
                'message' => 'Archivo no encontrado',
                'error' => "El archivo del certificado '{$empresa->CpyNameCertificate}' no existe en la carpeta storage/app/certificates/.",
                'field' => 'CpyNameCertificate_file'
            ];
        }

        // Validar contraseña si es PFX/P12
        $extension = strtolower(pathinfo($empresa->CpyNameCertificate, PATHINFO_EXTENSION));
        if (in_array($extension, ['pfx', 'p12']) && empty($empresa->CpyPasswordCertificate)) {
            return [
                'success' => false,
                'message' => 'Configuración incompleta',
                'error' => 'El certificado es tipo PFX/P12 pero la contraseña del certificado no está configurada.',
                'field' => 'CpyPasswordCertificate'
            ];
        }

        return ['success' => true];
    }

}
