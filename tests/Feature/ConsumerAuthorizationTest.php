<?php

use App\Models\Empresa;
use App\Models\McrApiClient;
use App\Models\McrSunatSubmission;
use App\Models\McrWebhookSubscription;
use App\Services\Auth\ApiCredentialService;
use App\Services\Documents\AdmitElectronicDocument;
use App\Services\Sunat\CertificateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/PipelineDatabase.php';

beforeEach(function () {
    bootPipelineDatabase();
    Http::preventStrayRequests();
    Storage::fake('local');
    $this->companyId = (int) Empresa::firstOrFail()->getKey();
    $this->clientId = (int) McrApiClient::where('McrCode', 'legacy')->firstOrFail()->getKey();
    $this->otherClient = McrApiClient::create(['McrCode' => 'consumer-b', 'McrName' => 'Consumer B', 'McrIsActive' => true, 'SecStatus' => true]);
    $this->otherCompany = Empresa::create([
        'McrRuc' => '20555555551', 'McrBusinessName' => 'Company B', 'McrEnvironment' => 'beta',
        'McrIsActive' => true, 'SecStatus' => true, 'McrIgvRate' => 18,
    ]);
});

afterEach(function () {
    if (isset($this->pipelineSchema)) {
        DB::statement('DROP SCHEMA "'.$this->pipelineSchema.'" CASCADE');
        DB::disconnect('pipeline_test');
    }
});

function consumerCredential(int $clientId, int $companyId, array $permissions = ['read', 'emit', 'admin']): array
{
    return app(ApiCredentialService::class)->issue($clientId, [$companyId], $permissions);
}

it('rejects the shared configuration token unless backed by a credential', function () {
    config(['services.billing.token' => 'unregistered-shared-secret']);
    $this->withToken('unregistered-shared-secret')->getJson('/api/facturacion/configuracion/empresa')->assertUnauthorized();
});

it('infers the consumer from its credential when admitting documents', function () {
    $issued = consumerCredential($this->otherClient->getKey(), $this->companyId);
    $this->withToken($issued['token'])->withHeader('X-Company-Id', $this->companyId)
        ->withHeader('Idempotency-Key', 'consumer-admission-001')
        ->postJson('/api/facturacion/emitir-factura', pipelinePayload())->assertStatus(202);
    expect((int) DB::table('McrDocument')->value('McrApiClientID'))->toBe($this->otherClient->getKey());
});

it('rejects unauthorized company selection before validation or fiscal access', function () {
    $issued = consumerCredential($this->clientId, $this->companyId);
    $this->withToken($issued['token'])->withHeader('X-Company-Id', $this->otherCompany->getKey())
        ->postJson('/api/facturacion/emitir-factura', [])->assertForbidden();
    expect(DB::table('McrDocument')->count())->toBe(0);
});

it('rejects consumer impersonation through either legacy header', function (string $header, string $value) {
    $issued = consumerCredential($this->clientId, $this->companyId);
    $value = $header === 'X-Api-Client-Id' ? (string) $this->otherClient->getKey() : $value;
    $this->withToken($issued['token'])->withHeader('X-Company-Id', $this->companyId)->withHeader($header, $value)
        ->postJson('/api/facturacion/emitir-factura', pipelinePayload())->assertForbidden();
})->with([['X-Client-Code', 'consumer-b'], ['X-Api-Client-Id', 'other-id']]);

it('does not expose another consumer submission ticket or artifact', function () {
    $operation = app(AdmitElectronicDocument::class)->execute(pipelineContext('foreign-consumer-001'), pipelinePayload())->toArray();
    $submission = McrSunatSubmission::findOrFail($operation['submission_id']);
    $submission->update(['McrTicket' => 'foreign-ticket']);
    $submission->document->update(['McrPdfPath' => 'facturacion/pdf/foreign.pdf']);
    Storage::disk('local')->put('facturacion/pdf/foreign.pdf', 'private artifact');
    $issued = consumerCredential($this->otherClient->getKey(), $this->companyId);
    $this->withToken($issued['token'])->withHeader('X-Company-Id', $this->companyId);
    $this->getJson('/api/facturacion/submissions/'.$submission->getKey())->assertNotFound();
    $this->getJson('/api/facturacion/historial-guia/foreign-ticket')->assertNotFound();
    $this->getJson('/api/facturacion/archivo/pdf/foreign.pdf')->assertNotFound();
});

