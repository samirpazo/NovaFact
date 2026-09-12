<?php

namespace App\Actions\Facturacion;

use App\DTO\GuiaData;
use App\Services\Facturacion\GuiaService;

class EmitGuiaAction
{
    public function __construct(
        protected GuiaService $guiaService,
        protected \App\Services\Sunat\ConfigValidatorService $configValidator,
        protected \App\Services\Sunat\GreValidatorService $greValidator
    ) {}

    public function execute(array $data): array
    {
        // 1. Validar configuración (RUC, SOL, Certificado, API Keys)
        $configResult = $this->configValidator->validateGuia();
        if (!$configResult['success']) {
            return $configResult;
        }

        // 2. Validar Datos del Documento (UBL, Catalog 20, DAM/DS, Placas)
        $docResult = $this->greValidator->validate($data);
        if (!$docResult['success']) {
            return [
                'success' => false,
                'message' => 'Errores de validación en los datos de la guía (SUNAT Rules)',
                'errors' => $docResult['errors']
            ];
        }

        $dto = GuiaData::fromArray($data);
        
        $result = $this->guiaService->emitir($dto);

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Error desconocido al emitir guía',
                'code' => 'REST_API_ERROR',
            ];
        }

        return [
            'success' => true,
            'message' => 'Guía enviada exitosamente a SUNAT',
            'ticket' => $result['ticket'],
            'fec_recepcion' => $result['fec_recepcion'] ?? null,
            'cdr_base64' => $result['cdr_base64'] ?? null,
            // En GRE REST, el ID se construye con RUC-TIPO-SERIE-CORRELATIVO
            'id' => $dto->serie . '-' . $dto->correlativo, 
            'description' => $result['description'] ?? ($result['cdr_base64'] ? 'La guía fue aceptada y el CDR está disponible.' : 'La guía ha sido aceptada por el sistema REST, pendiente de procesamiento por ticket.'),
        ];
    }
}
