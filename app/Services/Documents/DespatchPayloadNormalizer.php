<?php

namespace App\Services\Documents;

use App\Enums\DespatchReason;
use App\Enums\DespatchTransportMode;
use App\Enums\DocumentType;
use App\Support\DecimalMeasure;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class DespatchPayloadNormalizer
{
    private const UNITS = ['KGM', 'TNE', 'NIU', 'ZZ'];
    private const ID_TYPES = ['0', '1', '4', '6', '7', 'A'];

    public function request(array $payload): array
    {
        unset($payload['correlativo'], $payload['_idempotency_key'], $payload['external_reference']);
        try {
            $type = DocumentType::from((string) ($payload['tipoDoc'] ?? ''));
            if (! $type->isDespatch()) {
                throw new \ValueError;
            }
            $series = strtoupper(trim((string) ($payload['serie'] ?? '')));
            $expectedPrefix = $type === DocumentType::SenderDespatch ? 'T' : 'V';
            if (! preg_match('/^'.$expectedPrefix.'[A-Z0-9]{3}$/D', $series)) {
                $this->invalid('The configured series format is invalid for this GRE type.');
            }
            $reason = DespatchReason::tryFrom((string) ($payload['traslado']['motivo'] ?? ''));
            $mode = DespatchTransportMode::tryFrom((string) ($payload['traslado']['modalidad'] ?? ''));
            if (! $reason || ! $mode) $this->invalid('Invalid despatch reason or transport mode.');
            $recipient = $this->party($payload['destinatario'] ?? null, 'destinatario');
            $origin = $this->address($payload['traslado']['origen'] ?? null, 'origen');
            $destination = $this->address($payload['traslado']['destino'] ?? null, 'destino');
            $goods = $payload['bienes'] ?? null;
            if (! is_array($goods) || $goods === []) $this->invalid('At least one transported good is required.');
            $goods = array_map(function ($item) {
                if (! is_array($item) || trim((string) ($item['descripcion'] ?? '')) === '') $this->invalid('Every good requires a description.');
                $unit = strtoupper((string) ($item['unidad'] ?? ''));
                if (! in_array($unit, self::UNITS, true)) $this->invalid('Unsupported unit of measure.');
                return ['codigo' => isset($item['codigo']) ? trim((string) $item['codigo']) : null,
                    'descripcion' => trim((string) $item['descripcion']), 'unidad' => $unit,
                    'cantidad' => DecimalMeasure::normalize($item['cantidad'] ?? null)];
            }, array_values($goods));
            $carrier = isset($payload['traslado']['transportista']) ? $this->party($payload['traslado']['transportista'], 'transportista', true) : null;
            $driver = isset($payload['traslado']['conductor']) ? $this->driver($payload['traslado']['conductor']) : null;
            $vehicle = isset($payload['traslado']['vehiculo']) ? $this->vehicle($payload['traslado']['vehiculo']) : null;
            if ($mode === DespatchTransportMode::Public && ! $carrier) $this->invalid('Public transport requires a carrier.');
            if ($mode === DespatchTransportMode::Private && (! $driver || ! $vehicle)) $this->invalid('Private transport requires a driver and vehicle.');
            $sender = isset($payload['remitente']) ? $this->party($payload['remitente'], 'remitente') : null;
            if ($type === DocumentType::CarrierDespatch && ! $sender) $this->invalid('GRE transportista requires the sender party.');
            $related = array_map(function ($doc) {
                if (! is_array($doc) || empty($doc['tipo']) || empty($doc['numero'])) $this->invalid('Related documents require type and number.');
                return ['tipo' => (string) $doc['tipo'], 'numero' => trim((string) $doc['numero']),
                    'emisor' => isset($doc['emisor']) ? trim((string) $doc['emisor']) : null,
                    'descripcion' => isset($doc['descripcion']) ? trim((string) $doc['descripcion']) : null];
            }, array_values($payload['documentos_relacionados'] ?? []));
            return array_filter([
                'tipoDoc' => $type->value, 'serie' => $series,
                'fechaEmision' => CarbonImmutable::parse($payload['fechaEmision'] ?? null, date_default_timezone_get())->format('c'),
                'destinatario' => $recipient, 'remitente' => $sender,
                'traslado' => array_filter(['motivo' => $reason->value,
                    'descripcion_motivo' => isset($payload['traslado']['descripcion_motivo']) ? trim((string) $payload['traslado']['descripcion_motivo']) : null,
                    'modalidad' => $mode->value,
                    'fecha_inicio' => CarbonImmutable::parse($payload['traslado']['fecha_inicio'] ?? null)->format('Y-m-d'),
                    'peso_bruto' => DecimalMeasure::normalize($payload['traslado']['peso_bruto'] ?? null, 3),
                    'unidad_peso' => strtoupper((string) ($payload['traslado']['unidad_peso'] ?? 'KGM')),
                    'bultos' => isset($payload['traslado']['bultos']) ? filter_var($payload['traslado']['bultos'], FILTER_VALIDATE_INT) : null,
                    'origen' => $origin, 'destino' => $destination, 'transportista' => $carrier,
                    'conductor' => $driver, 'vehiculo' => $vehicle], fn ($v) => $v !== null),
                'bienes' => $goods, 'documentos_relacionados' => $related,
            ], fn ($v) => $v !== null);
        } catch (UnprocessableEntityHttpException $e) {
            throw $e;
        } catch (\Throwable) {
            $this->invalid('Invalid GRE payload. Dates and exact decimal measures are required.');
        }
    }

    public function processing(array $payload, string $series, int $correlative): array
    {
        $payload['serie'] = $series;
        $payload['correlativo'] = (string) $correlative;
        return $payload;
    }

    private function party(mixed $party, string $field, bool $mtc = false): array
    {
        if (! is_array($party) || ! in_array((string) ($party['tipo_documento'] ?? ''), self::ID_TYPES, true)
            || trim((string) ($party['numero_documento'] ?? '')) === '' || trim((string) ($party['razon_social'] ?? '')) === '') {
            $this->invalid("{$field} requires valid identity and name.");
        }
        if ($mtc && isset($party['registro_mtc']) && trim((string) $party['registro_mtc']) === '') $this->invalid('registro_mtc cannot be blank.');
        return array_filter(['tipo_documento' => (string) $party['tipo_documento'], 'numero_documento' => trim((string) $party['numero_documento']),
            'razon_social' => trim((string) $party['razon_social']), 'registro_mtc' => isset($party['registro_mtc']) ? trim((string) $party['registro_mtc']) : null], fn ($v) => $v !== null);
    }

    private function address(mixed $address, string $field): array
    {
        if (! is_array($address) || ! preg_match('/^[0-9]{6}$/D', (string) ($address['ubigeo'] ?? '')) || trim((string) ($address['direccion'] ?? '')) === '') $this->invalid("{$field} requires ubigeo and address.");
        return array_filter(['ubigeo' => (string) $address['ubigeo'], 'direccion' => trim((string) $address['direccion']),
            'codigo_establecimiento' => isset($address['codigo_establecimiento']) ? (string) $address['codigo_establecimiento'] : null,
            'ruc' => isset($address['ruc']) ? (string) $address['ruc'] : null], fn ($v) => $v !== null);
    }

    private function driver(mixed $driver): array
    {
        if (! is_array($driver) || empty($driver['tipo_documento']) || empty($driver['numero_documento']) || empty($driver['nombres']) || empty($driver['apellidos']) || empty($driver['licencia'])) $this->invalid('Driver identity, name and licence are required.');
        return ['tipo_documento'=>(string)$driver['tipo_documento'],'numero_documento'=>trim((string)$driver['numero_documento']),
            'nombres'=>trim((string)$driver['nombres']),'apellidos'=>trim((string)$driver['apellidos']),'licencia'=>trim((string)$driver['licencia'])];
    }

    private function vehicle(mixed $vehicle): array
    {
        if (! is_array($vehicle) || ! preg_match('/^[A-Z0-9-]{5,10}$/D', strtoupper((string) ($vehicle['placa'] ?? '')))) $this->invalid('A valid vehicle plate is required.');
        return ['placa' => strtoupper((string) $vehicle['placa'])];
    }

    private function invalid(string $message): never { throw new UnprocessableEntityHttpException($message); }
}
