<?php

use App\Enums\DocumentState;
use App\Jobs\PollSunatSubmissionJob;
use App\Jobs\ProcessElectronicDocumentJob;
use App\Models\McrDocument;
use App\Services\Documents\AdmitElectronicDocument;
use App\Services\Documents\DocumentLifecycle;
use App\Services\Documents\DocumentProcessorResolver;
use App\Services\Facturacion\DespatchPdfService;
use App\Services\Facturacion\DespatchService;
use App\Services\Sunat\GrePollResult;
use App\Services\Sunat\GreSendResult;
use App\Services\Sunat\GreTransport;
use App\Services\Sunat\GreenterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

require_once __DIR__.'/../Support/PipelineDatabase.php';

beforeEach(function(){ bootPipelineDatabase(); Storage::fake('local'); });
afterEach(function(){ if(isset($this->pipelineSchema)){ DB::statement('DROP SCHEMA "'.$this->pipelineSchema.'" CASCADE'); DB::disconnect('pipeline_test'); }});

it('admits 09 and 31 as first class documents using their configured series', function(string $type){
    $op=app(AdmitElectronicDocument::class)->execute(pipelineContext('gre-'.$type),despatchPayload($type));
    $doc=McrDocument::findOrFail($op->documentId);
    expect($doc->McrDocumentType)->toBe($type)->and($doc->McrSeriesCode)->toBe($type==='09'?'T001':'V001')
        ->and($doc->McrStatus)->toBe('queued')->and(DB::table('McrDocumentPayload')->count())->toBe(1)
        ->and(DB::table('McrSunatSubmission')->value('McrTransport'))->toBe('gre_rest');
})->with(['09','31']);

it('enforces conditional GRE rules before consuming a number', function(){
    $payload=despatchPayload('09','02'); unset($payload['traslado']['conductor']);
    expect(fn()=>app(AdmitElectronicDocument::class)->execute(pipelineContext('bad-gre'),$payload))->toThrow(UnprocessableEntityHttpException::class);
    expect(McrDocument::count())->toBe(0)->and((int)DB::table('McrSeries')->where('McrDocumentType','09')->value('McrNextCorrelative'))->toBe(1);
});

it('requires T series for 09 and V series for 31', function(){
    $payload=despatchPayload('09'); $payload['serie']='V001';
    expect(fn()=>app(AdmitElectronicDocument::class)->execute(pipelineContext('bad-series'),$payload))->toThrow(UnprocessableEntityHttpException::class);
});

it('maps distinct 09 and 31 public Greenter models without DOM patches', function(string $type){
    $payload=app(\App\Services\Documents\DespatchPayloadNormalizer::class)->processing(
        app(\App\Services\Documents\DespatchPayloadNormalizer::class)->request(despatchPayload($type)), $type==='09'?'T001':'V001', 1);
    $model=app(DespatchService::class)->map(\App\Models\Empresa::firstOrFail(), \App\DTO\DespatchData::fromArray($payload));
    expect($model->getTipoDoc())->toBe($type)->and($model->getEnvio()->getPesoTotal())->toBe(125.375)
        ->and($model->getDetails()[0]->getCantidad())->toBe(10.5);
    if($type==='31') expect($model->getTercero()->getNumDoc())->toBe('20333333331');
})->with(['09','31']);

