<?php

namespace App\Services\Documents;

use App\Models\McrDocument;

interface ElectronicDocumentProcessor
{
    public function process(McrDocument $document, array $payload): ProcessingResult;
}
