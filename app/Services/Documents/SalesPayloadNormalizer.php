<?php

namespace App\Services\Documents;

use App\DTO\FacturaData;
use Carbon\CarbonImmutable;

final class SalesPayloadNormalizer
{
    /**
     * Canonical request before platform numbering. Missing nullable fields and
     * explicit null are equivalent. Decimal fields become JSON numbers; object
     * keys are sorted by PayloadCodec and array order remains significant.
     */
    public function request(array $payload): array
    {
        unset($payload['correlativo'], $payload['_idempotency_key'], $payload['external_reference']);

        return $this->normalize($payload);
    }

    public function processing(array $payload, string $series, int $correlative, float $defaultIgvRate): array
    {
        $payload = $this->normalize($payload);
        $payload['serie'] = $series;
        $payload['correlativo'] = (string) $correlative;
        $payload['igvRate'] ??= $defaultIgvRate;

        return $payload;
    }

    private function normalize(array $payload): array
    {
        $data = FacturaData::fromArray($payload);
        $normalized = [
            'tipoDoc' => $data->tipoDoc,
            'serie' => $data->serie,
            'fechaEmision' => CarbonImmutable::parse($data->fechaEmision, date_default_timezone_get())->format('c'),
            'tipoMoneda' => $data->tipoMoneda,
            'clientTipoDoc' => $data->clientTipoDoc,
            'clientNumDoc' => $data->clientNumDoc,
            'clientRznSocial' => $data->clientRznSocial,
            'mtoOperGravada' => $data->mtoOperGravada,
            'mtoIGV' => $data->mtoIGV,
            'mtoTotal' => $data->mtoTotal,
            'items' => array_map(fn (array $item): array => [
                'codigo' => $item['codigo'] ?? null,
                'descripcion' => $item['descripcion'],
                'unidad' => $item['unidad'] ?? 'NIU',
                'cantidad' => (float) $item['cantidad'],
                'mtoBaseIgv' => (float) $item['mtoBaseIgv'],
                'igv' => (float) $item['igv'],
                'mtoValorUnitario' => (float) $item['mtoValorUnitario'],
                'mtoValorVenta' => (float) $item['mtoValorVenta'],
                'mtoPrecioUnitario' => (float) $item['mtoPrecioUnitario'],
            ], $data->items),
            'igvRate' => $data->igvRate,
            'observaciones' => $data->observaciones,
            'guias' => $data->guias,
            'anticipos' => $data->anticipos,
            'mtoTotalAnticipos' => $data->mtoTotalAnticipos,
            'sumDsctoGlobal' => $data->sumDsctoGlobal,
        ];

        return array_filter($normalized, static fn (mixed $value): bool => $value !== null);
    }
}
