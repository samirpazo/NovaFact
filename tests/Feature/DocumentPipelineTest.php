<?php

use App\Enums\DocumentState;
use App\Jobs\ProcessElectronicDocumentJob;
use App\Models\McrDocument;
use App\Services\Documents\AdmitElectronicDocument;
use App\Services\Documents\DocumentLifecycle;
use App\Services\Documents\DocumentProcessorResolver;
use App\Services\Documents\ElectronicDocumentProcessor;
use App\Services\Documents\PayloadCodec;
use App\Services\Documents\ProcessingResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

require_once __DIR__.'/../Support/PipelineDatabase.php';

beforeEach(function () {
    bootPipelineDatabase();
    Http::preventStrayRequests();
});

afterEach(function () {
    if (isset($this->pipelineSchema)) {
        DB::statement('DROP SCHEMA "'.$this->pipelineSchema.'" CASCADE');
        DB::disconnect('pipeline_test');
    }
});

it('persists document payload lines submission and a real database job before returning 202', function (string $type) {
    config(['queue.default' => 'sync']);
    $response = $this->withToken('test-token')->withHeader('Idempotency-Key', 'test-key-0001')
        ->postJson('/api/facturacion/emitir-factura', pipelinePayload($type));
    $response->assertStatus(202)->assertJsonPath('status', 'queued');
    $doc = McrDocument::firstOrFail();
    expect($doc->McrStatus)->toBe('queued')->and($doc->McrDocumentType)->toBe($type);
    expect(DB::table('McrDocumentLine')->count())->toBe(1)
        ->and(DB::table('McrDocumentPayload')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
    $submission = DB::table('McrSunatSubmission')->first();
    expect((int) $submission->McrDocumentID)->toBe($doc->getKey())->and($submission->McrStatus)->toBe('queued');
    $serialized = json_decode(DB::table('jobs')->value('payload'), true)['data']['command'];
    expect($serialized)->toContain('ProcessElectronicDocumentJob')->not->toContain('Test client');
    $snapshot = DB::table('McrDocumentPayload')->first();
    expect(PayloadCodec::hash(json_decode($snapshot->McrPayload, true)))->toBe($snapshot->McrPayloadHash);
})->with(['01', '03']);

it('rolls back the number document and submission if enqueue fails', function () {
    $connection = Mockery::mock();
    $connection->shouldReceive('push')->once()->andThrow(new RuntimeException('queue unavailable'));
    Queue::shouldReceive('connection')->with('documents')->andReturn($connection);
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext(), pipelinePayload()))->toThrow(RuntimeException::class);
    expect(McrDocument::count())->toBe(0)->and(DB::table('McrSunatSubmission')->count())->toBe(0)
        ->and((int) DB::table('McrSeries')->where('McrDocumentType', '01')->value('McrNextCorrelative'))->toBe(1)
        ->and(DB::table('McrDocumentPayload')->count())->toBe(0);
});

it('records terminal results once and never sends again on redelivery', function (DocumentState $state) {
    $operation = app(AdmitElectronicDocument::class)->execute(pipelineContext(), pipelinePayload())->toArray();
    $doc = McrDocument::firstOrFail();
    $processor = Mockery::mock(ElectronicDocumentProcessor::class);
    $processor->shouldReceive('process')->once()->withArgs(fn ($d, $p) => $d->getKey() === $doc->getKey() && $p['correlativo'] === '1')
        ->andReturn(new ProcessingResult($state, $state === DocumentState::Rejected ? '2335' : '0', 'SUNAT fixture'));
    $resolver = Mockery::mock(DocumentProcessorResolver::class);
    $resolver->shouldReceive('resolve')->with('01')->once()->andReturn($processor);
    $job = new ProcessElectronicDocumentJob($doc->getKey(), $operation['submission_id']);
    $job->handle($resolver, app(DocumentLifecycle::class));
    $job->handle($resolver, app(DocumentLifecycle::class));
    expect($doc->fresh()->McrStatus)->toBe($state->value)
        ->and(DB::table('McrSunatAttempt')->count())->toBe(1)
        ->and(DB::table('McrSunatResponse')->count())->toBe(1)
        ->and((int) DB::table('McrSeries')->where('McrDocumentType', '01')->value('McrNextCorrelative'))->toBe(2);
    expect(DB::table('McrSunatAttempt')->value('McrDurationMs'))->not->toBeNull();
})->with([DocumentState::Accepted, DocumentState::AcceptedWithObservations, DocumentState::Rejected]);

