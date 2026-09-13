<?php

namespace App\Services\Documents\Processors;

use App\DTO\FacturaData;
use App\Models\McrDocument;
use App\Services\Documents\ElectronicDocumentProcessor;
use App\Services\Documents\ProcessingResult;
use App\Services\Facturacion\FacturaService;

class InvoiceProcessor implements ElectronicDocumentProcessor
{
    public function __construct(private FacturaService $service) {}

    public function process(McrDocument $document, array $payload): ProcessingResult
    {
        return ProcessingResult::fromBillResult($this->service->emitPersisted($document, FacturaData::fromArray($payload)));
    }
}
