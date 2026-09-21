<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function bootPipelineDatabase(): void
{
    if (getenv('PIPELINE_TEST_POSTGRES') === '1') {
        // Intentionally hardcoded loopback; never inherit .env database credentials.
        $schema = 'pipeline_test_'.bin2hex(random_bytes(6));
        config(['database.default' => 'pipeline_test', 'database.connections.pipeline_test' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55439,
            'database' => 'postgres', 'username' => 'pipeline_test', 'password' => '',
            'charset' => 'utf8', 'prefix' => '', 'search_path' => $schema, 'sslmode' => 'disable',
        ]]);
        DB::purge('pipeline_test');
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        test()->pipelineSchema = $schema;
    }
    foreach (glob(database_path('migrations/*.php')) as $file) {
        if (DB::getDriverName() === 'sqlite' && str_contains($file, 'change_document_idempotency_to_string')) {
            continue; // PostgreSQL-specific historical migration, tested in the PG run.
        }
        (require $file)->up();
    }
    config(['sunat.production' => false, 'services.billing.token' => 'test-token']);
    \App\Models\McrApiClient::firstOrCreate(['McrCode' => 'legacy'], ['McrName' => 'Legacy client', 'McrIsActive' => true, 'SecStatus' => true]);
    \App\Models\Empresa::create([
        'McrRuc' => '20123456789', 'McrBusinessName' => 'Pipeline fixture', 'McrEnvironment' => 'beta',
        'McrAddress' => 'Av. Fixture 123', 'McrUbigeo' => '150101', 'McrDepartment' => 'LIMA',
        'McrProvince' => 'LIMA', 'McrDistrict' => 'LIMA',
        'McrIsActive' => true, 'SecStatus' => true, 'McrIgvRate' => 18,
    ]);
    $establishment = \App\Models\McrEstablishment::create([
        'McrCompanyConfigID' => \App\Models\Empresa::firstOrFail()->getKey(),
        'McrExternalCode' => 'RST-BRANCH-1', 'McrSunatCode' => '0000',
        'McrName' => 'Sucursal fixture', 'McrAddress' => 'Av. Fixture 123',
        'McrUbigeo' => '150101', 'McrDepartment' => 'LIMA', 'McrProvince' => 'LIMA',
        'McrDistrict' => 'LIMA', 'McrCountryCode' => 'PE', 'McrIsDefault' => true,
        'McrIsActive' => true, 'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
    ]);
    foreach ([['01', 'F001'], ['03', 'B001'], ['07', 'FC01'], ['07', 'BC01'], ['08', 'FD01'], ['08', 'BD01'], ['09', 'T001'], ['31', 'V001']] as [$type, $series]) {
        \App\Models\McrSeries::create([
            'McrCompanyConfigID' => \App\Models\Empresa::firstOrFail()->getKey(),
            'McrEstablishmentID' => $establishment->getKey(),
            'McrDocumentType' => $type,
            'McrSeriesCode' => $series,
            'McrNextCorrelative' => 1,
            'McrIsActive' => true,
            'SecStatus' => true,
            'CreateUserId' => 0,
            'CreateDate' => now(),
        ]);
    }
}

function persistedOriginal(string $type = '01', ?int $companyId = null, string $status = 'accepted'): \App\Models\McrDocument
{
    $companyId ??= \App\Models\Empresa::value('McrCompanyConfigID');
    $correlative = ((int) \App\Models\McrDocument::where('McrCompanyConfigID', $companyId)
        ->where('McrDocumentType', $type)->where('McrSeriesCode', $type === '01' ? 'F001' : 'B001')->max('McrCorrelative')) + 1;
    $correlative = max(200, $correlative);

    return \App\Models\McrDocument::create([
        'McrApiClientID' => \App\Models\McrApiClient::where('McrCode', 'legacy')->value('McrApiClientID'),
        'McrCompanyConfigID' => $companyId,
        'McrEstablishmentID' => \App\Models\McrEstablishment::where('McrCompanyConfigID', $companyId)->value('McrEstablishmentID'),
        'McrEstablishmentSnapshot' => \App\Models\McrEstablishment::where('McrCompanyConfigID', $companyId)->first()?->snapshot(),
        'McrDocumentType' => $type, 'McrSeriesCode' => $type === '01' ? 'F001' : 'B001', 'McrCorrelative' => $correlative,
        'McrIssueDate' => '2026-09-01', 'McrIssuedAt' => '2026-09-01T10:00:00-05:00', 'McrCurrencyCode' => 'PEN',
        'McrCustomerDocumentType' => $type === '01' ? '6' : '1',
        'McrCustomerDocumentNumber' => $type === '01' ? '20123456789' : '12345678',
        'McrCustomerName' => 'Original customer', 'McrTaxableAmount' => 100, 'McrTaxAmount' => 18,
        'McrTotalAmount' => 118, 'McrStatus' => $status, 'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
    ]);
}

