<?php

use App\Enums\DocumentState;
use App\Jobs\PollFiscalOperationJob;
use App\Jobs\ProcessFiscalOperationJob;
use App\Models\Empresa;
use App\Models\FiscalOperation;
use App\Models\McrApiClient;
use App\Services\Auth\ApiCredentialService;
use App\Services\Documents\AdmitElectronicDocument;
use App\Services\Documents\DocumentLifecycle;
use App\Services\Fiscal\AdmitFiscalOperation;
use App\Services\Fiscal\FiscalOperationProcessor;
use App\Services\Sunat\GreenterService;
use Greenter\Model\Response\CdrResponse;
use Greenter\Model\Response\Error as SunatError;
use Greenter\Model\Response\StatusResult;
use Greenter\Model\Response\SummaryResult;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Voided\Voided;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

require_once __DIR__.'/../Support/PipelineDatabase.php';

beforeEach(function () {
    bootPipelineDatabase();
    Http::preventStrayRequests();
    Storage::fake('local');
    $this->receipt = persistedOriginal('03');
    $this->receipt->update(['McrIssueDate' => now()->toDateString(), 'McrIssuedAt' => now()]);
});

afterEach(function () {
    if (isset($this->pipelineSchema)) {
        DB::statement('DROP SCHEMA "'.$this->pipelineSchema.'" CASCADE');
        DB::disconnect('pipeline_test');
    }
});

function fiscalVoidPayload(int $documentId): array
{
    return ['document_id' => $documentId, 'motivo' => 'Documento no entregado', 'not_delivered' => true];
}

function fiscalTransport(string $protocol = 'RC'): MockInterface
{
    $transport = Mockery::mock(GreenterService::class);
    $transport->shouldReceive('getXml')->once()->withArgs(function ($document, $company) use ($protocol) {
        expect($document)->toBeInstanceOf($protocol === 'RC' ? Summary::class : Voided::class);
        expect($document->getDetails())->not->toBeEmpty();

        return $company->McrEnvironment === 'beta';
    })->andReturn('<?xml version="1.0"?><FiscalFixture><Signature>test</Signature></FiscalFixture>');
    app()->instance(GreenterService::class, $transport);

    return $transport;
}

it('persists a queued operation and job before acknowledging a summary', function () {
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-summary-001'), 'summary', ['fecha' => now()->toDateString()]);
    expect($operation->state)->toBe('queued')->and($operation->protocol)->toBe('RC')
        ->and($operation->correlative)->toBe(1)->and($operation->request_hash)->not->toBeEmpty();
    expect(DB::table('jobs')->count())->toBe(1);
    expect(json_decode(DB::table('jobs')->value('payload'), true)['data']['command'])->toContain('ProcessFiscalOperationJob');
});

