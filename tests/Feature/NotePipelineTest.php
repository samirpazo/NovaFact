<?php

use App\DTO\FacturaData;
use App\Jobs\ProcessElectronicDocumentJob;
use App\Models\Empresa;
use App\Models\McrDocument;
use App\Models\McrSeries;
use App\Services\Documents\AdmitElectronicDocument;
use App\Services\Documents\DocumentLifecycle;
use App\Services\Documents\DocumentProcessorResolver;
use App\Services\Documents\PayloadCodec;
use App\Services\Facturacion\InvoicePdfService;
use App\Services\Facturacion\NoteService;
use App\Services\Sunat\GreenterService;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\CdrResponse;
use Greenter\Model\Sale\Note;
use Greenter\Xml\Builder\NoteBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

it('admits credit and debit notes with an auditable internal reference', function (string $noteType, string $affectedType) {
    $original = persistedOriginal($affectedType);
    $payload = notePayload($noteType, 'internal', $original->getKey(), $affectedType);
    $result = app(AdmitElectronicDocument::class)->execute(pipelineContext('note-'.$noteType.'-'.$affectedType), $payload);
    $note = McrDocument::findOrFail($result->documentId);
    $reference = DB::table('McrDocumentReference')->first();
    $snapshot = json_decode(DB::table('McrDocumentPayload')->where('McrDocumentID', $note->getKey())->value('McrPayload'), true);

    expect($note->McrDocumentType)->toBe($noteType)
        ->and((int) $reference->ReferencedMcrDocumentID)->toBe($original->getKey())
        ->and($reference->McrIsExternal)->toBeIn([false, 0])
        ->and($reference->McrReferencedNumber)->toBe($original->McrSeriesCode.'-'.$original->McrCorrelative)
        ->and($snapshot['reference']['document_id'])->toBe($original->getKey())
        ->and(PayloadCodec::hash($snapshot))->toBe($note->McrPayloadHash)
        ->and(DB::table('jobs')->count())->toBe(1);
})->with([['07', '01'], ['07', '03'], ['08', '01'], ['08', '03']]);

it('admits a controlled external reference without inventing a document', function (string $type) {
    $payload = notePayload($type, 'external');
    $result = app(AdmitElectronicDocument::class)->execute(pipelineContext('external-note-'.$type), $payload);
    $reference = DB::table('McrDocumentReference')->where('McrDocumentID', $result->documentId)->first();

    expect($reference->ReferencedMcrDocumentID)->toBeNull()
        ->and($reference->McrIsExternal)->toBeIn([true, 1])
        ->and($reference->McrReferencedDocumentType)->toBe('01')
        ->and((int) $reference->McrReferencedCorrelative)->toBe(900);
})->with(['07', '08']);

it('exposes notes through the existing HTTP facade without a second emission path', function (string $type) {
    $payload = notePayload($type, 'external');
    $response = $this->withToken('test-token')->withHeader('Idempotency-Key', 'http-note-'.$type)
        ->postJson('/api/facturacion/emitir-factura', $payload);

    $response->assertStatus(202)->assertJsonPath('status', 'queued');
    expect(McrDocument::where('McrDocumentType', $type)->count())->toBe(1)
        ->and(DB::table('McrSunatSubmission')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
})->with(['07', '08']);

it('rejects invalid note reasons, missing documents, incompatible state and cross-company references', function () {
    $original = persistedOriginal();
    $invalid = notePayload('07', 'internal', $original->getKey());
    $invalid['reference']['reason_code'] = '99';
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('bad-reason'), $invalid))
        ->toThrow(UnprocessableEntityHttpException::class);

    $unsupported = notePayload('07', 'internal', $original->getKey());
    $unsupported['reference']['reason_code'] = '13';
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('unsupported-reason'), $unsupported))
        ->toThrow(UnprocessableEntityHttpException::class);

    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('missing-original'), notePayload('08', 'internal', 999999)))
        ->toThrow(UnprocessableEntityHttpException::class);

    $rejected = persistedOriginal(status: 'rejected');
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('rejected-original'), notePayload('08', 'internal', $rejected->getKey())))
        ->toThrow(UnprocessableEntityHttpException::class);

    $companyB = Empresa::create(['McrRuc' => '20999999993', 'McrBusinessName' => 'Other company', 'McrEnvironment' => 'beta', 'McrIsActive' => true, 'SecStatus' => true]);
    $foreign = persistedOriginal(companyId: $companyB->getKey());
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('foreign-original'), notePayload('07', 'internal', $foreign->getKey())))
        ->toThrow(UnprocessableEntityHttpException::class);
    expect(McrDocument::whereIn('McrDocumentType', ['07', '08'])->count())->toBe(0);
});

it('rejects a note series from the wrong family or document type', function () {
    $original = persistedOriginal('03');
    $payload = notePayload('07', 'internal', $original->getKey(), '03');
    $payload['serie'] = 'FC01';
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('wrong-family'), $payload))
        ->toThrow(UnprocessableEntityHttpException::class);

    $payload['serie'] = 'BC01';
    McrSeries::where('McrDocumentType', '07')->where('McrSeriesCode', 'BC01')->update(['McrIsActive' => false]);
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('inactive-series'), $payload))
        ->toThrow(UnprocessableEntityHttpException::class);
});

