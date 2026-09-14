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
use App\Services\Facturacion\ManagedFileService;
use App\Services\Facturacion\NoteService;
use Illuminate\Support\Facades\Storage;

final class ArtifactRecoveryService
{
    public function __construct(private FacturaService $invoices, private NoteService $notes,
        private DespatchService $despatches, private DespatchPdfService $despatchPdf, private ManagedFileService $files) {}

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
            Storage::disk('local')->put($path, $this->despatchPdf->render($model));
            $document->update(['McrPdfPath' => $path, 'McrPdfFilID' => $this->files->register($path, basename($path), 'application/pdf')]);
        }
        $document->update(['McrArtifactError' => null]);
    }
}
