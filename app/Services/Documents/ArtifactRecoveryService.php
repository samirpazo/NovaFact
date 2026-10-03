<?php

namespace App\Services\Documents;

use App\DTO\DespatchData;
use App\DTO\FacturaData;
use App\Enums\DocumentType;
use App\Models\Empresa;
use App\Models\McrDocument;
use App\Services\Facturacion\DespatchPdfService;
use App\Services\Facturacion\DespatchService;
use App\Services\Facturacion\FacturaService;
use App\Services\Facturacion\NoteService;
use Illuminate\Support\Facades\Storage;

final class ArtifactRecoveryService
{
    public function __construct(private FacturaService $invoices, private NoteService $notes,
        private DespatchService $despatches, private DespatchPdfService $despatchPdf) {}

    public function recover(McrDocument $document, array $payload): void
    {
        if (! $document->McrProcessingResult) throw new \LogicException('Fiscal result must be persisted before artifact recovery.');
        if (! $document->McrXmlPath || ! Storage::disk('local')->exists($document->McrXmlPath)) {
            throw new \LogicException('The exact signed XML is unavailable and must not be regenerated.');
        }
        $type = DocumentType::from($document->McrDocumentType);
        if (in_array($type, [DocumentType::Invoice, DocumentType::Receipt], true)) {
            $this->invoices->recoverArtifacts($document, FacturaData::fromArray($payload));
        } elseif (in_array($type, [DocumentType::CreditNote, DocumentType::DebitNote], true)) {
            $this->notes->recoverArtifacts($document, $payload);
        } else {
            $company = Empresa::findOrFail($document->McrCompanyConfigID);
            $model = $this->despatches->map($company, DespatchData::fromArray($payload));
            $root = dirname($document->McrXmlPath);
            $path = $root.'/'.$model->getName().'.pdf';
            $result = json_decode($document->McrProcessingResult, true, flags: JSON_THROW_ON_ERROR);
            $qrUrl = $result['metadata']['qr_url'] ?? null;
            if (! $qrUrl && $document->McrCdrPath && Storage::disk('local')->exists($document->McrCdrPath)) {
                $qrUrl = app(\App\Services\Sunat\GreCdrParser::class)->parse(Storage::disk('local')->get($document->McrCdrPath))->metadata['qr_url'] ?? null;
            }
            Storage::disk('local')->put($path, $this->despatchPdf->render($model, $qrUrl));
            $document->update(['McrPdfPath' => $path]);
        }
        $document->update(['McrArtifactError' => null]);
    }
}
