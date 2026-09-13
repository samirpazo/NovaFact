<?php

namespace App\Services\Sunat;

use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\BillResult;
use Greenter\See;

class GreenterService
{
    protected See $see;

    public function __construct(protected CertificateService $certificateService, protected \App\Repositories\EmpresaRepository $empresaRepository
    ) {
        $this->see = new See;
    }

    protected function configure(?\App\Models\Empresa $company = null): void
    {
        $empresa = $company ?? $this->empresaRepository->getActive();

        if (! $empresa) {
            return;
        }

        $cert = $this->certificateService->getCertificate($empresa->CpyNameCertificate, $empresa->CpyPasswordCertificate);

        if ($cert) {
            $this->see->setCertificate($cert);
        }

        $this->see->setClaveSOL($empresa->CpyRuc, $empresa->CpyUserSol, $empresa->CpyPasswordSol);
        $this->see->setService(config('sunat.endpoints.soap'));
    }

    public function send(DocumentInterface $document): object
    {
        // Re-configurar antes de enviar por si la empresa activa cambió en el mismo request
        $this->configure();

        $this->see->setService(config('sunat.endpoints.soap'));

        return $this->see->send($document);
    }

    public function sendSignedXml(string $type, string $name, string $xml, \App\Models\Empresa $company): BillResult
    {
        $this->configure($company);
        $result = $this->see->sendXml($type, $name, $xml);
        if (! $result instanceof BillResult) {
            throw new \RuntimeException('Unexpected SUNAT response type.');
        }

        return $result;
    }

    public function getEmpresaRepository(): \App\Repositories\EmpresaRepository
    {
        return $this->empresaRepository;
    }

    public function getStatus(?string $ticket): object
    {
        $this->configure();

        return $this->see->getStatus($ticket);
    }

    public function getXml(DocumentInterface $document, ?\App\Models\Empresa $company = null): string
    {
        $this->configure($company);

        return $this->see->getXmlSigned($document) ?? '';
    }
}