it('records a technical exception as failed without exposing exception secrets', function () {
    $operation = app(AdmitElectronicDocument::class)->execute(pipelineContext(), pipelinePayload())->toArray();
    $doc = McrDocument::firstOrFail();
    $resolver = Mockery::mock(DocumentProcessorResolver::class);
    $resolver->shouldReceive('resolve')->andThrow(new RuntimeException('access_token=do-not-persist'));
    (new ProcessElectronicDocumentJob($doc->getKey(), $operation['submission_id']))->handle($resolver, app(DocumentLifecycle::class));
    expect($doc->fresh()->McrStatus)->toBe('retry_pending')
        ->and(DB::table('McrSunatSubmission')->value('McrError'))->not->toContain('do-not-persist');
});

it('refuses payload tampering before contacting the processor', function () {
    $operation = app(AdmitElectronicDocument::class)->execute(pipelineContext(), pipelinePayload())->toArray();
    $doc = McrDocument::firstOrFail();
    DB::table('McrDocumentPayload')->update(['McrPayload' => json_encode(['tampered' => true])]);
    $resolver = Mockery::mock(DocumentProcessorResolver::class);
    $resolver->shouldNotReceive('resolve');
    (new ProcessElectronicDocumentJob($doc->getKey(), $operation['submission_id']))->handle($resolver, app(DocumentLifecycle::class));
    expect($doc->fresh()->McrStatus)->toBe('manual_review');
});

it('does not claim a document twice while processing', function () {
    $op = app(AdmitElectronicDocument::class)->execute(pipelineContext(), pipelinePayload())->toArray();
    $id = McrDocument::firstOrFail()->getKey();
    $lifecycle = app(DocumentLifecycle::class);
    expect($lifecycle->claim($id, $op['submission_id']))->toBeInt();
    expect($lifecycle->claim($id, $op['submission_id']))->toBeNull();
});

it('does not allow a submission belonging to another document', function () {
    app(AdmitElectronicDocument::class)->execute(pipelineContext('test-key-0001'), pipelinePayload());
    $second = app(AdmitElectronicDocument::class)->execute(pipelineContext('test-key-0002'), pipelinePayload())->toArray();
    expect(fn () => app(DocumentLifecycle::class)->claim(McrDocument::firstOrFail()->getKey(), $second['submission_id']))->toThrow(LogicException::class);
    expect(DB::table('McrSunatAttempt')->count())->toBe(0);
});

it('marks interrupted workers as failed without resending', function () {
    $op = app(AdmitElectronicDocument::class)->execute(pipelineContext(), pipelinePayload())->toArray();
    $id = McrDocument::firstOrFail()->getKey();
    app(DocumentLifecycle::class)->claim($id, $op['submission_id']);
    (new ProcessElectronicDocumentJob($id, $op['submission_id']))->failed(new RuntimeException('timeout'));
    expect(McrDocument::find($id)->McrStatus)->toBe('retry_pending')
        ->and(DB::table('McrSunatAttempt')->value('McrStatus'))->toBe('retry_pending');
});

it('runs the actual resolver processor mapper and persisted signed transport with no real SUNAT calls', function (string $type) {
    \Illuminate\Support\Facades\Storage::fake('local');
    $operation = app(AdmitElectronicDocument::class)->execute(pipelineContext(), pipelinePayload($type))->toArray();
    $doc = McrDocument::firstOrFail();
    $xml = '<signed-fixture>exact bytes</signed-fixture>';
    $greenter = Mockery::mock(\App\Services\Sunat\GreenterService::class);
    $greenter->shouldReceive('getXml')->once()->withArgs(fn ($invoice, $company) => $invoice->getTipoDoc() === $type && $company->getKey() === $doc->McrCompanyConfigID)->andReturn($xml);
    $greenter->shouldReceive('sendSignedXml')->once()->withArgs(function ($class, $name, $bytes, $company) use ($xml, $doc) {
        $path = $doc->fresh()->McrXmlPath;

        return $bytes === $xml && \Illuminate\Support\Facades\Storage::disk('local')->get($path) === $xml;
    })->andReturn((new \Greenter\Model\Response\BillResult)->setSuccess(true)
        ->setCdrZip('cdr-fixture')->setCdrResponse((new \Greenter\Model\Response\CdrResponse)->setCode('0')->setDescription('Accepted')));
    app()->instance(\App\Services\Sunat\GreenterService::class, $greenter);
    $pdf = Mockery::mock(\App\Services\Facturacion\InvoicePdfService::class);
    $pdf->shouldReceive('generate')->once()->andReturnUsing(function ($invoice, $filename) {
        $path = 'facturacion/pdf/'.$filename;
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, 'pdf-fixture');

        return ['path' => $path];
    });
    app()->instance(\App\Services\Facturacion\InvoicePdfService::class, $pdf);
    (new ProcessElectronicDocumentJob($doc->getKey(), $operation['submission_id']))->handle(app(DocumentProcessorResolver::class), app(DocumentLifecycle::class));
    $doc->refresh();
    expect($doc->McrStatus)->toBe('accepted')->and($doc->McrXmlFilID)->not->toBeNull()
        ->and($doc->McrCdrFilID)->not->toBeNull()->and($doc->McrPdfFilID)->not->toBeNull();
    $this->withToken('test-token')->get('/api/facturacion/archivo/xml/'.basename($doc->McrXmlPath))->assertOk();
    $this->withToken('test-token')->getJson('/api/facturacion/submissions/'.$operation['submission_id'])->assertOk()
        ->assertJsonPath('status', 'accepted')->assertJsonPath('state_version', 3);
})->with(['01', '03']);

