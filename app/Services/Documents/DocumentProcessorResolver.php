<?php

namespace App\Services\Documents;

use App\Enums\DocumentType;
use App\Services\Documents\Processors\CreditNoteProcessor;
use App\Services\Documents\Processors\DebitNoteProcessor;
use App\Services\Documents\Processors\InvoiceProcessor;
use App\Services\Documents\Processors\ReceiptProcessor;
use App\Services\Documents\Processors\SenderDespatchProcessor;
use App\Services\Documents\Processors\CarrierDespatchProcessor;
use InvalidArgumentException;

class DocumentProcessorResolver
{
    public function resolve(string $type): ElectronicDocumentProcessor
    {
        return app(match ($type) {
            DocumentType::Invoice->value => InvoiceProcessor::class,
            DocumentType::Receipt->value => ReceiptProcessor::class,
            DocumentType::CreditNote->value => CreditNoteProcessor::class,
            DocumentType::DebitNote->value => DebitNoteProcessor::class,
            DocumentType::SenderDespatch->value => SenderDespatchProcessor::class,
            DocumentType::CarrierDespatch->value => CarrierDespatchProcessor::class,
            default => throw new InvalidArgumentException('Document type has no verified processor.'),
        });
    }
}
