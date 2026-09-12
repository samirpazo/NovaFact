<?php

namespace App\DTO;

class FacturaData
{
    public function __construct(
        public string $tipoDoc,
        public ?string $serie,
        public ?string $correlativo,
        public string $fechaEmision,
        public string $tipoMoneda,
        public string $clientTipoDoc,
        public string $clientNumDoc,
        public string $clientRznSocial,
        public float $mtoOperGravada,
        public float $mtoIGV,
        public float $mtoTotal,
        public array $items, // Array de FacturaItemData
        public ?string $observaciones = null,
        public array $guias = [],
        public array $anticipos = [],
        public float $mtoTotalAnticipos = 0.0,
        public float $sumDsctoGlobal = 0.0
        ,public ?string $idempotencyKey = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            tipoDoc: $data['tipoDoc'] ?? '01',
            serie: $data['serie'] ?? null,
            correlativo: $data['correlativo'] ?? null,
            fechaEmision: $data['fechaEmision'],
            tipoMoneda: $data['tipoMoneda'] ?? 'PEN',
            clientTipoDoc: $data['clientTipoDoc'],
            clientNumDoc: $data['clientNumDoc'],
            clientRznSocial: $data['clientRznSocial'],
            mtoOperGravada: (float) $data['mtoOperGravada'],
            mtoIGV: (float) $data['mtoIGV'],
            mtoTotal: (float) $data['mtoTotal'],
            items: $data['items'] ?? [],
            observaciones: $data['observaciones'] ?? null,
            guias: $data['guias'] ?? [],
            anticipos: $data['anticipos'] ?? [],
            mtoTotalAnticipos: (float) ($data['mtoTotalAnticipos'] ?? 0.0),
            sumDsctoGlobal: (float) ($data['sumDsctoGlobal'] ?? 0.0),
            idempotencyKey: $data['_idempotency_key'] ?? null,
        );
    }
}