it('preserves the fiscal result when PDF generation fails after SUNAT acceptance', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    $operation = app(AdmitElectronicDocument::class)->execute(pipelineContext(), pipelinePayload())->toArray();
    $doc = McrDocument::firstOrFail();
    $greenter = Mockery::mock(\App\Services\Sunat\GreenterService::class);
    $greenter->shouldReceive('getXml')->once()->andReturn('<signed/>');
    $greenter->shouldReceive('sendSignedXml')->once()->andReturn((new \Greenter\Model\Response\BillResult)->setSuccess(true)
        ->setCdrZip('cdr-fixture')->setCdrResponse((new \Greenter\Model\Response\CdrResponse)->setCode('0')));
    app()->instance(\App\Services\Sunat\GreenterService::class, $greenter);
    $pdf = Mockery::mock(\App\Services\Facturacion\InvoicePdfService::class);
    $pdf->shouldReceive('generate')->once()->andThrow(new RuntimeException('pdf rendering unavailable'));
    app()->instance(\App\Services\Facturacion\InvoicePdfService::class, $pdf);
    $job = new ProcessElectronicDocumentJob($doc->getKey(), $operation['submission_id']);
    $job->handle(app(DocumentProcessorResolver::class), app(DocumentLifecycle::class));
    $job->handle(app(DocumentProcessorResolver::class), app(DocumentLifecycle::class));
    expect($doc->fresh()->McrStatus)->toBe('accepted')->and($doc->fresh()->McrCdrPath)->not->toBeNull();
});

it('recovers the known SUNAT outcome if the worker dies during artifact generation', function () {
    $op = app(AdmitElectronicDocument::class)->execute(pipelineContext(), pipelinePayload())->toArray();
    $doc = McrDocument::firstOrFail();
    app(DocumentLifecycle::class)->claim($doc->getKey(), $op['submission_id']);
    $doc->update(['McrProcessingResult' => json_encode((new ProcessingResult(DocumentState::Accepted, '0'))->toArray())]);
    (new ProcessElectronicDocumentJob($doc->getKey(), $op['submission_id']))->failed(new RuntimeException('timeout during PDF'));
    expect($doc->fresh()->McrStatus)->toBe('accepted')
        ->and(DB::table('McrSunatAttempt')->value('McrStatus'))->toBe('accepted');
});

it('executes the serialized database job through the queue handler', function () {
    $op = app(AdmitElectronicDocument::class)->execute(pipelineContext(), pipelinePayload())->toArray();
    $processor = Mockery::mock(\App\Services\Documents\Processors\InvoiceProcessor::class);
    $processor->shouldReceive('process')->once()->andReturn(new ProcessingResult(DocumentState::Accepted, '0'));
    app()->instance(\App\Services\Documents\Processors\InvoiceProcessor::class, $processor);
    $queued = Queue::connection('documents')->pop();
    expect($queued)->not->toBeNull();
    $queued->fire();
    $queued->delete();
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $op['submission_id'])->value('McrStatus'))->toBe('accepted');
});