function notePayload(string $type = '07', string $kind = 'internal', ?int $documentId = null, string $affectedType = '01'): array
{
    $payload = pipelinePayload($affectedType);
    $payload['tipoDoc'] = $type;
    $family = $affectedType === '01' ? 'F' : 'B';
    $payload['serie'] = $family.($type === '07' ? 'C01' : 'D01');
    $payload['reference'] = [
        'kind' => $kind, 'reason_code' => $type === '07' ? '04' : '02', 'reason' => 'Ajuste probado',
    ];
    if ($kind === 'internal') {
        $payload['reference']['document_id'] = $documentId;
    } else {
        $payload['reference'] += [
            'document_type' => $affectedType, 'series' => $affectedType === '01' ? 'F001' : 'B001',
            'correlative' => 900, 'issue_date' => '2026-08-01', 'currency' => 'PEN',
            'customer_document_type' => $payload['clientTipoDoc'],
            'customer_document_number' => $payload['clientNumDoc'],
        ];
    }

    return $payload;
}

function pipelineContext(string $key = 'test-key-0001', ?string $externalReference = null, ?int $clientId = null, ?int $companyId = null): \App\Services\Documents\AdmissionContext
{
    return new \App\Services\Documents\AdmissionContext(
        $clientId ?? (int) \App\Models\McrApiClient::where('McrCode', 'legacy')->value('McrApiClientID'),
        $companyId ?? (int) \App\Models\Empresa::value('McrCompanyConfigID'),
        $key,
        $externalReference,
    );
}

function pipelinePayload(string $type = '01'): array
{
    return [
        'tipoDoc' => $type, 'establishment' => 'RST-BRANCH-1', 'serie' => $type === '01' ? 'F001' : 'B001', 'fechaEmision' => '2026-09-13', 'tipoMoneda' => 'PEN',
        'clientTipoDoc' => $type === '01' ? '6' : '1',
        'clientNumDoc' => $type === '01' ? '20123456789' : '12345678', 'clientRznSocial' => 'Test client',
        'mtoOperGravada' => 100, 'mtoIGV' => 18, 'mtoTotal' => 118,
        'items' => [['descripcion' => 'Test item', 'cantidad' => 1, 'mtoBaseIgv' => 100,
            'igv' => 18, 'mtoValorUnitario' => 100, 'mtoPrecioUnitario' => 118, 'mtoValorVenta' => 100]],
    ];
}

function despatchPayload(string $type = '09', string $mode = '02'): array
{
    $shipment = [
        'motivo' => '01', 'modalidad' => $mode, 'fecha_inicio' => '2026-09-14', 'peso_bruto' => '125.375', 'unidad_peso' => 'KGM', 'bultos' => 2,
        'origen' => ['ubigeo' => '150101', 'direccion' => 'Av. Origen 123'],
        'destino' => ['ubigeo' => '150122', 'direccion' => 'Av. Destino 456'],
    ];
    if ($mode === '01') {
        $shipment['transportista'] = ['tipo_documento' => '6', 'numero_documento' => '20555555551', 'razon_social' => 'Transportes Demo SAC', 'registro_mtc' => '1512345CNG'];
    } else {
        $shipment['conductor'] = ['tipo_documento' => '1', 'numero_documento' => '12345678', 'nombres' => 'Ana', 'apellidos' => 'Quispe', 'licencia' => 'Q12345678'];
        $shipment['vehiculo'] = ['placa' => 'ABC123'];
    }
    $payload = ['tipoDoc' => $type, 'serie' => $type === '09' ? 'T001' : 'V001', 'fechaEmision' => '2026-09-13T12:00:00-05:00',
        'establishment' => 'RST-BRANCH-1',
        'destinatario' => ['tipo_documento' => '6', 'numero_documento' => '20444444441', 'razon_social' => 'Destinatario SAC'],
        'traslado' => $shipment, 'bienes' => [['codigo' => 'P001', 'descripcion' => 'Producto de prueba', 'unidad' => 'NIU', 'cantidad' => '10.500000']],
        'documentos_relacionados' => [['tipo' => '01', 'numero' => 'F001-123', 'emisor' => '20123456789']]];
    if ($type === '31') {
        $payload['remitente'] = ['tipo_documento' => '6', 'numero_documento' => '20333333331', 'razon_social' => 'Remitente SAC'];
    }

    return $payload;
}
