<?php

namespace App\Services\Facturacion;

use App\DTO\FacturaData;
use App\Services\Sunat\GreenterService;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Company;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\SaleDetail;
use App\Enums\ProcessingCheckpoint;
use App\Exceptions\ClassifiedSubmissionException;
use App\Services\Documents\SubmissionCheckpoint;

class FacturaService
{
    public function __construct(
        protected GreenterService $greenterService,
        protected InvoicePdfService $invoicePdfService,
        protected ManagedFileService $managedFileService,
        protected SubmissionCheckpoint $checkpoint,
    ) {}

    /** Process a reserved document without ever allocating another number. */
    public function emitPersisted(\App\Models\McrDocument $document, FacturaData $data): BillResult
    {
        $company = \App\Models\Empresa::findOrFail($document->McrCompanyConfigID);
        if (! $company->McrIsActive || ! $company->SecStatus ||
            $company->McrEnvironment !== (config('sunat.production') ? 'production' : 'beta')) {
            throw new \RuntimeException('Document company is not active in this worker environment.');
        }
        $data->serie = $document->McrSeriesCode;
        $data->correlativo = (string) $document->McrCorrelative;
        $invoice = $this->mapToInvoice($data, $company);
        $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::XmlGenerated);
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $name = $document->getKey().'-'.$invoice->getName();
        $xmlPath = 'facturacion/xml/'.$name.'.xml';
        if ($disk->exists($xmlPath)) {
            $xml = $disk->get($xmlPath);
        } else {
            $xml = $this->greenterService->getXml($invoice, $company);
            if ($xml === '' || ! $disk->put($xmlPath, $xml)) {
                throw new \RuntimeException('Could not persist signed XML.');
            }
        }
        $document->update(['McrXmlPath' => $xmlPath]);
        $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::XmlSigned);
        // Send exactly the signed bytes that were persisted, without signing twice.
        $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::SubmissionStarted, true);
        try {
            $result = $this->greenterService->sendSignedXml(Invoice::class, $invoice->getName(), $xml, $company);
        } catch (\Throwable $exception) {
            throw ClassifiedSubmissionException::ambiguous(ProcessingCheckpoint::SubmissionStarted, $exception);
        }
        $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::RemoteResponseReceived);

        // Durable response checkpoint precedes slow/local artifact work. A worker
        // timeout during PDF generation must preserve the known fiscal outcome.
        $document->update(['McrProcessingResult' => json_encode(\App\Services\Documents\ProcessingResult::fromBillResult($result)->toArray(), JSON_THROW_ON_ERROR)]);
        $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::RemoteResultPersisted);

        // A PDF/GenFile failure after SUNAT answered must never trigger a resend.
        try {
            if ($result->getCdrZip()) {
                $cdrPath = 'facturacion/cdr/R-'.$name.'.zip';
                if (! $disk->exists($cdrPath) && ! $disk->put($cdrPath, $result->getCdrZip())) {
                    throw new \RuntimeException('Could not persist CDR.');
                }
                $document->update(['McrCdrPath' => $cdrPath]);

            }
            $document->update(['McrXmlFilID' => $this->managedFileService->register($xmlPath, $name.'.xml', 'application/xml')]);
            if (isset($cdrPath)) {
                $document->update(['McrCdrFilID' => $this->managedFileService->register($cdrPath, 'R-'.$name.'.zip', 'application/zip')]);
            }
            if ($result->isSuccess() && $result->getCdrResponse()?->isAccepted()) {
                $pdf = $this->invoicePdfService->generate($invoice, $name.'.pdf');
                $document->update(['McrPdfPath' => $pdf['path']]);
                $document->update(['McrPdfFilID' => $this->managedFileService->register($pdf['path'], $name.'.pdf', 'application/pdf')]);
            }
            $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::ArtifactsGenerated);
        } catch (\Throwable $e) {
            $document->update(['McrArtifactError' => 'Artifact generation or registration failed; rebuild locally without resending to SUNAT.']);
            \Illuminate\Support\Facades\Log::error('document.artifact.failed', [
                'document_id' => $document->getKey(), 'exception_type' => get_class($e),
            ]);
        }

        return $result;
    }

    public function recoverArtifacts(\App\Models\McrDocument $document, FacturaData $data): void
    {
        $company = \App\Models\Empresa::findOrFail($document->McrCompanyConfigID);
        $data->serie = $document->McrSeriesCode; $data->correlativo = (string) $document->McrCorrelative;
        $invoice = $this->mapToInvoice($data, $company); $name = $document->getKey().'-'.$invoice->getName();
        $document->update(['McrXmlFilID' => $this->managedFileService->register($document->McrXmlPath, $name.'.xml', 'application/xml')]);
        if ($document->McrCdrPath && \Illuminate\Support\Facades\Storage::disk('local')->exists($document->McrCdrPath))
            $document->update(['McrCdrFilID' => $this->managedFileService->register($document->McrCdrPath, 'R-'.$name.'.zip', 'application/zip')]);
        $pdf = $this->invoicePdfService->generate($invoice, $name.'.pdf');
        $document->update(['McrPdfPath' => $pdf['path'], 'McrPdfFilID' => $this->managedFileService->register($pdf['path'], $name.'.pdf', 'application/pdf')]);
    }

    protected function mapToInvoice(FacturaData $data, \App\Models\Empresa $companyConfig): Invoice
    {
        $client = (new Client)
            ->setTipoDoc($data->clientTipoDoc)
            ->setNumDoc($data->clientNumDoc)
            ->setRznSocial($data->clientRznSocial);

        $empresaActual = $companyConfig;
        // La tasa debe salir de la configuración tributaria de la empresa. Usar
        // una tasa fija aquí desincroniza el XML cuando la operación (por ejemplo
        // en Beta) está configurada con una tasa distinta al 18% estándar.
        $igvRate = $data->igvRate ?? (float) ($empresaActual->McrIgvRate ?? 18);
        $company = (new Company)
            ->setRuc($empresaActual->CpyRuc)
            ->setRazonSocial($empresaActual->CpyBusinessName)
            ->setNombreComercial($empresaActual->CpyTradename)
            ->setAddress((new \Greenter\Model\Company\Address)
                ->setUbigueo($empresaActual->ubigeo)
                ->setDepartamento($empresaActual->departamento)
                ->setProvincia($empresaActual->provincia)
                ->setDistrito($empresaActual->distrito)
                ->setUrbanizacion($empresaActual->urbanizacion)
                ->setDireccion($empresaActual->CpyAddress)
                ->setCodLocal('0000'));

        $subTotal = floatval($data->mtoOperGravada + $data->mtoIGV);

        $invoice = (new \Greenter\Model\Sale\Invoice)
            ->setUblVersion('2.1')
            ->setTipoOperacion('0101') // Venta interna
            ->setTipoDoc($data->tipoDoc)
            ->setSerie($data->serie)
            ->setCorrelativo($data->correlativo)
            ->setFechaEmision(new \DateTime($data->fechaEmision))
            ->setTipoMoneda($data->tipoMoneda)
            ->setCompany($company)
            ->setClient($client)
            ->setMtoOperGravadas($data->mtoOperGravada)
            ->setMtoIGV($data->mtoIGV)
            ->setTotalImpuestos($data->mtoIGV)
            ->setValorVenta($data->mtoOperGravada)
            ->setSubTotal($subTotal)
            ->setFormaPago(new \Greenter\Model\Sale\FormaPagos\FormaPagoContado);

        // Manejo Exclusivo de Anticipos
        if ($data->mtoTotalAnticipos > 0) {
            $invoice->setTotalAnticipos($data->mtoTotalAnticipos);
        }

        // Manejo Exclusivo de Descuentos Globales
        if ($data->sumDsctoGlobal > 0) {
            $invoice->setSumDsctoGlobal($data->sumDsctoGlobal);
        }

        // El Monto Total se le Restan los Anticipos en UBL 2.1
        $invoice->setMtoImpVenta($data->mtoTotal);

        // Guías relacionadas
        if (! empty($data->guias)) {
            $relatedDocs = [];
            foreach ($data->guias as $g) {
                $relatedDocs[] = (new \Greenter\Model\Sale\Document)
                    ->setTipoDoc($g['tipoDoc'])
                    ->setNroDoc($g['nroDoc']);
            }
            $invoice->setGuias($relatedDocs);
        }

        // Facturas de Anticipo relacionadas
        if (! empty($data->anticipos)) {
            $relatedPrepayments = [];
            foreach ($data->anticipos as $anticipo) {
                // Si el anticipo tiene base reportamos el Descuento Global
                if (isset($anticipo['montoBase'])) {
                    $descuentoGlobal = (new \Greenter\Model\Sale\Charge)
                        ->setCodTipo('04')
                        ->setFactor(1.00)
                        ->setMontoBase($anticipo['montoBase'])
                        ->setMonto($anticipo['montoBase']);

                    $descuentos = $invoice->getDescuentos() ?? [];
                    $descuentos[] = $descuentoGlobal;
                    $invoice->setDescuentos($descuentos);
                }

                $relatedPrepayments[] = (new \Greenter\Model\Sale\Prepayment)
                    ->setTipoDocRel($anticipo['tipoDocRel'])
                    ->setNroDocRel($anticipo['nroDocRel'])
                    ->setTotal($anticipo['total']);
            }
            $invoice->setAnticipos($relatedPrepayments);
        }

        $details = [];
        foreach ($data->items as $item) {
            $details[] = (new SaleDetail)
                ->setCodProducto($item['codigo'] ?? 'P001')
                ->setUnidad($item['unidad'] ?? 'NIU')
                ->setCantidad($item['cantidad'])
                ->setDescripcion($item['descripcion'])
                ->setMtoBaseIgv($item['mtoBaseIgv'])
                ->setPorcentajeIgv($igvRate)
                ->setIgv($item['igv'])
                ->setTotalImpuestos($item['igv'])
                ->setTipAfeIgv('10') // Gravado - Operación Onerosa
                ->setMtoValorVenta($item['mtoValorVenta'])
                ->setMtoValorUnitario($item['mtoValorUnitario'])
                ->setMtoPrecioUnitario($item['mtoPrecioUnitario']);
        }

        $invoice->setDetails($details)
            ->setLegends([
                (new Legend)
                    ->setCode('1000')
                    ->setValue('SON '.number_format($data->mtoTotal, 2, '.', '').' SOLES'),
            ]);

        return $invoice;
    }
}