it('applies and reverses the pipeline migration on the isolated schema', function () {
    $migration = require database_path('migrations/2026_09_13_000000_add_document_pipeline.php');
    $migration->down();
    expect(\Illuminate\Support\Facades\Schema::hasTable('McrDocumentPayload'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('McrDocument', 'McrProcessingResult'))->toBeFalse();
    $migration->up();
    expect(\Illuminate\Support\Facades\Schema::hasTable('McrSunatAttempt'))->toBeTrue()
        ->and(\App\Models\Empresa::count())->toBe(1);
});

it('has exactly one executable admission and processing path for invoices receipts and notes', function () {
    expect(file_exists(app_path('Jobs/EmitFacturaJob.php')))->toBeFalse()
        ->and(file_exists(app_path('Actions/Facturacion/EmitFacturaAction.php')))->toBeFalse()
        ->and(file_exists(app_path('Services/Documents/LegacyBillingCallback.php')))->toBeFalse()
        ->and(method_exists(\App\Services\Facturacion\FacturaService::class, 'emitir'))->toBeFalse()
        ->and(file_exists(app_path('Services/Facturacion/McrPersistenceService.php')))->toBeFalse()
        ->and(file_exists(app_path('Jobs/EmitCreditNoteJob.php')))->toBeFalse()
        ->and(file_exists(app_path('Jobs/EmitDebitNoteJob.php')))->toBeFalse()
        ->and(file_exists(app_path('Actions/EmitCreditNoteAction.php')))->toBeFalse()
        ->and(file_exists(app_path('Actions/EmitDebitNoteAction.php')))->toBeFalse();

    $creationSites = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())))
        ->filter(fn (SplFileInfo $file) => $file->isFile() && $file->getExtension() === 'php')
        ->filter(fn (SplFileInfo $file) => str_contains(file_get_contents($file->getPathname()), 'McrDocument::create('))
        ->map(fn (SplFileInfo $file) => $file->getPathname())->values()->all();
    $dispatchSites = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())))
        ->filter(fn (SplFileInfo $file) => $file->isFile() && $file->getExtension() === 'php')
        ->filter(fn (SplFileInfo $file) => str_contains(file_get_contents($file->getPathname()), 'new ProcessElectronicDocumentJob('))
        ->map(fn (SplFileInfo $file) => $file->getPathname())->values()->all();
    expect($creationSites)->toBe([app_path('Services/Documents/AdmitElectronicDocument.php')])
        ->and($dispatchSites)->toBe([app_path('Services/Documents/AdmitElectronicDocument.php')]);
});

it('returns the original operation for the same scoped key and canonical payload', function () {
    $admission = app(AdmitElectronicDocument::class);
    $first = $admission->execute(pipelineContext(), pipelinePayload());
    McrDocument::whereKey($first->documentId)->update(['McrStatus' => 'processing']);
    $repeat = $admission->execute(pipelineContext(), [...pipelinePayload(), 'mtoTotal' => '118.00']);

    expect($repeat->documentId)->toBe($first->documentId)
        ->and($repeat->submissionId)->toBe($first->submissionId)
        ->and($repeat->status)->toBe('processing')
        ->and(McrDocument::count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and((int) DB::table('McrSeries')->where('McrDocumentType', '01')->value('McrNextCorrelative'))->toBe(2);
});

it('rejects a reused scoped key with a different payload without consuming a number', function () {
    app(AdmitElectronicDocument::class)->execute(pipelineContext(), pipelinePayload());
    $different = pipelinePayload();
    $different['clientRznSocial'] = 'Different client name';

    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext(), $different))
        ->toThrow(ConflictHttpException::class);
    expect(McrDocument::count())->toBe(1)->and(DB::table('jobs')->count())->toBe(1)
        ->and((int) DB::table('McrSeries')->where('McrDocumentType', '01')->value('McrNextCorrelative'))->toBe(2);
});

it('deduplicates external reference across different keys and rejects incompatible payloads', function () {
    $first = app(AdmitElectronicDocument::class)->execute(pipelineContext('request-key-001', 'RST-SALE-85023'), pipelinePayload());
    $repeat = app(AdmitElectronicDocument::class)->execute(pipelineContext('request-key-002', 'RST-SALE-85023'), pipelinePayload());
    expect($repeat->documentId)->toBe($first->documentId)->and(McrDocument::count())->toBe(1);
    $aliasRepeat = app(AdmitElectronicDocument::class)->execute(pipelineContext('request-key-002'), pipelinePayload());
    expect($aliasRepeat->documentId)->toBe($first->documentId);

    $different = pipelinePayload();
    $different['clientRznSocial'] = 'Incompatible operation';
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('request-key-003', 'RST-SALE-85023'), $different))
        ->toThrow(ConflictHttpException::class);
    expect(DB::table('McrIdempotency')->count())->toBe(2)->and(DB::table('jobs')->count())->toBe(1);
});

