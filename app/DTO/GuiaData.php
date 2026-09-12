<?php

namespace App\DTO;

class GuiaData
{
    public function __construct(
        public string $serie,
        public string $correlativo,
        public string $fechaEmision,
        public string $tipoDoc, // '09' para Guía de Remisión Remitente
        public string $destinatarioTipoDoc,
        public string $destinatarioNumDoc,
        public string $destinatarioRznSocial,
        public string $motivoTraslado,
        public string $modalidadTraslado,
        public string $fechaTraslado,
        public string $pesoTotal,
        public string $unidadMedida,
        public string $ubigeoPartida,
        public string $direccionPartida,
        public string $ubigeoLlegada,
        public string $direccionLlegada,
        public ?string $remitenteTipoDoc = null,
        public ?string $remitenteNumDoc = null,
        public ?string $remitenteRznSocial = null,
        public ?string $transportistaTipoDoc = null,
        public ?string $transportistaNumDoc = null,
        public ?string $transportistaRznSocial = null,
        public ?string $transportistaNroMtc = null,
        public ?string $placaVehiculo = null,
        public ?string $choferTipoDoc = null,
        public ?string $choferNumDoc = null,
        public ?string $choferNombres = null,
        public ?string $choferApellidos = null,
        public ?string $choferLicencia = null,
        public array $relatedDocs = [],
        public array $details = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            serie: $data['serie'],
            correlativo: $data['correlativo'],
            fechaEmision: $data['fechaEmision'],
            tipoDoc: $data['tipoDoc'] ?? '09',
            destinatarioTipoDoc: $data['destinatarioTipoDoc'],
            destinatarioNumDoc: $data['destinatarioNumDoc'],
            destinatarioRznSocial: $data['destinatarioRznSocial'],
            motivoTraslado: $data['motivoTraslado'],
            modalidadTraslado: $data['modalidadTraslado'],
            fechaTraslado: $data['fechaTraslado'],
            pesoTotal: $data['pesoTotal'],
            unidadMedida: $data['unidadMedida'] ?? 'KGM',
            ubigeoPartida: $data['ubigeoPartida'],
            direccionPartida: $data['direccionPartida'],
            ubigeoLlegada: $data['ubigeoLlegada'],
            direccionLlegada: $data['direccionLlegada'],
            remitenteTipoDoc: $data['remitenteTipoDoc'] ?? null,
            remitenteNumDoc: $data['remitenteNumDoc'] ?? null,
            remitenteRznSocial: $data['remitenteRznSocial'] ?? null,
            transportistaTipoDoc: $data['transportistaTipoDoc'] ?? null,
            transportistaNumDoc: $data['transportistaNumDoc'] ?? null,
            transportistaRznSocial: $data['transportistaRznSocial'] ?? null,
            transportistaNroMtc: $data['transportistaNroMtc'] ?? null,
            placaVehiculo: $data['placaVehiculo'] ?? null,
            choferTipoDoc: $data['choferTipoDoc'] ?? null,
            choferNumDoc: $data['choferNumDoc'] ?? null,
            choferNombres: $data['choferNombres'] ?? null,
            choferApellidos: $data['choferApellidos'] ?? null,
            choferLicencia: $data['choferLicencia'] ?? null,
            relatedDocs: $data['relatedDocs'] ?? [],
            details: $data['details'] ?? ($data['bienes'] ?? [])
        );
    }
}
