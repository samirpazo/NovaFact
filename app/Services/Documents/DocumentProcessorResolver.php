<?php

namespace App\Services\Documents;

use App\Services\Documents\Processors\InvoiceProcessor;
use App\Services\Documents\Processors\ReceiptProcessor;
use InvalidArgumentException;

class DocumentProcessorResolver
{
    public function resolve(string $type): ElectronicDocumentProcessor
    {
        return app(match ($type) {
            '01' => InvoiceProcessor::class,
            '03' => ReceiptProcessor::class,
            default => throw new InvalidArgumentException('Document type has no verified processor.'),
        });
    }
}