it('replays fiscal idempotency without a second number or job', function () {
    $service = app(AdmitFiscalOperation::class);
    $payload = fiscalVoidPayload($this->receipt->getKey());
    $first = $service->execute(pipelineContext('fiscal-idempotent-001'), 'void', $payload);
    $second = $service->execute(pipelineContext('fiscal-idempotent-001'), 'void', $payload);
    expect($second->getKey())->toBe($first->getKey())->and(FiscalOperation::count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
    expect(fn () => $service->execute(pipelineContext('fiscal-idempotent-001'), 'void', array_replace($payload, ['motivo' => 'Otro motivo'])))
        ->toThrow(ConflictHttpException::class);
});

it('rolls back the fiscal operation and allocation when enqueue fails', function () {
    $connection = Mockery::mock();
    $connection->shouldReceive('push')->once()->andThrow(new RuntimeException('queue unavailable'));
    Queue::shouldReceive('connection')->with('documents')->andReturn($connection);
    expect(fn () => app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-enqueue-001'), 'void', fiscalVoidPayload($this->receipt->getKey())))
        ->toThrow(RuntimeException::class);
    expect(FiscalOperation::count())->toBe(0);
});

it('rejects a foreign consumer document before fiscal admission', function () {
    $foreign = McrApiClient::create(['McrCode' => 'foreign-fiscal', 'McrName' => 'Foreign', 'McrIsActive' => true, 'SecStatus' => true]);
    expect(fn () => app(AdmitFiscalOperation::class)->execute(pipelineContext('foreign-fiscal-001', null, $foreign->getKey()), 'void', fiscalVoidPayload($this->receipt->getKey())))
        ->toThrow(NotFoundHttpException::class);
    expect(FiscalOperation::count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
});

it('requires explicit non-delivery confirmation for a void', function () {
    expect(fn () => app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-delivery-001'), 'void', array_replace(fiscalVoidPayload($this->receipt->getKey()), ['not_delivered' => false])))
        ->toThrow(ValidationException::class);
    expect(FiscalOperation::count())->toBe(0);
});

it('routes receipt voids to RC and invoice voids to RA', function (string $type, string $protocol) {
    $document = $type === '03' ? $this->receipt : persistedOriginal('01');
    $document->update(['McrIssueDate' => now()->toDateString(), 'McrIssuedAt' => now()]);
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-protocol-'.$type), 'void', fiscalVoidPayload($document->getKey()));
    expect($operation->protocol)->toBe($protocol);
    $transport = fiscalTransport($protocol);
    $transport->shouldReceive('sendFiscalXml')->once()->withArgs(fn ($actual, $name, $xml, $company) => $actual === $protocol && str_contains($name, $protocol.'-') && str_contains($xml, 'Signature'))
        ->andReturn((new SummaryResult)->setSuccess(true)->setTicket('protocol-ticket'));
    (new ProcessFiscalOperationJob($operation->getKey()))->handle(app(FiscalOperationProcessor::class));
    expect($operation->fresh()->ticket)->toBe('protocol-ticket')->and($document->fresh()->McrStatus)->toBe('accepted');
})->with([['03', 'RC'], ['01', 'RA']]);

it('persists a ticket and stays pending until an accepted CDR is received', function () {
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-poll-001'), 'void', fiscalVoidPayload($this->receipt->getKey()));
    $transport = fiscalTransport();
    $transport->shouldReceive('sendFiscalXml')->once()->andReturn((new SummaryResult)->setSuccess(true)->setTicket('poll-ticket'));
    $transport->shouldReceive('getStatus')->once()->with('poll-ticket', Mockery::type(Empresa::class))
        ->andReturn((new StatusResult)->setSuccess(true)->setCode('98'));
    (new ProcessFiscalOperationJob($operation->getKey()))->handle(app(FiscalOperationProcessor::class));
    (new PollFiscalOperationJob($operation->getKey()))->handle(app(FiscalOperationProcessor::class));
    expect($operation->fresh()->state)->toBe('pending')->and($this->receipt->fresh()->McrStatus)->toBe('accepted');
    $operation->fresh()->update(['next_attempt_at' => now()->subMinute()]);
    $transport->shouldReceive('getStatus')->once()->andReturn((new StatusResult)->setSuccess(true)->setCode('0')
        ->setCdrResponse((new CdrResponse)->setCode('0')->setDescription('Accepted'))->setCdrZip('fixture-cdr-zip'));
    (new PollFiscalOperationJob($operation->getKey()))->handle(app(FiscalOperationProcessor::class));
    $operation->refresh();
    expect($operation->state)->toBe('accepted')->and($this->receipt->fresh()->McrStatus)->toBe('void_accepted');
    Storage::disk('local')->assertExists($operation->xml_path);
    Storage::disk('local')->assertExists($operation->cdr_path);
});

it('does not mark a rejected void document as void accepted', function () {
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-reject-001'), 'void', fiscalVoidPayload($this->receipt->getKey()));
    $transport = fiscalTransport();
    $transport->shouldReceive('sendFiscalXml')->once()->andReturn((new SummaryResult)->setSuccess(true)->setTicket('rejected-ticket'));
    $transport->shouldReceive('getStatus')->once()->andReturn((new StatusResult)->setSuccess(true)->setCode('0')
        ->setCdrResponse((new CdrResponse)->setCode('2335')->setDescription('Rejected'))->setCdrZip('rejected-cdr'));
    (new ProcessFiscalOperationJob($operation->getKey()))->handle(app(FiscalOperationProcessor::class));
    (new PollFiscalOperationJob($operation->getKey()))->handle(app(FiscalOperationProcessor::class));
    expect($operation->fresh()->state)->toBe('rejected')->and($this->receipt->fresh()->McrStatus)->toBe('accepted');
});

it('does not report remote send failure as success', function () {
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-failure-001'), 'void', fiscalVoidPayload($this->receipt->getKey()));
    $transport = fiscalTransport();
    $transport->shouldReceive('sendFiscalXml')->once()->andReturn((new SummaryResult)->setSuccess(false)->setError(new SunatError('2335', 'Rejected')));
    (new ProcessFiscalOperationJob($operation->getKey()))->handle(app(FiscalOperationProcessor::class));
    expect($operation->fresh()->state)->toBe('rejected')->and($operation->fresh()->ticket)->toBeNull()
        ->and($this->receipt->fresh()->McrStatus)->toBe('accepted');
});

it('keeps an ambiguous remote timeout for manual review without resending on redelivery', function () {
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-timeout-001'), 'void', fiscalVoidPayload($this->receipt->getKey()));
    $transport = fiscalTransport();
    $transport->shouldReceive('sendFiscalXml')->once()->andThrow(new RuntimeException('Timeout after send'));
    $job = new ProcessFiscalOperationJob($operation->getKey());
    $job->handle(app(FiscalOperationProcessor::class));
    $job->handle(app(FiscalOperationProcessor::class));
    expect($operation->fresh()->state)->toBe('manual_review')->and($operation->fresh()->remote_started_at)->not->toBeNull()
        ->and($operation->fresh()->attempts)->toBe(1)->and($this->receipt->fresh()->McrStatus)->toBe('accepted');
});

it('includes receipt family notes in the daily summary and excludes invoices and foreign consumers', function () {
    $note = persistedOriginal('03')->replicate();
    $note->McrDocumentType = '07';
    $note->McrSeriesCode = 'BC01';
    $note->McrCorrelative = 201;
    $note->McrIssueDate = now()->toDateString();
    $note->McrIssuedAt = now();
    $note->save();
    $invoice = persistedOriginal('01');
    $invoice->update(['McrIssueDate' => now()->toDateString(), 'McrIssuedAt' => now()]);
    $foreignClient = McrApiClient::create(['McrCode' => 'summary-foreign', 'McrName' => 'Foreign', 'McrIsActive' => true, 'SecStatus' => true]);
    $foreignReceipt = persistedOriginal('03');
    $foreignReceipt->update(['McrApiClientID' => $foreignClient->getKey(), 'McrIssueDate' => now()->toDateString(), 'McrIssuedAt' => now()]);
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-summary-notes-001'), 'summary', ['fecha' => now()->toDateString()]);
    $transport = Mockery::mock(GreenterService::class);
    $transport->shouldReceive('getXml')->once()->withArgs(function ($model) use ($note, $foreignReceipt) {
        expect($model)->toBeInstanceOf(Summary::class);
        $types = array_map(fn ($detail) => $detail->getTipoDoc(), $model->getDetails());
        expect($types)->toContain('03', '07')->not->toContain('01');
        $numbers = array_map(fn ($detail) => $detail->getSerieNro(), $model->getDetails());
        expect($numbers)->toContain('BC01-'.$note->McrCorrelative)->not->toContain('B001-'.$foreignReceipt->McrCorrelative);

        return true;
    })->andReturn('<SummaryDocuments/>');
    $transport->shouldReceive('sendFiscalXml')->once()->andReturn((new SummaryResult)->setSuccess(true)->setTicket('summary-notes-ticket'));
    app()->instance(GreenterService::class, $transport);
    (new ProcessFiscalOperationJob($operation->getKey()))->handle(app(FiscalOperationProcessor::class));
    expect($operation->fresh()->state)->toBe('pending');
});

it('routes receipt family notes to RC and invoice family notes to RA', function (string $series, string $protocol) {
    $note = persistedOriginal('03')->replicate();
    $note->McrDocumentType = '07';
    $note->McrSeriesCode = $series;
    $note->McrCorrelative = 202;
    $note->McrIssueDate = now()->toDateString();
    $note->McrIssuedAt = now();
    $note->save();
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-note-'.$series), 'void', fiscalVoidPayload($note->getKey()));
    expect($operation->protocol)->toBe($protocol);
    $transport = fiscalTransport($protocol);
    $transport->shouldReceive('sendFiscalXml')->once()->andReturn((new SummaryResult)->setSuccess(true)->setTicket('note-ticket'));
    (new ProcessFiscalOperationJob($operation->getKey()))->handle(app(FiscalOperationProcessor::class));
    expect($operation->fresh()->state)->toBe('pending');
})->with([['BC01', 'RC'], ['FC01', 'RA']]);

it('requires CDR evidence even when ticket status reports completion', function () {
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-no-cdr-001'), 'void', fiscalVoidPayload($this->receipt->getKey()));
    $transport = fiscalTransport();
    $transport->shouldReceive('sendFiscalXml')->once()->andReturn((new SummaryResult)->setSuccess(true)->setTicket('no-cdr-ticket'));
    $transport->shouldReceive('getStatus')->once()->andReturn((new StatusResult)->setSuccess(true)->setCode('0'));
    (new ProcessFiscalOperationJob($operation->getKey()))->handle(app(FiscalOperationProcessor::class));
    (new PollFiscalOperationJob($operation->getKey()))->handle(app(FiscalOperationProcessor::class));
    expect($operation->fresh()->state)->not->toBe('accepted')->and($this->receipt->fresh()->McrStatus)->toBe('accepted');
});

it('reserves a document against a second pending void with a different idempotency key', function () {
    $service = app(AdmitFiscalOperation::class);
    $service->execute(pipelineContext('fiscal-reserve-001'), 'void', fiscalVoidPayload($this->receipt->getKey()));
    expect(fn () => $service->execute(pipelineContext('fiscal-reserve-002'), 'void', fiscalVoidPayload($this->receipt->getKey())))
        ->toThrow(ConflictHttpException::class);
    expect(FiscalOperation::count())->toBe(1)->and(DB::table('jobs')->count())->toBe(1);
});

it('recovers interrupted fiscal workers according to durable remote evidence', function (string $state, bool $contacted, ?string $ticket, string $expected, ?string $jobClass) {
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-interrupted-'.$expected), 'void', fiscalVoidPayload($this->receipt->getKey()));
    DB::table('jobs')->delete();
    DB::table('fiscal_operations')->where('id', $operation->getKey())->update([
        'state' => $state, 'remote_started_at' => $contacted ? now()->subMinutes(10) : null,
        'ticket' => $ticket, 'updated_at' => now()->subMinutes(10), 'next_attempt_at' => null,
    ]);
    $attemptId = DB::table('fiscal_operation_attempts')->insertGetId([
        'operation_id' => $operation->getKey(), 'action' => $state === 'polling' ? 'poll' : 'send',
        'state' => $state, 'started_at' => now()->subMinutes(10),
    ]);
    $transport = Mockery::mock(GreenterService::class);
    $transport->shouldNotReceive('sendFiscalXml');
    $transport->shouldNotReceive('getStatus');
    app()->instance(GreenterService::class, $transport);
    $this->artisan('billing:fiscal-reconcile')->assertExitCode(0);
    expect($operation->fresh()->state)->toBe($expected)
        ->and(DB::table('fiscal_operation_attempts')->where('id', $attemptId)->value('state'))->toBe('interrupted')
        ->and(DB::table('fiscal_operation_attempts')->where('id', $attemptId)->value('completed_at'))->not->toBeNull();
    if ($jobClass === null) {
        expect(DB::table('jobs')->count())->toBe(0);
    } else {
        expect(DB::table('jobs')->count())->toBe(1);
        expect(json_decode(DB::table('jobs')->value('payload'), true)['data']['command'])->toContain($jobClass);
    }
    expect($this->receipt->fresh()->McrStatus)->toBe('accepted');
})->with([
    ['processing', false, null, 'queued', 'ProcessFiscalOperationJob'],
    ['processing', true, null, 'manual_review', null],
    ['polling', true, 'interrupted-ticket', 'pending', 'PollFiscalOperationJob'],
]);

it('keeps a live fiscal worker claimed during reconciliation', function () {
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-live-worker'), 'void', fiscalVoidPayload($this->receipt->getKey()));
    DB::table('jobs')->delete();
    $operation->update(['state' => 'processing']);
    $this->artisan('billing:fiscal-reconcile')->assertExitCode(0);
    expect($operation->fresh()->state)->toBe('processing')->and(DB::table('jobs')->count())->toBe(0);
});

it('returns 404 for another consumer fiscal operation ticket and files', function (string $resource) {
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-private-resources'), 'void', fiscalVoidPayload($this->receipt->getKey()));
    $operation->update(['ticket' => 'private-fiscal-ticket', 'xml_path' => 'facturacion/operations/private.xml', 'cdr_path' => 'facturacion/operations/private.zip']);
    Storage::disk('local')->put('facturacion/operations/private.xml', '<Private/>');
    Storage::disk('local')->put('facturacion/operations/private.zip', 'private-cdr');
    $foreign = McrApiClient::create(['McrCode' => 'private-consumer', 'McrName' => 'Other consumer', 'McrIsActive' => true, 'SecStatus' => true]);
    $issued = app(ApiCredentialService::class)->issue($foreign->getKey(), [$operation->company_id], ['read']);
    $url = match ($resource) {
        'operation' => '/api/facturacion/operations/'.$operation->getKey(),
        'ticket' => '/api/facturacion/boletas/resumen/private-fiscal-ticket',
        default => '/api/facturacion/operations/'.$operation->getKey().'/files/'.$resource,
    };
    $this->withToken($issued['token'])->withHeader('X-Company-Id', $operation->company_id)->getJson($url)->assertNotFound();
})->with(['operation', 'ticket', 'xml', 'cdr']);

it('returns 404 for fiscal resources belonging to another company of the same consumer', function (string $resource) {
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('fiscal-private-company'), 'void', fiscalVoidPayload($this->receipt->getKey()));
    $otherCompany = Empresa::create(['McrRuc' => '20999999991', 'McrBusinessName' => 'Other company', 'McrEnvironment' => 'beta', 'McrIsActive' => true, 'SecStatus' => true]);
    $operation->update(['company_id' => $otherCompany->getKey(), 'ticket' => 'other-company-ticket', 'xml_path' => 'facturacion/operations/other.xml']);
    Storage::disk('local')->put('facturacion/operations/other.xml', '<Private/>');
    $issued = app(ApiCredentialService::class)->issue($operation->client_id, [$this->receipt->McrCompanyConfigID], ['read']);
    $url = match ($resource) {
        'operation' => '/api/facturacion/operations/'.$operation->getKey(),
        'ticket' => '/api/facturacion/boletas/resumen/other-company-ticket',
        default => '/api/facturacion/operations/'.$operation->getKey().'/files/xml',
    };
    $this->withToken($issued['token'])->withHeader('X-Company-Id', $this->receipt->McrCompanyConfigID)->getJson($url)->assertNotFound();
})->with(['operation', 'ticket', 'xml']);

it('separates receipt summaries by an explicitly selected currency', function () {
    $usd = persistedOriginal('03');
    $usd->update(['McrIssueDate' => now()->toDateString(), 'McrCurrencyCode' => 'USD']);
    $service = app(AdmitFiscalOperation::class);
    expect(fn () => $service->execute(pipelineContext('summary-mixed-001'), 'summary', ['fecha' => now()->toDateString()]))
        ->toThrow(UnprocessableEntityHttpException::class);
    $penOperation = $service->execute(pipelineContext('summary-pen-0001'), 'summary', ['fecha' => now()->toDateString(), 'currency' => 'PEN']);
    $usdOperation = $service->execute(pipelineContext('summary-usd-0001'), 'summary', ['fecha' => now()->toDateString(), 'currency' => 'USD']);
    expect(array_column($penOperation->payload['documents'], 'id'))->toBe([$this->receipt->getKey()])
        ->and(array_column($usdOperation->payload['documents'], 'id'))->toBe([$usd->getKey()]);
});

it('admits a later batch on the same date without resummarizing existing documents', function () {
    $service = app(AdmitFiscalOperation::class);
    $first = $service->execute(pipelineContext('summary-first-batch'), 'summary', ['fecha' => now()->toDateString()]);
    $first->update(['state' => 'accepted']);
    $later = persistedOriginal('03');
    $later->update(['McrIssueDate' => now()->toDateString()]);
    $second = $service->execute(pipelineContext('summary-later-batch'), 'summary', ['fecha' => now()->toDateString()]);
    expect(array_column($second->payload['documents'], 'id'))->toBe([$later->getKey()])->and($second->correlative)->toBe(2);
});

it('does not void a document while its daily summary result is unresolved', function () {
    app(AdmitFiscalOperation::class)->execute(pipelineContext('summary-before-void'), 'summary', ['fecha' => now()->toDateString()]);
    expect(fn () => app(AdmitFiscalOperation::class)->execute(pipelineContext('void-during-summary'), 'void', fiscalVoidPayload($this->receipt->getKey())))
        ->toThrow(ConflictHttpException::class);
});

it('identifies the accepted void operation and its CDR in the document webhook', function () {
    $payload = pipelinePayload('01');
    $payload['fechaEmision'] = now()->toIso8601String();
    $admission = app(AdmitElectronicDocument::class)->execute(pipelineContext('void-webhook-document'), $payload);
    $lifecycle = app(DocumentLifecycle::class);
    $lifecycle->transition($admission->documentId, $admission->submissionId, DocumentState::Processing);
    $lifecycle->transition($admission->documentId, $admission->submissionId, DocumentState::Accepted);
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('void-webhook-operation'), 'void', fiscalVoidPayload($admission->documentId));
    $operation->update(['state' => 'pending', 'ticket' => 'void-webhook-ticket']);
    $transport = Mockery::mock(GreenterService::class);
    $transport->shouldReceive('getStatus')->once()->andReturn((new StatusResult)->setSuccess(true)->setCode('0')->setCdrResponse((new CdrResponse)->setCode('0'))->setCdrZip('void-cdr'));
    app()->instance(GreenterService::class, $transport);
    app(FiscalOperationProcessor::class)->poll($operation->id);
    $event = DB::table('McrOutboxEvent')->where('McrDocumentID', $admission->documentId)->where('McrEventType', 'document.void_accepted')->first();
    expect($event)->not->toBeNull();
    $body = json_decode($event->McrPayloadBody, true);
    expect($body['data']['fiscal_operation']['operation_id'] ?? null)->toBe($operation->id)
        ->and($body['data']['fiscal_operation']['cdr_url'] ?? '')->toEndWith('/operations/'.$operation->id.'/files/cdr');
});
