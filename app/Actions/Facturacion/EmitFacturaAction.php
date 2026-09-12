<?php

namespace App\Actions\Facturacion;

use App\DTO\FacturaData;
use App\Services\Facturacion\FacturaService;
use Greenter\Model\Response\BillResult;

class EmitFacturaAction
{
    public function __construct(
        protected FacturaService $facturaService,
        protected \App\Services\Sunat\ConfigValidatorService $validatorService
    ) {}

    public function execute(array $data): array
    {
        // Validar configuración antes de proceder
        $configError = $this->validatorService->validateFacturacion();
        if ($configError) {
            return $configError;
        }

        $dto = FacturaData::fromArray($data);
        
        $result = $this->facturaService->emitir($dto);

        if (!$result->isSuccess()) {
            return [
                'success' => false,
                'error' => $result->getError()->getMessage(),
                'code' => $result->getError()->getCode(),
            ];
        }

        $response = [
            'success' => true,
            'message' => 'Comprobante emitido exitosamente',
        ];

        $cdrZip = $result->getCdrZip();
        if ($cdrZip) {
            $response['cdr_base64'] = base64_encode($cdrZip);
        }

        if ($result->getCdrResponse()) {
            $response['id'] = $result->getCdrResponse()->getId();
            $response['description'] = $result->getCdrResponse()->getDescription();
            $response['notes'] = $result->getCdrResponse()->getNotes();
        }

        return $response;
    }
}
