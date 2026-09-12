<?php

namespace App\Services\Facturacion;

use App\DTO\GuiaData;
use App\Services\Sunat\GreenterService;
use App\Services\Sunat\XmlService;
use Greenter\Model\Despatch\Despatch;
use Greenter\Model\Despatch\DespatchDetail;
use Greenter\Model\Despatch\Direction;
use Greenter\Model\Despatch\Shipment;
use Greenter\Model\Despatch\Transportist;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Despatch\Vehicle;
use Greenter\Model\Despatch\Driver;
use Greenter\Model\Despatch\AdditionalDoc;
use Greenter\Model\Response\BillResult;
use App\Repositories\EmpresaRepository;

class GuiaService
{
    public function __construct(
        protected GreenterService $greenterService,
        protected XmlService $xmlService,
        protected EmpresaRepository $empresaRepository,
        protected \App\Services\Sunat\SunatRestService $sunatRestService
    ) {}

    public function emitir(GuiaData $data): array
    {
        $despatch = $this->mapToDespatch($data);
        
        // Guardar XML antes de enviar (Siguiendo la misma lógica que FacturaService)
        $xmlSigned = $this->greenterService->getXml($despatch);
        $this->xmlService->save($xmlSigned, $despatch->getName().'.xml');

        // En GRE (Guías), usamos el servicio REST (Greenter-API) para mayor estabilidad
        $result = $this->sunatRestService->send($despatch);

        // Si el envío fue exitoso y recibimos CDR, lo guardamos
        if ($result['success'] && !empty($result['cdr_base64'])) {
            $this->xmlService->saveCdr(base64_decode($result['cdr_base64']), 'R-'.$despatch->getName().'.zip');
        }

        return $result;
    }

    protected function mapToDespatch(GuiaData $data): Despatch
    {
        $rel = (new Client())
            ->setTipoDoc($data->destinatarioTipoDoc)
            ->setNumDoc($data->destinatarioNumDoc)
            ->setRznSocial($data->destinatarioRznSocial);

        $shipment = (new Shipment())
            ->setModTraslado($data->modalidadTraslado)
            ->setCodTraslado($data->motivoTraslado)
            ->setFecTraslado(new \DateTime($data->fechaTraslado))
            ->setPesoTotal(floatval($data->pesoTotal))
            ->setUndPesoTotal($data->unidadMedida)
            ->setPartida(new Direction($data->ubigeoPartida, $data->direccionPartida))
            ->setLlegada(new Direction($data->ubigeoLlegada, $data->direccionLlegada));

        // Modalidad 01: Transporte Público exige Transportista
        if ($data->modalidadTraslado === '01' || $data->transportistaNumDoc) {
             $transportista = (new Transportist())
                ->setTipoDoc($data->transportistaTipoDoc ?? '6')
                ->setNumDoc($data->transportistaNumDoc)
                ->setRznSocial($data->transportistaRznSocial);
                
             if (!empty($data->transportistaNroMtc)) {
                 $transportista->setNroMtc($data->transportistaNroMtc);
             }
             
             $shipment->setTransportista($transportista);
        }

        // En Guía Transportista (31) o Modalidad Privada (02), el vehículo es obligatorio
        if ($data->modalidadTraslado === '02' || $data->tipoDoc === '31') {
             if ($data->placaVehiculo) {
                $shipment->setVehiculo((new Vehicle())
                    ->setPlaca($data->placaVehiculo));
            }
        }


        if ($data->choferNumDoc) {
            $driver = (new Driver())
                ->setTipoDoc($data->choferTipoDoc)
                ->setNroDoc($data->choferNumDoc)
                ->setTipo('Principal');
            
            if ($data->choferNombres) $driver->setNombres($data->choferNombres);
            if ($data->choferApellidos) $driver->setApellidos($data->choferApellidos);
            if ($data->choferLicencia) $driver->setLicencia($data->choferLicencia);
                
            $shipment->setChoferes([$driver]);
        }

        $empresaActual = $this->empresaRepository->getActive();
        $company = (new Company())
            ->setRuc($empresaActual->CpyRuc)
            ->setRazonSocial($empresaActual->CpyBusinessName)
            ->setNombreComercial($empresaActual->CpyTradename)
            ->setAddress((new Address())
                ->setUbigueo($empresaActual->ubigeo)
                ->setDepartamento($empresaActual->departamento)
                ->setProvincia($empresaActual->provincia)
                ->setDistrito($empresaActual->distrito)
                ->setUrbanizacion($empresaActual->urbanizacion)
                ->setDireccion($empresaActual->CpyAddress)
                ->setCodLocal('0000'));

        $despatch = (new Despatch())
            ->setVersion('2022')
            ->setTipoDoc($data->tipoDoc)
            ->setSerie($data->serie)
            ->setCorrelativo($data->correlativo)
            ->setFechaEmision(new \DateTime($data->fechaEmision))
            ->setCompany($company)
            ->setDestinatario($rel)
            ->setEnvio($shipment);

        if ($data->remitenteNumDoc) {
            $remitente = (new Client())
                ->setTipoDoc($data->remitenteTipoDoc)
                ->setNumDoc($data->remitenteNumDoc)
                ->setRznSocial($data->remitenteRznSocial);
            $despatch->setTercero($remitente);
        }

        $details = [];
        foreach ($data->details as $item) {
            $details[] = (new DespatchDetail())
                ->setCodigo($item['codigo'])
                ->setDescripcion($item['descripcion'])
                ->setUnidad($item['unidad'])
                ->setCantidad($item['cantidad']);
        }

        $despatch->setDetails($details);

        if (!empty($data->relatedDocs)) {
            $addDocs = [];
            foreach ($data->relatedDocs as $doc) {
                $addDoc = (new AdditionalDoc())
                    ->setTipo($doc['tipo'])
                    ->setNro($doc['nro'])
                    ->setEmisor($doc['emisor'] ?? $company->getRuc());
                
                if (isset($doc['tipoDesc'])) {
                    $addDoc->setTipoDesc($doc['tipoDesc']);
                }
                $addDocs[] = $addDoc;
            }
            $despatch->setAddDocs($addDocs);
        }

        return $despatch;
    }
}
