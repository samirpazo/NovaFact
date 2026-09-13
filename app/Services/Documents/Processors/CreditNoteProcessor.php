<?php

namespace App\Services\Documents\Processors;

use App\Models\McrDocument;
use App\Services\Documents\ElectronicDocumentProcessor;
use App\Services\Documents\ProcessingResult;
use App\Services\Facturacion\NoteService;

class CreditNoteProcessor implements ElectronicDocumentProcessor
{
    public function __construct(private NoteService $service) {}

    public function process(McrDocument $document, array $payload): ProcessingResult
    {
        return ProcessingResult::fromBillResult($this->service->emitPersisted($document, $payload));
    }
}
