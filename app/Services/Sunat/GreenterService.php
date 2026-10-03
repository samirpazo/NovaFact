<?php

namespace App\Services\Sunat;

use App\Enums\FailureCategory;
use App\Enums\ProcessingCheckpoint;
use App\Exceptions\ClassifiedSubmissionException;
use App\Models\Empresa;
use App\Services\Sunat\Xml\ModernInvoiceBuilder;
use App\Services\Sunat\Xml\CarrierDespatchBuilder;
use Greenter\Model\Despatch\Despatch;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\SummaryResult;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Voided\Voided;
use Greenter\See;

class GreenterService
{
    protected See $see;

    public function __construct(protected CertificateService $certificateService)
    {
        $this->see = new See;
    }

    protected function configure(Empresa $company): void
    {
        // Each company/request gets an independent SOAP security context.
        $this->see = new See;
        $empresa = $company;

        $cert = $this->certificateService->getCertificate($empresa->CpyNameCertificate, $empresa->CpyPasswordCertificate);

        if (! $cert) {
            throw new ClassifiedSubmissionException(FailureCategory::Validation, ProcessingCheckpoint::XmlGenerated, false, false);
        }
        $this->see->setCertificate($cert);

        $this->see->setClaveSOL($empresa->CpyRuc, $empresa->CpyUserSol, $empresa->CpyPasswordSol);
        $this->see->setService(config('sunat.endpoints.soap'));
    }

    public function send(DocumentInterface $document, Empresa $company): object
    {
        // Re-configurar antes de enviar por si la empresa activa cambió en el mismo request
        $this->configure($company);

        $this->see->setService(config('sunat.endpoints.soap'));

        return $this->see->send($document);
    }

    public function sendSignedXml(string $type, string $name, string $xml, Empresa $company): BillResult
    {
        $this->configure($company);
        $result = $this->see->sendXml($type, $name, $xml);
        if (! $result instanceof BillResult) {
            throw new \RuntimeException('Unexpected SUNAT response type.');
        }

        return $result;
    }

    public function sendFiscalXml(string $protocol, string $name, string $xml, Empresa $company): object
    {
        if (! in_array($protocol, ['RC', 'RA'], true)) {
            throw new \InvalidArgumentException('Unsupported fiscal protocol.');
        }
        $this->configure($company);
        $type = $protocol === 'RC' ? Summary::class : Voided::class;
        $result = $this->see->sendXml($type, $name, $xml);
        if (! $result instanceof SummaryResult) {
            throw new \RuntimeException('Unexpected summary response.');
        }

        return $result;
    }

    public function getStatus(?string $ticket, Empresa $company): object
    {
        $this->configure($company);

        return $this->see->getStatus($ticket);
    }

    public function getXml(DocumentInterface $document, Empresa $company): string
    {
        $this->configure($company);

        if ($document instanceof Invoice) {
            return $this->see->getFactory()->setBuilder(new ModernInvoiceBuilder(['autoescape' => false]))->getXmlSigned($document) ?? '';
        }

        if ($document instanceof Despatch && $document->getTipoDoc() === '31') {
            return $this->see->getFactory()->setBuilder(new CarrierDespatchBuilder(['autoescape' => false]))->getXmlSigned($document) ?? '';
        }

        return $this->see->getXmlSigned($document) ?? '';
    }
}
