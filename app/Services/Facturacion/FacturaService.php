<?php

namespace App\Services\Facturacion;

use App\DTO\FacturaData;
use App\Services\Sunat\GreenterService;
use App\Services\Sunat\XmlService;
use Greenter\Model\Sale\FormaPago\PagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Company;
use Greenter\Model\Response\BillResult;
use App\Models\McrSeries;

class FacturaService
{
    protected ?array $lastPdf = null;
    protected ?int $lastDocumentId = null;

    public function __construct(
        protected GreenterService $greenterService,
        protected XmlService $xmlService,
        protected \App\Repositories\EmpresaRepository $empresaRepository,
        protected InvoicePdfService $invoicePdfService,
        protected McrPersistenceService $persistenceService,
        protected ManagedFileService $managedFileService
    ) {}

    public function emitir(FacturaData $data): BillResult
    {
        $companyConfig = $this->empresaRepository->getActive();
        $data->serie ??= McrSeries::query()->where('McrCompanyConfigID', $companyConfig->getKey())->where('McrDocumentType', $data->tipoDoc)->where('McrIsActive', true)->where('SecStatus', true)->value('McrSeriesCode');
        $data->serie ??= $data->tipoDoc === '03' ? 'B001' : 'F001';
        $data->correlativo ??= '1';
        [$series, $correlative] = $this->persistenceService->reserveSeries($companyConfig, $data);
        $data->correlativo = (string) $correlative;
        $invoice = $this->mapToInvoice($data);
        
        // Obtenemos y guardamos el XML *antes* de enviarlo a SUNAT para registro (Evita perderlo si falla la conexión)
        $xmlSigned = $this->greenterService->getXml($invoice);
        $xmlPath = $this->xmlService->save($xmlSigned, $invoice->getName().'.xml');

        $result = $this->greenterService->send($invoice);

        if ($result->isSuccess()) {
            $cdrZip = $result->getCdrZip();
            if ($cdrZip) {
                $cdrPath = $this->xmlService->saveCdr($cdrZip, 'R-'.$invoice->getName().'.zip');
            }

            $this->lastPdf = $this->invoicePdfService->generate($invoice);
            $document = $this->persistenceService->storeAccepted(
                $data, $companyConfig, $correlative, $result, $this->lastPdf, $invoice->getName(),
                $this->managedFileService->register($xmlPath, $invoice->getName().'.xml', 'application/xml'),
                isset($cdrPath) ? $this->managedFileService->register($cdrPath, 'R-'.$invoice->getName().'.zip', 'application/zip') : null,
                $this->managedFileService->register($this->lastPdf['path'], $invoice->getName().'.pdf', 'application/pdf')
            );
            $this->lastDocumentId = $document->getKey();
        }

        return $result;
    }

    public function getLastPdf(): ?array
    {
        return $this->lastPdf;
    }

    public function getLastDocumentId(): ?int
    {
        return $this->lastDocumentId;
    }

    protected function mapToInvoice(FacturaData $data): Invoice
    {
        $client = (new Client())
            ->setTipoDoc($data->clientTipoDoc)
            ->setNumDoc($data->clientNumDoc)
            ->setRznSocial($data->clientRznSocial);

        $empresaActual = $this->empresaRepository->getActive();
        // La tasa debe salir de la configuración tributaria de la empresa. Usar
        // una tasa fija aquí desincroniza el XML cuando la operación (por ejemplo
        // en Beta) está configurada con una tasa distinta al 18% estándar.
        $igvRate = $data->igvRate ?? (float) ($empresaActual->McrIgvRate ?? 18);
        $company = (new Company())
            ->setRuc($empresaActual->CpyRuc)
            ->setRazonSocial($empresaActual->CpyBusinessName)
            ->setNombreComercial($empresaActual->CpyTradename)
            ->setAddress((new \Greenter\Model\Company\Address())
                ->setUbigueo($empresaActual->ubigeo)
                ->setDepartamento($empresaActual->departamento)
                ->setProvincia($empresaActual->provincia)
                ->setDistrito($empresaActual->distrito)
                ->setUrbanizacion($empresaActual->urbanizacion)
                ->setDireccion($empresaActual->CpyAddress)
                ->setCodLocal('0000'));

        $subTotal = floatval($data->mtoOperGravada + $data->mtoIGV);

        $invoice = (new \Greenter\Model\Sale\Invoice())
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
            ->setFormaPago(new \Greenter\Model\Sale\FormaPagos\FormaPagoContado());

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
        if (!empty($data->guias)) {
            $relatedDocs = [];
            foreach ($data->guias as $g) {
                $relatedDocs[] = (new \Greenter\Model\Sale\Document())
                    ->setTipoDoc($g['tipoDoc'])
                    ->setNroDoc($g['nroDoc']);
            }
            $invoice->setGuias($relatedDocs);
        }

        // Facturas de Anticipo relacionadas
        if (!empty($data->anticipos)) {
            $relatedPrepayments = [];
            foreach ($data->anticipos as $anticipo) {
                // Si el anticipo tiene base reportamos el Descuento Global
                if (isset($anticipo['montoBase'])) {
                    $descuentoGlobal = (new \Greenter\Model\Sale\Charge())
                        ->setCodTipo('04') 
                        ->setFactor(1.00)
                        ->setMontoBase($anticipo['montoBase'])
                        ->setMonto($anticipo['montoBase']);
                    
                    $descuentos = $invoice->getDescuentos() ?? [];
                    $descuentos[] = $descuentoGlobal;
                    $invoice->setDescuentos($descuentos);
                }

                $relatedPrepayments[] = (new \Greenter\Model\Sale\Prepayment())
                    ->setTipoDocRel($anticipo['tipoDocRel'])
                    ->setNroDocRel($anticipo['nroDocRel'])
                    ->setTotal($anticipo['total']);
            }
            $invoice->setAnticipos($relatedPrepayments);
        }

        $details = [];
        foreach ($data->items as $item) {
            $details[] = (new SaleDetail())
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
                (new Legend())
                    ->setCode('1000')
                    ->setValue('SON ' . number_format($data->mtoTotal, 2, '.', '') . ' SOLES')
            ]);

        return $invoice;
    }
}
