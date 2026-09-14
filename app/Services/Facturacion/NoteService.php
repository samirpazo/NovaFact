<?php

namespace App\Services\Facturacion;

use App\DTO\FacturaData;
use App\Enums\ProcessingCheckpoint;
use App\Exceptions\ClassifiedSubmissionException;
use App\Models\Empresa;
use App\Models\McrDocument;
use App\Services\Documents\ProcessingResult;
use App\Services\Documents\SubmissionCheckpoint;
use App\Services\Sunat\GreenterService;
use App\Support\DecimalAmount;
use Greenter\Model\Client\Client;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\SaleDetail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class NoteService
{
    public function __construct(
        private GreenterService $greenter,
        private InvoicePdfService $pdf,
        private ManagedFileService $files,
        private SubmissionCheckpoint $checkpoint,
        private FiscalCompanyFactory $fiscalCompany,
    ) {}

    public function emitPersisted(McrDocument $document, array $payload): BillResult
    {
        $company = Empresa::findOrFail($document->McrCompanyConfigID);
        if (! $company->McrIsActive || ! $company->SecStatus
            || $company->McrEnvironment !== (config('sunat.production') ? 'production' : 'beta')) {
            throw new \RuntimeException('Document company is not active in this worker environment.');
        }
        $data = FacturaData::fromArray($payload);
        $data->serie = $document->McrSeriesCode;
        $data->correlativo = (string) $document->McrCorrelative;
        $note = $this->map($data, $payload['reference'], $company, DecimalAmount::add($payload['mtoOperGravada'], $payload['mtoIGV']), $payload['mtoTotal'], $document->McrEstablishmentSnapshot);
        $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::XmlGenerated);
        $disk = Storage::disk('local');
        $name = $document->getKey().'-'.$note->getName();
        $xmlPath = 'facturacion/xml/'.$name.'.xml';
        $xml = $disk->exists($xmlPath) ? $disk->get($xmlPath) : $this->greenter->getXml($note, $company);
        if ($xml === '' || (! $disk->exists($xmlPath) && ! $disk->put($xmlPath, $xml))) {
            throw new \RuntimeException('Could not persist signed XML.');
        }
        $document->update(['McrXmlPath' => $xmlPath]);
        $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::XmlSigned);
        $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::SubmissionStarted, true);
        try {
            $result = $this->greenter->sendSignedXml(Note::class, $note->getName(), $xml, $company);
        } catch (\Throwable $exception) {
            throw ClassifiedSubmissionException::ambiguous(ProcessingCheckpoint::SubmissionStarted, $exception);
        }
        $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::RemoteResponseReceived);
        $document->update(['McrProcessingResult' => json_encode(ProcessingResult::fromBillResult($result)->toArray(), JSON_THROW_ON_ERROR)]);
        $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::RemoteResultPersisted);

        try {
            if ($result->getCdrZip()) {
                $cdrPath = 'facturacion/cdr/R-'.$name.'.zip';
                if (! $disk->exists($cdrPath) && ! $disk->put($cdrPath, $result->getCdrZip())) {
                    throw new \RuntimeException('Could not persist CDR.');
                }
                $document->update(['McrCdrPath' => $cdrPath]);
            }
            $document->update(['McrXmlFilID' => $this->files->register($xmlPath, $name.'.xml', 'application/xml')]);
            if (isset($cdrPath)) {
                $document->update(['McrCdrFilID' => $this->files->register($cdrPath, 'R-'.$name.'.zip', 'application/zip')]);
            }
            if ($result->isSuccess() && $result->getCdrResponse()?->isAccepted()) {
                $pdf = $this->pdf->generate($note, $name.'.pdf');
                $document->update([
                    'McrPdfPath' => $pdf['path'],
                    'McrPdfFilID' => $this->files->register($pdf['path'], $name.'.pdf', 'application/pdf'),
                ]);
            }
            $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::ArtifactsGenerated);
        } catch (\Throwable $exception) {
            $document->update(['McrArtifactError' => 'Artifact generation or registration failed; rebuild locally without resending to SUNAT.']);
            Log::error('document.artifact.failed', ['document_id' => $document->getKey(), 'exception_type' => get_class($exception)]);
        }

        return $result;
    }

    public function recoverArtifacts(McrDocument $document, array $payload): void
    {
        $company = Empresa::findOrFail($document->McrCompanyConfigID);
        $data = FacturaData::fromArray($payload);
        $data->serie = $document->McrSeriesCode;
        $data->correlativo = (string) $document->McrCorrelative;
        $note = $this->map($data, $payload['reference'], $company, DecimalAmount::add($payload['mtoOperGravada'], $payload['mtoIGV']), $payload['mtoTotal'], $document->McrEstablishmentSnapshot);
        $name = $document->getKey().'-'.$note->getName();
        $document->update(['McrXmlFilID' => $this->files->register($document->McrXmlPath, $name.'.xml', 'application/xml')]);
        if ($document->McrCdrPath && Storage::disk('local')->exists($document->McrCdrPath)) {
            $document->update(['McrCdrFilID' => $this->files->register($document->McrCdrPath, 'R-'.$name.'.zip', 'application/zip')]);
        }
        $pdf = $this->pdf->generate($note, $name.'.pdf');
        $document->update(['McrPdfPath' => $pdf['path'], 'McrPdfFilID' => $this->files->register($pdf['path'], $name.'.pdf', 'application/pdf')]);
    }

    public function map(FacturaData $data, array $reference, Empresa $companyConfig, string $subTotal, string|int $totalText, ?array $establishmentSnapshot = null): Note
    {
        $client = (new Client)->setTipoDoc($data->clientTipoDoc)->setNumDoc($data->clientNumDoc)->setRznSocial($data->clientRznSocial);
        $company = $this->fiscalCompany->make($companyConfig, $establishmentSnapshot);
        $rate = $data->igvRate ?? (float) ($companyConfig->McrIgvRate ?? 18);
        $note = (new Note)->setUblVersion('2.1')->setTipoDoc($data->tipoDoc)->setSerie($data->serie)
            ->setCorrelativo($data->correlativo)->setFechaEmision(new \DateTime($data->fechaEmision))
            ->setTipoMoneda($data->tipoMoneda)->setCompany($company)->setClient($client)
            ->setTipDocAfectado($reference['document_type'])->setNumDocfectado($reference['series'].'-'.$reference['correlative'])
            ->setCodMotivo($reference['reason_code'])->setDesMotivo($reference['reason'])
            ->setMtoOperGravadas($data->mtoOperGravada)->setMtoIGV($data->mtoIGV)->setTotalImpuestos($data->mtoIGV)
            ->setValorVenta($data->mtoOperGravada)->setSubTotal((float) $subTotal)->setMtoImpVenta($data->mtoTotal);
        $details = array_map(fn (array $item): SaleDetail => (new SaleDetail)
            ->setCodProducto($item['codigo'] ?? 'P001')->setUnidad($item['unidad'] ?? 'NIU')->setCantidad($item['cantidad'])
            ->setDescripcion($item['descripcion'])->setMtoBaseIgv($item['mtoBaseIgv'])->setPorcentajeIgv($rate)
            ->setIgv($item['igv'])->setTotalImpuestos($item['igv'])->setTipAfeIgv('10')
            ->setMtoValorVenta($item['mtoValorVenta'])->setMtoValorUnitario($item['mtoValorUnitario'])
            ->setMtoPrecioUnitario($item['mtoPrecioUnitario']), $data->items);
        $note->setDetails($details)->setLegends([(new Legend)->setCode('1000')->setValue('SON '.$totalText.' SOLES')]);

        return $note;
    }
}
