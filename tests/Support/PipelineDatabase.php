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
    // Existing application depends on this external table. Minimal fixture only,
    // not a production migration pretending the service is already independent.
    Schema::create('GenFile', function (Blueprint $table): void {
        $table->increments('FilID');
        foreach (['FilOriginalName', 'FilStoredName', 'FilRouteParameter', 'FilExtension', 'FilMimeType', 'FilSha256', 'SyncId'] as $column) {
            $table->string($column)->nullable();
        }
        $table->binary('SyncVersion')->nullable();
        foreach (['FilSizeBytes', 'FilUploadStatus', 'FilPreviewStatus', 'CreateUserId'] as $column) {
            $table->integer($column)->nullable();
        }
        $table->boolean('SecStatus')->nullable();
        $table->timestamp('CreateDate')->nullable();
    });
    foreach (glob(database_path('migrations/*.php')) as $file) {
        if (DB::getDriverName() === 'sqlite' && str_contains($file, 'change_document_idempotency_to_string')) {
            continue; // PostgreSQL-specific historical migration, tested in the PG run.
        }
        (require $file)->up();
    }
    config(['sunat.production' => false, 'services.billing.token' => 'test-token']);
    \App\Models\Empresa::create([
        'McrRuc' => '20123456789', 'McrBusinessName' => 'Pipeline fixture', 'McrEnvironment' => 'beta',
        'McrIsActive' => true, 'SecStatus' => true, 'McrIgvRate' => 18,
    ]);
    foreach (['01' => 'F001', '03' => 'B001'] as $type => $series) {
        \App\Models\McrSeries::create([
            'McrCompanyConfigID' => \App\Models\Empresa::firstOrFail()->getKey(),
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
        'tipoDoc' => $type, 'serie' => $type === '01' ? 'F001' : 'B001', 'fechaEmision' => '2026-09-13', 'tipoMoneda' => 'PEN',
        'clientTipoDoc' => $type === '01' ? '6' : '1',
        'clientNumDoc' => $type === '01' ? '20123456789' : '12345678', 'clientRznSocial' => 'Test client',
        'mtoOperGravada' => 100, 'mtoIGV' => 18, 'mtoTotal' => 118,
        'items' => [['descripcion' => 'Test item', 'cantidad' => 1, 'mtoBaseIgv' => 100,
            'igv' => 18, 'mtoValorUnitario' => 100, 'mtoPrecioUnitario' => 118, 'mtoValorVenta' => 100]],
    ];
}
