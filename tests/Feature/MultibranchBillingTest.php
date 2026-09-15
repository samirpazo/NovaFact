<?php

use App\Models\McrDocument;
use App\Models\McrEstablishment;
use App\Models\McrSeries;
use App\Services\Documents\AdmitElectronicDocument;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

require_once __DIR__.'/../Support/PipelineDatabase.php';

beforeEach(function () {
    bootPipelineDatabase();
});
afterEach(function () {
    if (isset($this->pipelineSchema)) {
        DB::statement('DROP SCHEMA "'.$this->pipelineSchema.'" CASCADE');
        DB::disconnect('pipeline_test');
    }
});

function secondEstablishment(): McrEstablishment
{
    $company = \App\Models\Empresa::firstOrFail();
    $establishment = McrEstablishment::create([
        'McrCompanyConfigID' => $company->getKey(), 'McrExternalCode' => 'RST-BRANCH-2', 'McrSunatCode' => '0001',
        'McrName' => 'Sucursal B', 'McrAddress' => 'Av. B 456', 'McrUbigeo' => '150122', 'McrDepartment' => 'LIMA',
        'McrProvince' => 'LIMA', 'McrDistrict' => 'MIRAFLORES', 'McrCountryCode' => 'PE', 'McrIsDefault' => false,
        'McrIsActive' => true, 'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
    ]);
    foreach ([['01', 'F002'], ['03', 'B002'], ['07', 'FC02'], ['08', 'FD02'], ['09', 'T002'], ['31', 'V002']] as [$type,$code]) {
        McrSeries::create(['McrCompanyConfigID' => $company->getKey(), 'McrEstablishmentID' => $establishment->getKey(),
            'McrDocumentType' => $type, 'McrSeriesCode' => $code, 'McrNextCorrelative' => 1, 'McrIsActive' => true,
            'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now()]);
    }

    return $establishment;
}

it('resolves an explicit establishment before reserving its series and stores an immutable snapshot', function () {
    secondEstablishment();
    $payload = pipelinePayload();
    $payload['establishment'] = 'RST-BRANCH-2';
    $payload['serie'] = 'F002';
    $result = app(AdmitElectronicDocument::class)->execute(pipelineContext('branch-2'), $payload);
    $document = McrDocument::findOrFail($result->documentId);
    expect($document->McrEstablishmentSnapshot['address'])->toBe('Av. B 456')
        ->and($document->McrEstablishmentSnapshot['sunat_code'])->toBe('0001')
        ->and($document->McrSeriesCode)->toBe('F002');
    McrEstablishment::whereKey($document->McrEstablishmentID)->update(['McrAddress' => 'Av. Nueva 999']);
    expect($document->fresh()->McrEstablishmentSnapshot['address'])->toBe('Av. B 456');
});

it('rejects an establishment or series outside the company branch before consuming a number', function () {
    secondEstablishment();
    $payload = pipelinePayload();
    $payload['establishment'] = 'RST-BRANCH-2';
    $payload['serie'] = 'F001';
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('wrong-series'), $payload))
        ->toThrow(UnprocessableEntityHttpException::class);
    expect((int) McrSeries::where('McrSeriesCode', 'F001')->value('McrNextCorrelative'))->toBe(1);
});

it('keeps idempotency scope and conflicts when the same key changes establishment', function () {
    secondEstablishment();
    app(AdmitElectronicDocument::class)->execute(pipelineContext('same-operation'), pipelinePayload());
    $changed = pipelinePayload();
    $changed['establishment'] = 'RST-BRANCH-2';
    $changed['serie'] = 'F002';
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('same-operation'), $changed))
        ->toThrow(ConflictHttpException::class);
    expect(McrDocument::count())->toBe(1);
});

it('inherits the original establishment for internal notes and rejects another branch', function () {
    secondEstablishment();
    $original = persistedOriginal();
    $payload = notePayload('07', 'internal', $original->getKey());
    $result = app(AdmitElectronicDocument::class)->execute(pipelineContext('note-same-branch'), $payload);
    expect(McrDocument::findOrFail($result->documentId)->McrEstablishmentID)->toBe($original->McrEstablishmentID);
    $wrong = notePayload('07', 'internal', $original->getKey());
    $wrong['establishment'] = 'RST-BRANCH-2';
    $wrong['serie'] = 'FC02';
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('note-wrong-branch'), $wrong))
        ->toThrow(UnprocessableEntityHttpException::class);
});