it('inherits standard key and external-reference idempotency for notes', function (string $type) {
    $original = persistedOriginal();
    $payload = notePayload($type, 'internal', $original->getKey());
    $first = app(AdmitElectronicDocument::class)->execute(pipelineContext('note-key-'.$type, 'ERP-NOTE-'.$type), $payload);
    $sameKey = app(AdmitElectronicDocument::class)->execute(pipelineContext('note-key-'.$type, 'ERP-NOTE-'.$type), $payload);
    $sameExternal = app(AdmitElectronicDocument::class)->execute(pipelineContext('note-alias-'.$type, 'ERP-NOTE-'.$type), $payload);
    $different = $payload;
    $different['reference']['reason'] = 'Another semantic operation';

    expect($first->documentId)->toBe($sameKey->documentId)->toBe($sameExternal->documentId)
        ->and(McrDocument::whereIn('McrDocumentType', ['07', '08'])->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
    expect(fn () => app(AdmitElectronicDocument::class)->execute(pipelineContext('note-key-'.$type, 'ERP-NOTE-'.$type), $different))
        ->toThrow(ConflictHttpException::class);
})->with(['07', '08']);

it('builds structural UBL 2.1 XML and a Greenter report PDF for each note type', function (string $type) {
    Storage::fake('local');
    $payload = notePayload($type, 'external');
    $data = FacturaData::fromArray([...$payload, 'correlativo' => '15']);
    $note = app(NoteService::class)->map($data, $payload['reference'], Empresa::firstOrFail());
    $xml = (new NoteBuilder)->build($note);
    $dom = new DOMDocument;
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    $lineName = $type === '07' ? 'CreditNoteLine' : 'DebitNoteLine';

    expect($dom->documentElement->localName)->toBe($type === '07' ? 'CreditNote' : 'DebitNote')
        ->and($xpath->evaluate('string(//*[local-name()="ID"][1])'))->toBe($payload['serie'].'-15')
        ->and($xpath->evaluate('string(//*[local-name()="DocumentTypeCode"])'))->toBe('01')
        ->and($xpath->evaluate('string(//*[local-name()="InvoiceDocumentReference"]/*[local-name()="ID"])'))->toBe('F001-900')
        ->and($xpath->evaluate('string(//*[local-name()="ResponseCode"])'))->toBe($payload['reference']['reason_code'])
        ->and($xpath->evaluate('string(//*[local-name()="Description"])'))->toBe('Ajuste probado')
        ->and($xpath->query('//*[local-name()="'.$lineName.'"]'))->toHaveCount(1)
        ->and($xpath->evaluate('string(//*[local-name()="DocumentCurrencyCode"])'))->toBe('PEN')
        ->and($xpath->evaluate('string(//*[local-name()="TaxAmount"][@currencyID="PEN"][1])'))->not->toBe('');
    $pdf = app(InvoicePdfService::class)->generate($note, 'note-'.$type.'.pdf');
    expect(Storage::disk('local')->exists($pdf['path']))->toBeTrue()
        ->and(Storage::disk('local')->get($pdf['path']))->toStartWith('%PDF');
})->with(['07', '08']);

it('executes admission queue processor Greenter result and artifacts for notes', function (string $type, string $sunatCode, string $expectedState) {
    Storage::fake('local');
    $original = persistedOriginal();
    $operation = app(AdmitElectronicDocument::class)->execute(pipelineContext('process-note-'.$type.'-'.$sunatCode), notePayload($type, 'internal', $original->getKey()));
    $note = McrDocument::findOrFail($operation->documentId);
    $greenter = Mockery::mock(GreenterService::class);
    $greenter->shouldReceive('getXml')->once()->withArgs(fn ($model, $company) => $model instanceof Note && $model->getTipoDoc() === $type && $model->getNumDocfectado() === 'F001-200')->andReturn('<signed-note/>');
    $greenter->shouldReceive('sendSignedXml')->once()->with(Note::class, Mockery::type('string'), '<signed-note/>', Mockery::type(Empresa::class))
        ->andReturn((new BillResult)->setSuccess(true)->setCdrZip('note-cdr')->setCdrResponse((new CdrResponse)->setCode($sunatCode)->setDescription('SUNAT fixture')));
    app()->instance(GreenterService::class, $greenter);
    (new ProcessElectronicDocumentJob($note->getKey(), $operation->submissionId))->handle(app(DocumentProcessorResolver::class), app(DocumentLifecycle::class));
    $note->refresh();

    expect($note->McrStatus)->toBe($expectedState)
        ->and($note->McrXmlPath)->not->toBeNull()
        ->and($note->McrCdrPath)->not->toBeNull()
        ->and(DB::table('McrSunatResponse')->value('McrResponseCode'))->toBe($sunatCode);
    if ($expectedState === 'accepted') {
        expect($note->McrPdfPath)->not->toBeNull();
    }
})->with([['07', '0', 'accepted'], ['08', '0', 'accepted'], ['07', '2335', 'rejected'], ['08', '2335', 'rejected']]);