it('generates structurally distinct signed UBL 2.1 XML and a GRE PDF through installed Greenter', function(string $type){
    $company=\App\Models\Empresa::firstOrFail(); $file='gre-test-'.bin2hex(random_bytes(4)).'.p12'; $password='test-pass';
    $key=openssl_pkey_new(['private_key_bits'=>2048]); $csr=openssl_csr_new(['commonName'=>'GRE Test'], $key);
    $cert=openssl_csr_sign($csr,null,$key,1); openssl_pkcs12_export($cert,$p12,$key,$password);
    file_put_contents(storage_path('app/certificates/'.$file),$p12); $company->update(['McrCertificateName'=>$file,'McrCertificatePassword'=>$password,'McrSolUser'=>'MODDATOS','McrSolPassword'=>'moddatos']);
    try {
        $normalizer=app(\App\Services\Documents\DespatchPayloadNormalizer::class);
        $payload=$normalizer->processing($normalizer->request(despatchPayload($type)),$type==='09'?'T001':'V001',1);
        $model=app(DespatchService::class)->map($company,\App\DTO\DespatchData::fromArray($payload));
        $xml=app(GreenterService::class)->getXml($model,$company); $dom=new DOMDocument; expect($dom->loadXML($xml))->toBeTrue();
        $xp=new DOMXPath($dom); $xp->registerNamespace('d','urn:oasis:names:specification:ubl:schema:xsd:DespatchAdvice-2');
        $xp->registerNamespace('cbc','urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xp->registerNamespace('cac','urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xp->registerNamespace('ds','http://www.w3.org/2000/09/xmldsig#');
        expect($xp->evaluate('string(/d:DespatchAdvice/cbc:UBLVersionID)'))->toBe('2.1')
            ->and($xp->evaluate('string(/d:DespatchAdvice/cbc:DespatchAdviceTypeCode)'))->toBe($type)
            ->and($xp->evaluate('string(//cac:Shipment/cbc:GrossWeightMeasure)'))->toBe('125.375')
            ->and($xp->query('//cac:DespatchLine'))->toHaveCount(1)
            ->and($xp->query('//ds:Signature'))->toHaveCount(1);
        if($type==='31') expect($xp->evaluate('string(//cac:SellerSupplierParty//cbc:ID)'))->toBe('20333333331');
        expect(substr(app(DespatchPdfService::class)->render($model),0,4))->toBe('%PDF');
    } finally { @unlink(storage_path('app/certificates/'.$file)); }
})->with(['09','31']);

it('persists exact XML ZIP PDF and ticket then schedules durable polling', function(string $type){
    Queue::fake(); $op=app(AdmitElectronicDocument::class)->execute(pipelineContext('process-'.$type),despatchPayload($type));
    $xml='<DespatchAdvice signed="yes">'.$type.'</DespatchAdvice>';
    $greenter=Mockery::mock(GreenterService::class); $greenter->shouldReceive('getXml')->once()->andReturn($xml); app()->instance(GreenterService::class,$greenter);
    $pdf=Mockery::mock(DespatchPdfService::class); $pdf->shouldNotReceive('render'); app()->instance(DespatchPdfService::class,$pdf);
    $transport=Mockery::mock(GreTransport::class); $transport->shouldReceive('send')->once()->withArgs(function($company,$name,$zip)use($xml,$type){
        $tmp=tempnam(sys_get_temp_dir(),'ziptest'); file_put_contents($tmp,$zip); $z=new ZipArchive; $z->open($tmp); $inside=$z->getFromName($name.'.xml'); $z->close(); unlink($tmp); return $inside===$xml && str_contains($name,'-'.$type.'-');
    })->andReturn(new GreSendResult('ticket-'.$type,'2026-09-13T12:01:00-05:00')); app()->instance(GreTransport::class,$transport);
    (new ProcessElectronicDocumentJob($op->documentId,$op->submissionId))->handle(app(DocumentProcessorResolver::class),app(DocumentLifecycle::class));
    $doc=McrDocument::findOrFail($op->documentId); expect($doc->McrStatus)->toBe('awaiting_sunat')->and($doc->McrSunatTicket)->toBe('ticket-'.$type)
        ->and(Storage::disk('local')->get($doc->McrXmlPath))->toBe($xml)->and($doc->McrZipPath)->not->toBeNull()->and($doc->McrPdfPath)->toBeNull();
    Queue::assertPushed(PollSunatSubmissionJob::class,fn($job)=>$job->submissionId===$op->submissionId);
})->with(['09','31']);

it('continues from only persisted submission data and accepts after a pending poll', function(){
    $op=admittedAwaitingGre(); $transport=Mockery::mock(GreTransport::class);
    $transport->shouldReceive('poll')->once()->andReturn(new GrePollResult('98')); app()->instance(GreTransport::class,$transport); Queue::fake();
    (new PollSunatSubmissionJob($op->submissionId))->handle($transport,app(\App\Services\Sunat\GreCdrParser::class),app(DocumentLifecycle::class));
    expect(McrDocument::find($op->documentId)->McrStatus)->toBe('awaiting_sunat'); Queue::assertPushed(PollSunatSubmissionJob::class);
    DB::table('McrSunatSubmission')->where('McrSunatSubmissionID',$op->submissionId)->update(['McrNextAttemptAt'=>now()->subSecond()]);
    $transport2=Mockery::mock(GreTransport::class); $transport2->shouldReceive('poll')->once()->andReturn(new GrePollResult('0',base64_encode(greCdrZip('0','Aceptada'))));
    (new PollSunatSubmissionJob($op->submissionId))->handle($transport2,app(\App\Services\Sunat\GreCdrParser::class),app(DocumentLifecycle::class));
    expect(McrDocument::find($op->documentId)->McrStatus)->toBe('accepted')->and(DB::table('McrSunatAttempt')->where('McrTransport','gre_poll')->count())->toBe(2)
        ->and(DB::table('McrOutboxEvent')->where('McrEventType','document.accepted')->count())->toBe(1);
});

it('classifies a ticket rejection and never confuses it with a pending result', function(){
    $op=admittedAwaitingGre(); $transport=Mockery::mock(GreTransport::class);
    $transport->shouldReceive('poll')->once()->andReturn(new GrePollResult('99',null,'2335','Documento rechazado'));
    (new PollSunatSubmissionJob($op->submissionId))->handle($transport,app(\App\Services\Sunat\GreCdrParser::class),app(DocumentLifecycle::class));
    expect(McrDocument::find($op->documentId)->McrStatus)->toBe('rejected')->and(DB::table('McrSunatSubmission')->value('McrCompletedAt'))->not->toBeNull();
});

it('keeps timeouts awaiting SUNAT and schedules a delayed retry', function(){
    Queue::fake(); $op=admittedAwaitingGre(); $transport=Mockery::mock(GreTransport::class); $transport->shouldReceive('poll')->once()->andThrow(new RuntimeException('timeout secret'));
    (new PollSunatSubmissionJob($op->submissionId))->handle($transport,app(\App\Services\Sunat\GreCdrParser::class),app(DocumentLifecycle::class));
    expect(McrDocument::find($op->documentId)->McrStatus)->toBe('awaiting_sunat')->and(DB::table('McrSunatAttempt')->value('McrError'))->not->toContain('secret');
    Queue::assertPushed(PollSunatSubmissionJob::class);
});

it('has GRE artifact columns in McrDocument schema', function(){
    expect(\Illuminate\Support\Facades\Schema::hasColumn('McrDocument','McrZipPath'))->toBeTrue()
        ;
});

it('keeps same key idempotent and rejects changed logistics', function(){
    $first=app(AdmitElectronicDocument::class)->execute(pipelineContext('same-gre'),despatchPayload());
    $same=app(AdmitElectronicDocument::class)->execute(pipelineContext('same-gre'),despatchPayload()); expect($same->documentId)->toBe($first->documentId);
    $different=despatchPayload(); $different['traslado']['peso_bruto']='126.000';
    expect(fn()=>app(AdmitElectronicDocument::class)->execute(pipelineContext('same-gre'),$different))->toThrow(ConflictHttpException::class);
});

it('has no second GRE pipeline, direct controller transport, sleep or DOM reflection', function(){
    foreach(['Actions/Facturacion/EmitGuiaAction.php','DTO/GuiaData.php','Services/Facturacion/GuiaService.php','Services/Sunat/SunatRestService.php','Services/Sunat/GreValidatorService.php','Jobs/EmitGuiaJob.php','Jobs/EmitGreJob.php'] as $file) expect(file_exists(app_path($file)))->toBeFalse();
    $sources=''; foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) if($file->isFile()&&$file->getExtension()==='php')$sources.=file_get_contents($file->getPathname());
    expect($sources)->not->toContain('sleep(')->not->toContain('ReflectionClass')->not->toContain('ReflectionMethod');
    expect(file_get_contents(app_path('Http/Controllers/Api/FacturacionController.php')))->not->toContain('enviarCpe(')->not->toContain('GreTransport');
});

function admittedAwaitingGre(): \App\Services\Documents\AdmissionResult {
    Queue::fake(); $op=app(AdmitElectronicDocument::class)->execute(pipelineContext('restart-gre'),despatchPayload());
    DB::table('McrDocument')->where('McrDocumentID',$op->documentId)->update(['McrStatus'=>'awaiting_sunat','McrSunatTicket'=>'persisted-ticket']);
    DB::table('McrSunatSubmission')->where('McrSunatSubmissionID',$op->submissionId)->update(['McrStatus'=>'awaiting_sunat','McrTicket'=>'persisted-ticket','McrAttemptNumber'=>1]);
    return $op;
}
function greCdrZip(string $code,string $description): string {
    $xml='<?xml version="1.0"?><ApplicationResponse xmlns="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"><cbc:ResponseCode>'.$code.'</cbc:ResponseCode><cbc:Description>'.$description.'</cbc:Description></ApplicationResponse>';
    $tmp=tempnam(sys_get_temp_dir(),'cdr'); $z=new ZipArchive; $z->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE); $z->addFromString('R-demo.xml',$xml); $z->close(); $bytes=file_get_contents($tmp); unlink($tmp); return $bytes;
}
