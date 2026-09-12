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
        $configResult = $this->validatorService->validateFacturacion();
        if (!$configResult['success']) {
            return $configResult;
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

        $pdf = $this->facturaService->getLastPdf();
        if ($pdf) {
            $response['pdf_path'] = $pdf['path'];
            $pdfName = basename($pdf['path']);
            $xmlName = str_replace('.pdf', '.xml', $pdfName);
            $cdrName = 'R-' . str_replace('.pdf', '.zip', $pdfName);
            $response['pdf_url'] = url('/api/facturacion/archivo/pdf/'.$pdfName);
            $response['xml_url'] = url('/api/facturacion/archivo/xml/'.$xmlName);
            $response['cdr_url'] = url('/api/facturacion/archivo/cdr/'.$cdrName);
        }
        $response['document_id'] = $this->facturaService->getLastDocumentId();

        return $response;
    }
}
