<?php

namespace App\Services\Facturacion;

use App\DTO\DespatchData;
use App\Models\Empresa;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Despatch\AdditionalDoc;
use Greenter\Model\Despatch\Despatch;
use Greenter\Model\Despatch\DespatchDetail;
use Greenter\Model\Despatch\Direction;
use Greenter\Model\Despatch\Driver;
use Greenter\Model\Despatch\Shipment;
use Greenter\Model\Despatch\Transportist;
use Greenter\Model\Despatch\Vehicle;

final class DespatchService
{
    public function map(Empresa $issuer, DespatchData $dto): Despatch
    {
        $d=$dto->data; $s=$dto->shipment();
        $shipment=(new Shipment)->setCodTraslado($s['motivo'])->setDesTraslado($s['descripcion_motivo'] ?? null)
            ->setModTraslado($s['modalidad'])->setFecTraslado(new \DateTime($s['fecha_inicio']))
            ->setPesoTotal((float)$s['peso_bruto'])->setUndPesoTotal($s['unidad_peso'])->setNumBultos($s['bultos'] ?? null)
            ->setPartida($this->direction($s['origen']))->setLlegada($this->direction($s['destino']));
        if (isset($s['transportista'])) { $p=$s['transportista']; $shipment->setTransportista((new Transportist)->setTipoDoc($p['tipo_documento'])->setNumDoc($p['numero_documento'])->setRznSocial($p['razon_social'])->setNroMtc($p['registro_mtc'] ?? null)); }
        if (isset($s['conductor'])) { $p=$s['conductor']; $shipment->setChoferes([(new Driver)->setTipo('Principal')->setTipoDoc($p['tipo_documento'])->setNroDoc($p['numero_documento'])->setNombres($p['nombres'])->setApellidos($p['apellidos'])->setLicencia($p['licencia'])]); }
        if (isset($s['vehiculo'])) $shipment->setVehiculo((new Vehicle)->setPlaca($s['vehiculo']['placa']));
        $document=(new Despatch)->setVersion('2022')->setTipoDoc($dto->type())->setSerie($dto->series())->setCorrelativo($dto->correlative())
            ->setFechaEmision(new \DateTime($d['fechaEmision']))->setCompany($this->company($issuer))
            ->setDestinatario($this->client($dto->recipient()))->setEnvio($shipment);
        if (isset($d['remitente'])) $document->setTercero($this->client($d['remitente']));
        $document->setDetails(array_map(fn($i)=>(new DespatchDetail)->setCodigo($i['codigo'] ?? null)->setDescripcion($i['descripcion'])->setUnidad($i['unidad'])->setCantidad((float)$i['cantidad']),$dto->items()));
        $document->setAddDocs(array_map(fn($x)=>(new AdditionalDoc)->setTipo($x['tipo'])->setNro($x['numero'])->setEmisor($x['emisor'] ?? null)->setTipoDesc($x['descripcion'] ?? null),$d['documentos_relacionados'] ?? []));
        return $document;
    }
    private function client(array $p): Client { return (new Client)->setTipoDoc($p['tipo_documento'])->setNumDoc($p['numero_documento'])->setRznSocial($p['razon_social']); }
    private function direction(array $p): Direction { return (new Direction($p['ubigeo'],$p['direccion']))->setCodLocal($p['codigo_establecimiento']??null)->setRuc($p['ruc']??null); }
    private function company(Empresa $e): Company { return (new Company)->setRuc($e->McrRuc)->setRazonSocial($e->McrBusinessName)->setNombreComercial($e->McrTradeName)->setAddress((new Address)->setUbigueo($e->McrUbigeo)->setDireccion($e->McrAddress)->setCodLocal('0000')); }
}
