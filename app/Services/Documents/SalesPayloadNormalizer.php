<?php

namespace App\Services\Documents;

use App\DTO\FacturaData;
use App\Enums\DocumentType;
use App\Support\DecimalAmount;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class SalesPayloadNormalizer
{
    /**
     * Canonical request before platform numbering. Missing nullable fields and
     * explicit null are equivalent. Note decimals become fixed-scale strings;
     * object keys are sorted by PayloadCodec and array order remains significant.
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
            'establishment' => isset($payload['establishment']) ? trim((string) $payload['establishment']) : null,
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
            'reference' => isset($payload['reference']) && is_array($payload['reference'])
                ? $this->normalizeReference($payload['reference']) : null,
        ];

        if (DocumentType::tryFrom($data->tipoDoc)?->isNote()) {
            try {
                foreach (['mtoOperGravada', 'mtoIGV', 'mtoTotal'] as $field) {
                    $normalized[$field] = DecimalAmount::normalize($payload[$field], 2);
                }
                foreach ($normalized['items'] as $index => &$item) {
                    $source = $payload['items'][$index];
                    foreach (['mtoBaseIgv', 'igv', 'mtoValorVenta'] as $field) {
                        $item[$field] = DecimalAmount::normalize($source[$field], 2);
                    }
                    foreach (['cantidad', 'mtoValorUnitario', 'mtoPrecioUnitario'] as $field) {
                        $item[$field] = DecimalAmount::normalize($source[$field], 6);
                    }
                }
                unset($item);
            } catch (\InvalidArgumentException $exception) {
                throw new UnprocessableEntityHttpException('Note monetary totals require exact non-negative decimals with at most two places; quantities and unit prices allow six.');
            }
        }

        return array_filter($normalized, static fn (mixed $value): bool => $value !== null);
    }

    private function normalizeReference(array $reference): array
    {
        return array_filter([
            'kind' => $reference['kind'] ?? null,
            'document_id' => isset($reference['document_id']) ? (int) $reference['document_id'] : null,
            'document_type' => $reference['document_type'] ?? null,
            'series' => isset($reference['series']) ? strtoupper((string) $reference['series']) : null,
            'correlative' => isset($reference['correlative']) ? (int) $reference['correlative'] : null,
            'issue_date' => isset($reference['issue_date']) ? substr((string) $reference['issue_date'], 0, 10) : null,
            'currency' => $reference['currency'] ?? null,
            'customer_document_type' => $reference['customer_document_type'] ?? null,
            'customer_document_number' => $reference['customer_document_number'] ?? null,
            'reason_code' => $reference['reason_code'] ?? null,
            'reason' => isset($reference['reason']) ? trim((string) $reference['reason']) : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