it('does not expose resources belonging to an ungranted company of the same consumer', function () {
    $operation = app(AdmitElectronicDocument::class)->execute(pipelineContext('foreign-company-001'), pipelinePayload())->toArray();
    $submission = McrSunatSubmission::findOrFail($operation['submission_id']);
    $submission->document->update(['McrCompanyConfigID' => $this->otherCompany->getKey(), 'McrEstablishmentID' => null, 'McrPdfPath' => 'facturacion/pdf/company-b.pdf']);
    $submission->update(['McrTicket' => 'company-b-ticket']);
    Storage::disk('local')->put('facturacion/pdf/company-b.pdf', 'private artifact');
    $issued = consumerCredential($this->clientId, $this->companyId);
    $this->withToken($issued['token'])->withHeader('X-Company-Id', $this->companyId);
    $this->getJson('/api/facturacion/submissions/'.$submission->getKey())->assertNotFound();
    $this->getJson('/api/facturacion/historial-guia/company-b-ticket')->assertNotFound();
    $this->getJson('/api/facturacion/archivo/pdf/company-b.pdf')->assertNotFound();
});

it('does not serve orphaned files through the legacy storage fallback', function () {
    Storage::disk('local')->put('facturacion/pdf/orphan.pdf', 'unowned file');
    $issued = consumerCredential($this->clientId, $this->companyId, ['read']);
    $this->withToken($issued['token'])->withHeader('X-Company-Id', $this->companyId)
        ->getJson('/api/facturacion/archivo/pdf/orphan.pdf')->assertNotFound();
});

it('allows reading owned submissions with a read credential', function () {
    $operation = app(AdmitElectronicDocument::class)->execute(pipelineContext('owned-read-001'), pipelinePayload())->toArray();
    $issued = consumerCredential($this->clientId, $this->companyId, ['read']);
    $this->withToken($issued['token'])->withHeader('X-Company-Id', $this->companyId)
        ->getJson('/api/facturacion/submissions/'.$operation['submission_id'])->assertOk();
});

it('denies emission and configuration changes to a read-only credential', function (string $method, string $path) {
    $issued = consumerCredential($this->clientId, $this->companyId, ['read']);
    $this->withToken($issued['token'])->withHeader('X-Company-Id', $this->companyId)
        ->json($method, $path, [])->assertForbidden();
})->with([
    ['POST', '/api/facturacion/emitir-factura'],
    ['POST', '/api/facturacion/emitir-guia'],
    ['POST', '/api/facturacion/boletas/baja'],
    ['POST', '/api/facturacion/boletas/resumen-diario'],
    ['PUT', '/api/facturacion/configuracion/empresa'],
    ['PUT', '/api/facturacion/configuracion/empresa/credenciales-sol'],
    ['POST', '/api/facturacion/configuracion/empresa/certificado'],
    ['POST', '/api/webhooks/subscriptions'],
]);

it('rejects revoked and expired credentials', function (string $field) {
    $issued = consumerCredential($this->clientId, $this->companyId);
    $issued['credential']->update([$field => now()->subMinute()]);
    $this->withToken($issued['token'])->withHeader('X-Company-Id', $this->companyId)
        ->getJson('/api/facturacion/configuracion/empresa')->assertUnauthorized();
})->with(['revoked_at', 'expires_at']);

it('stores only the hash of the consumer token', function () {
    $issued = consumerCredential($this->clientId, $this->companyId);
    expect($issued['credential']->token_hash)->toBe(hash('sha256', $issued['token']))
        ->and(json_encode($issued['credential']->getAttributes()))->not->toContain($issued['token']);
});