it('scopes the same key independently by client and company', function () {
    $clientB = \App\Models\McrApiClient::create([
        'McrCode' => 'client-b', 'McrName' => 'Client B', 'McrIsActive' => true,
        'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
    ]);
    $companyB = \App\Models\Empresa::create([
        'McrRuc' => '20999999991', 'McrBusinessName' => 'Company B', 'McrEnvironment' => 'beta',
        'McrIsActive' => true, 'SecStatus' => true, 'McrIgvRate' => 18,
    ]);
    $establishmentB = \App\Models\McrEstablishment::create([
        'McrCompanyConfigID' => $companyB->getKey(), 'McrExternalCode' => 'RST-BRANCH-1', 'McrSunatCode' => '0000',
        'McrName' => 'Company B main', 'McrAddress' => 'Av. Company B 1', 'McrUbigeo' => '150101',
        'McrCountryCode' => 'PE', 'McrIsDefault' => true, 'McrIsActive' => true, 'SecStatus' => true,
        'CreateUserId' => 0, 'CreateDate' => now(),
    ]);
    \App\Models\McrSeries::create([
        'McrCompanyConfigID' => $companyB->getKey(), 'McrEstablishmentID' => $establishmentB->getKey(),
        'McrDocumentType' => '01', 'McrSeriesCode' => 'F001',
        'McrNextCorrelative' => 1, 'McrIsActive' => true, 'SecStatus' => true,
        'CreateUserId' => 0, 'CreateDate' => now(),
    ]);
    $clientACompanyA = app(AdmitElectronicDocument::class)->execute(pipelineContext('shared-key-001'), pipelinePayload());
    $clientBCompanyA = app(AdmitElectronicDocument::class)->execute(pipelineContext('shared-key-001', clientId: $clientB->getKey()), pipelinePayload());
    $clientACompanyB = app(AdmitElectronicDocument::class)->execute(pipelineContext('shared-key-001', companyId: $companyB->getKey()), pipelinePayload());

    expect(collect([$clientACompanyA->documentId, $clientBCompanyA->documentId, $clientACompanyB->documentId])->unique())->toHaveCount(3)
        ->and(McrDocument::count())->toBe(3)->and(DB::table('McrIdempotency')->count())->toBe(3);
});

it('requires a configured active series and never auto creates one', function () {
    DB::table('McrSeries')->where('McrDocumentType', '01')->update(['McrIsActive' => false]);
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext(), pipelinePayload()))
        ->toThrow(UnprocessableEntityHttpException::class);
    expect(McrDocument::count())->toBe(0)->and(DB::table('McrIdempotency')->count())->toBe(0)
        ->and(DB::table('McrSeries')->count())->toBe(8);
});

it('exposes idempotent reconstruction and conflicts through the legacy HTTP facade', function () {
    $headers = ['Idempotency-Key' => 'http-idempotency-001'];
    $first = $this->withToken('test-token')->withHeaders($headers)
        ->postJson('/api/facturacion/emitir-factura', pipelinePayload())
        ->assertAccepted()->json();
    $repeat = $this->withToken('test-token')->withHeaders($headers)
        ->postJson('/api/facturacion/emitir-factura', [...pipelinePayload(), 'mtoTotal' => '118.00'])
        ->assertAccepted()->json();
    expect($repeat['document_id'])->toBe($first['document_id'])
        ->and($repeat['submission_id'])->toBe($first['submission_id']);

    $different = pipelinePayload();
    $different['clientRznSocial'] = 'HTTP conflict';
    $this->withToken('test-token')->withHeaders($headers)
        ->postJson('/api/facturacion/emitir-factura', $different)
        ->assertConflict();
});

it('requires explicit company resolution when the legacy facade is ambiguous', function () {
    $company = \App\Models\Empresa::create([
        'McrRuc' => '20999999992', 'McrBusinessName' => 'Ambiguous company', 'McrEnvironment' => 'beta',
        'McrIsActive' => true, 'SecStatus' => true,
    ]);
    $this->withToken('test-token')->withHeader('Idempotency-Key', 'company-context-001')
        ->postJson('/api/facturacion/emitir-factura', pipelinePayload())
        ->assertUnprocessable();
    $this->withToken('test-token')->withHeaders([
        'Idempotency-Key' => 'company-context-002',
        'X-Company-Id' => \App\Models\Empresa::where('McrRuc', '20123456789')->value('McrCompanyConfigID'),
    ])->postJson('/api/facturacion/emitir-factura', pipelinePayload())->assertAccepted();
    expect($company->exists)->toBeTrue();
});
