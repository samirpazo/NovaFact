<?php

namespace App\Services\Sunat;

use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Despatch\Despatch;
use Greenter\See;
use Greenter\Ws\Reference\CookieReader;
use Greenter\Ws\Reference\CurlReceiver;
use Illuminate\Support\Facades\Storage;

class GreenterService
{
    protected See $see;

    public function __construct(protected
        CertificateService $certificateService, protected
        \App\Repositories\EmpresaRepository $empresaRepository
        )
    {
        $this->see = new See();
    }

    protected function configure(): void
    {
        $empresa = $this->empresaRepository->getActive();

        if (!$empresa) {
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

    public function getEmpresaRepository(): \App\Repositories\EmpresaRepository
    {
        return $this->empresaRepository;
    }

    public function getStatus(?string $ticket): object
    {
        $this->configure();
        return $this->see->getStatus($ticket);
    }

    public function getXml(DocumentInterface $document): string
    {
        $this->configure();
        
        // En el API REST de SUNAT para GRE (2022), el nodo cac:DespatchParty (Remitente) es obligatorio
        // y debe coincidir con el emisor. Greenter no siempre lo inyecta correctamente en sus plantillas,
        // por lo que lo inyectamos manualmente si falta o si es tipo 31.
        if ($document instanceof Despatch) {
            $factory = $this->see->getFactory();
            $options = (new \ReflectionClass($this->see))->getProperty('options');
            $options->setAccessible(true);
            $builderResolver = new \Greenter\Factory\XmlBuilderResolver($options->getValue($this->see));
            $builder = $builderResolver->find(get_class($document));

            // Generar XML sin firmar
            $xmlUnsigned = $builder->build($document);

            // Cargar DOM
            $dom = new \DOMDocument();
            $dom->loadXML($xmlUnsigned);
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
            $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

            // Si ya existe el nodo DespatchParty, no hacemos nada (a menos que sea 31)
            $existingParty = $xpath->query('//cac:Shipment/cac:Delivery/cac:Despatch/cac:DespatchParty');

            if ($existingParty->length === 0 || $document->getTipoDoc() === '31') {
                $despatchNodes = $xpath->query('//cac:Shipment/cac:Delivery/cac:Despatch');
                if ($despatchNodes->length > 0) {
                    $despatchNode = $despatchNodes->item(0);

                    // Si existe un tercero (Remitente), usamos sus datos, sino usamos los de la empresa
                    $partyData = $document->getTercero();
                    $rucRemitente = $partyData ? $partyData->getNumDoc() : $document->getCompany()->getRuc();
                    $razonRemitente = $partyData ? $partyData->getRznSocial() : $document->getCompany()->getRazonSocial();

                    $despatchParty = $dom->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2', 'cac:DespatchParty');
                    $partyId = $dom->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2', 'cac:PartyIdentification');
                    $idElement = $dom->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2', 'cbc:ID', $rucRemitente);
                    $idElement->setAttribute('schemeID', '6');
                    $partyId->appendChild($idElement);
                    $despatchParty->appendChild($partyId);

                    $partyLegalEntity = $dom->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2', 'cac:PartyLegalEntity');
                    $registrationName = $dom->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2', 'cbc:RegistrationName');
                    $cdata = $dom->createCDATASection($razonRemitente ?? '');
                    $registrationName->appendChild($cdata);
                    $partyLegalEntity->appendChild($registrationName);
                    $despatchParty->appendChild($partyLegalEntity);

                    // Si ya existía uno (en el caso de tipo 31 que Greenter a veces pone), lo removemos antes
                    if ($existingParty->length > 0) {
                        $despatchNode->removeChild($existingParty->item(0));
                    }
                    $despatchNode->appendChild($despatchParty);
                }
            }

            // Refirmar con el nuevo DOM
            return $factory->getSigner()->signXml($dom->saveXML());
        }

        return $this->see->getXmlSigned($document) ?? '';
    }
}