it('scopes webhook ownership to authenticated consumer instead of request headers', function () {
    $subscription = McrWebhookSubscription::create([
        'McrApiClientID' => $this->otherClient->getKey(), 'McrCompanyConfigID' => $this->companyId,
        'McrUrl' => 'https://example.com/hooks', 'McrIsEnabled' => true,
        'McrEncryptedSecret' => 'fixture', 'McrEventTypes' => ['document.accepted'], 'McrCreatedAt' => now(), 'McrUpdatedAt' => now(),
    ]);
    $issued = consumerCredential($this->clientId, $this->companyId);
    $this->withToken($issued['token'])->withHeader('X-Company-Id', $this->companyId)
        ->getJson('/api/webhooks/subscriptions/'.$subscription->getKey())->assertNotFound();
    $this->getJson('/api/webhooks/subscriptions')->assertOk()->assertJsonCount(0, 'data');
});

it('does not grant read or administration implicitly to an emission credential', function (string $method, string $path) {
    $issued = consumerCredential($this->clientId, $this->companyId, ['emit']);
    $this->withToken($issued['token'])->withHeader('X-Company-Id', $this->companyId)
        ->json($method, $path, [])->assertForbidden();
})->with([
    ['GET', '/api/facturacion/submissions/99999'],
    ['GET', '/api/facturacion/archivo/pdf/missing.pdf'],
    ['PUT', '/api/facturacion/configuracion/empresa'],
]);

it('serves an owned artifact to an authorized reader', function () {
    $document = persistedOriginal();
    $document->update(['McrPdfPath' => 'facturacion/pdf/owned.pdf']);
    Storage::disk('local')->put('facturacion/pdf/owned.pdf', 'owned artifact');
    $issued = consumerCredential($this->clientId, $this->companyId, ['read']);
    $this->withToken($issued['token'])->withHeader('X-Company-Id', $this->companyId)
        ->get('/api/facturacion/archivo/pdf/owned.pdf')->assertOk()->assertDownload('owned.pdf');
});

it('keeps company RUC immutable after document admission and returns fresh configuration', function () {
    persistedOriginal('01');
    $data = ['business_name' => 'NovaFact Updated', 'ruc' => '20555555551', 'currency_code' => 'PEN', 'igv_rate' => 16, 'ipm_rate' => 2, 'special_tax_regime' => false];
    $this->withToken('test-token')->putJson('/api/facturacion/configuracion/empresa', $data)->assertStatus(409);
    $data['ruc'] = '20123456789';
    $this->withToken('test-token')->putJson('/api/facturacion/configuracion/empresa', $data)->assertOk()->assertJsonPath('company.business_name', 'NovaFact Updated');
});

it('rejects an incorrect signing certificate password without replacing the current certificate', function () {
    $company = Empresa::findOrFail($this->companyId);
    $company->update(['McrCertificateName' => 'existing.pfx']);
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => 'NovaFact Test'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 1);
    openssl_pkcs12_export($cert, $pfx, $key, 'correct-password');
    $path = tempnam(sys_get_temp_dir(), 'novafact-upload-');
    file_put_contents($path, $pfx);
    try {
        $file = new UploadedFile($path, 'demo.pfx', 'application/x-pkcs12', null, true);
        $this->withToken('test-token')->postJson('/api/facturacion/configuracion/empresa/certificado', ['certificate' => $file, 'certificate_password' => 'wrong-password'])
            ->assertStatus(422)->assertJsonPath('errors.certificate.0', 'Certificate or password is invalid.');
        expect($company->fresh()->McrCertificateName)->toBe('existing.pfx');
        expect(Storage::disk('local')->allFiles('certificates'))->toBeEmpty();
        $this->withToken('test-token')->postJson('/api/facturacion/configuracion/empresa/certificado', ['certificate' => $file, 'certificate_password' => 'correct-password'])->assertOk();
        expect($company->fresh()->McrCertificateName)->not->toBe('existing.pfx');
        expect(app(CertificateService::class)->getCertificate($company->fresh()->McrCertificateName, 'correct-password'))->toContain('BEGIN CERTIFICATE');
    } finally {
        unlink($path);
    }
});