it('requires explicit establishment for external notes', function () {
    $payload = notePayload('07', 'external');
    unset($payload['establishment']);
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('external-no-branch'), $payload))
        ->toThrow(UnprocessableEntityHttpException::class);
});

it('rejects inactive or incomplete establishments and inactive series before reserving', function () {
    $branch = secondEstablishment();
    $payload = pipelinePayload();
    $payload['establishment'] = 'RST-BRANCH-2';
    $payload['serie'] = 'F002';
    $branch->update(['McrIsActive' => false]);
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('inactive-branch'), $payload))
        ->toThrow(UnprocessableEntityHttpException::class);
    $branch->update(['McrIsActive' => true, 'McrUbigeo' => '']);
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('incomplete-branch'), $payload))
        ->toThrow(UnprocessableEntityHttpException::class);
    $branch->update(['McrUbigeo' => '150122']);
    McrSeries::where('McrSeriesCode', 'F002')->update(['McrIsActive' => false]);
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('inactive-series'), $payload))
        ->toThrow(UnprocessableEntityHttpException::class);
    expect((int) McrSeries::where('McrSeriesCode', 'F002')->value('McrNextCorrelative'))->toBe(1);
});

it('never resolves the first establishment when no unique active default exists', function () {
    McrEstablishment::where('McrIsDefault', true)->update(['McrIsDefault' => false]);
    secondEstablishment();
    $payload = pipelinePayload();
    unset($payload['establishment']);
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('no-default'), $payload))
        ->toThrow(UnprocessableEntityHttpException::class);
});

it('administers establishments without destructive deletion or cross-company access', function () {
    $companyId = (int) DB::table('McrCompanyConfig')->value('McrCompanyConfigID');
    $headers = ['X-Company-Id' => (string) $companyId];
    $created = $this->withToken('test-token')->withHeaders($headers)
        ->postJson('/api/facturacion/configuracion/establecimientos', [
            'external_code' => 'RST-BRANCH-9', 'sunat_code' => '0009', 'name' => 'Sucursal 9',
            'address' => 'Av. Nueve 9', 'ubigeo' => '150109', 'country_code' => 'PE',
            'is_default' => false, 'is_active' => true,
        ])->assertCreated();
    $id = $created->json('data.id');
    $this->withToken('test-token')->withHeaders($headers)
        ->patchJson('/api/facturacion/configuracion/establecimientos/'.$id, ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);
    $this->withToken('test-token')->withHeaders($headers)
        ->deleteJson('/api/facturacion/configuracion/establecimientos/'.$id)->assertStatus(405);
    $this->withToken('test-token')->withHeaders(['X-Company-Id' => '999999'])
        ->getJson('/api/facturacion/configuracion/establecimientos')->assertNotFound();
});

it('returns a scoped read-only fiscal view without company secrets', function () {
    $companyId = (int) DB::table('McrCompanyConfig')->value('McrCompanyConfigID');
    DB::table('McrCompanyConfig')->where('McrCompanyConfigID', $companyId)->update([
        'McrSolPassword' => 'never-return-sol',
        'McrCertificatePassword' => 'never-return-certificate',
    ]);
    secondEstablishment();

    $response = $this->withToken('test-token')->withHeaders(['X-Company-Id' => (string) $companyId])
        ->getJson('/api/facturacion/configuracion/establecimientos?external_code=RST-BRANCH-1')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.external_code', 'RST-BRANCH-1')
        ->assertJsonPath('data.0.sunat_code', '0000')
        ->assertJsonPath('data.0.series.0.document_type', '01')
        ->assertJsonPath('data.0.series.0.series_code', 'F001')
        ->assertJsonPath('data.0.series.0.next_correlative', 1);

    $body = $response->getContent();
    expect($body)->not->toContain('never-return-sol')
        ->not->toContain('never-return-certificate')
        ->not->toContain('McrSolPassword')
        ->not->toContain('McrCertificatePassword');

    $this->withToken('test-token')->withHeaders(['X-Company-Id' => (string) $companyId])
        ->getJson('/api/facturacion/configuracion/establecimientos?external_code=UNKNOWN')
        ->assertOk()->assertJsonCount(0, 'data');
    $this->withToken('test-token')->withHeaders(['X-Company-Id' => (string) $companyId])
        ->getJson('/api/facturacion/configuracion/establecimientos?default=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_default', true);

    $this->withToken('test-token')->getJson('/api/facturacion/configuracion/empresa')
        ->assertOk()->assertJsonMissingPath('company.series');
    $this->withToken('test-token')->putJson('/api/facturacion/configuracion/empresa/series', [
        'series' => [],
    ])->assertNotFound();
});
