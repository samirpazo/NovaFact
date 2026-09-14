<?php

use App\Enums\DocumentState;
use App\Enums\FailureCategory;
use App\Enums\ProcessingCheckpoint;
use App\Enums\RecoveryAction;
use App\Exceptions\ClassifiedSubmissionException;
use App\Jobs\PollSunatSubmissionJob;
use App\Jobs\ProcessElectronicDocumentJob;
use App\Jobs\ReconcileSunatSubmissionJob;
use App\Models\Empresa;
use App\Models\McrDocument;
use App\Services\Documents\AdmitElectronicDocument;
use App\Services\Documents\DocumentLifecycle;
use App\Services\Documents\DocumentProcessorResolver;
use App\Services\Documents\ElectronicDocumentProcessor;
use App\Services\Documents\RecoveryEvidence;
use App\Services\Documents\SubmissionRecoveryPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/PipelineDatabase.php';

beforeEach(function () { bootPipelineDatabase(); });
afterEach(function () {
    if (isset($this->pipelineSchema)) { DB::statement('DROP SCHEMA "'.$this->pipelineSchema.'" CASCADE'); DB::disconnect('pipeline_test'); }
});

it('centralizes recovery decisions from durable evidence', function (RecoveryEvidence $evidence, RecoveryAction $expected) {
    expect(app(SubmissionRecoveryPolicy::class)->decide($evidence))->toBe($expected);
})->with([
    'known terminal' => [new RecoveryEvidence(DocumentState::Accepted, 'soap', ProcessingCheckpoint::RemoteResultPersisted, hasFiscalResult: true), RecoveryAction::MarkTerminal],
    'artifact only' => [new RecoveryEvidence(DocumentState::Accepted, 'soap', ProcessingCheckpoint::RemoteResultPersisted, hasFiscalResult: true, artifactsMissing: true), RecoveryAction::RegenerateArtifacts],
    'GRE ticket' => [new RecoveryEvidence(DocumentState::AwaitingSunat, 'gre_rest', ProcessingCheckpoint::RemoteResponseReceived, ticket: 'ticket'), RecoveryAction::PollTicket],
    'ambiguous SOAP' => [new RecoveryEvidence(DocumentState::ReconciliationPending, 'soap', ProcessingCheckpoint::SubmissionStarted, FailureCategory::AmbiguousSubmission, ambiguous: true), RecoveryAction::Reconcile],
    'known safe local' => [new RecoveryEvidence(DocumentState::RetryPending, 'soap', ProcessingCheckpoint::XmlSigned, FailureCategory::LocalRetryable), RecoveryAction::RetrySubmission],
    'invalid document' => [new RecoveryEvidence(DocumentState::ManualReview, 'soap', ProcessingCheckpoint::Admitted, FailureCategory::Validation), RecoveryAction::ManualReview],
]);

it('never resends automatically when the SOAP outcome is ambiguous', function () {
    Queue::fake();
    $op = app(AdmitElectronicDocument::class)->execute(pipelineContext('ambiguous-soap'), pipelinePayload())->toArray();
    Queue::fake();
    $processor = Mockery::mock(ElectronicDocumentProcessor::class);
    $processor->shouldReceive('process')->once()->andThrow(ClassifiedSubmissionException::ambiguous(
        ProcessingCheckpoint::SubmissionStarted, new RuntimeException('secret-token')));
    $resolver = Mockery::mock(DocumentProcessorResolver::class);
    $resolver->shouldReceive('resolve')->once()->andReturn($processor);
    (new ProcessElectronicDocumentJob($op['document_id'], $op['submission_id']))->handle($resolver, app(DocumentLifecycle::class));
    $submission = DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $op['submission_id'])->first();
    expect(McrDocument::find($op['document_id'])->McrStatus)->toBe('reconciliation_pending')
        ->and((bool) $submission->McrIsAmbiguous)->toBeTrue()
        ->and($submission->McrFailureCategory)->toBe('ambiguous_submission')
        ->and($submission->McrError)->not->toContain('secret-token');
    Queue::assertPushed(ReconcileSunatSubmissionJob::class);
    Queue::assertNotPushed(ProcessElectronicDocumentJob::class);
});

it('keeps attempts historical and blocks a duplicate active GRE poll claim', function () {
    $op = app(AdmitElectronicDocument::class)->execute(pipelineContext('claimed-gre'), despatchPayload())->toArray();
    DB::table('McrDocument')->where('McrDocumentID', $op['document_id'])->update(['McrStatus' => 'awaiting_sunat']);
    DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $op['submission_id'])->update([
        'McrStatus' => 'awaiting_sunat', 'McrTicket' => 'ticket-one', 'McrClaimedAt' => now(), 'McrClaimToken' => (string) Str::uuid(),
    ]);
    $transport = Mockery::mock(\App\Services\Sunat\GreTransport::class); $transport->shouldNotReceive('poll');
    (new PollSunatSubmissionJob($op['submission_id']))->handle($transport, app(\App\Services\Sunat\GreCdrParser::class), app(DocumentLifecycle::class), app(\App\Services\Facturacion\ManagedFileService::class));
    expect(DB::table('McrSunatAttempt')->count())->toBe(0);
});

it('requires an explicit company for legacy fiscal facades and transport helpers', function () {
    expect((new ReflectionMethod(\App\Services\Sunat\GreenterService::class, 'getXml'))->getParameters()[1]->isDefaultValueAvailable())->toBeFalse()
        ->and((new ReflectionMethod(\App\Services\Sunat\GreenterService::class, 'send'))->getNumberOfRequiredParameters())->toBe(2);
    $this->withToken('test-token')->postJson('/api/facturacion/boletas/resumen-diario', ['fecha' => '2026-09-13'])->assertStatus(422);
});

it('queues only due recovery candidates with a bounded command', function () {
    Queue::fake();
    $op = app(AdmitElectronicDocument::class)->execute(pipelineContext('due-command'), pipelinePayload())->toArray();
    DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $op['submission_id'])->update(['McrNextAttemptAt' => now()->subSecond()]);
    $this->artisan('billing:reconcile --limit=10')->assertSuccessful();
    Queue::assertPushed(ReconcileSunatSubmissionJob::class, fn ($job) => $job->submissionId === $op['submission_id']);
});

it('reconciles with the company persisted on the document', function () {
    Queue::fake();
    $companyA=Empresa::firstOrFail();
    $companyB=Empresa::create(['McrRuc'=>'20999999991','McrBusinessName'=>'Other company','McrEnvironment'=>'beta','McrIsActive'=>true,'SecStatus'=>true,'CreateDate'=>now()]);
    $op=app(AdmitElectronicDocument::class)->execute(pipelineContext('company-reconcile'),pipelinePayload())->toArray();
    DB::table('McrDocument')->where('McrDocumentID',$op['document_id'])->update(['McrStatus'=>'reconciliation_pending']);
    DB::table('McrSunatSubmission')->where('McrSunatSubmissionID',$op['submission_id'])->update([
        'McrStatus'=>'reconciliation_pending','McrCheckpoint'=>'submission_started','McrIsAmbiguous'=>true,
        'McrFailureCategory'=>'ambiguous_submission','McrNextAttemptAt'=>now()->subSecond(),
    ]);
    $consult=Mockery::mock(\App\Services\Sunat\SoapStatusConsultant::class);
    $consult->shouldReceive('consult')->once()->withArgs(fn($company)=>$company->getKey()===$companyA->getKey() && $company->getKey()!==$companyB->getKey())
        ->andReturn((new \Greenter\Model\Response\StatusCdrResult)->setSuccess(true)->setCdrResponse(
            (new \Greenter\Model\Response\CdrResponse)->setCode('0')->setDescription('Accepted')));
    (new ReconcileSunatSubmissionJob($op['submission_id']))->handle(app(SubmissionRecoveryPolicy::class),
        app(\App\Services\Documents\RecoveryEvidenceFactory::class),app(\App\Services\Documents\RetryBackoffPolicy::class),
        $consult,app(DocumentLifecycle::class));
    expect(McrDocument::find($op['document_id'])->McrStatus)->toBe('accepted')
        ->and((int)DB::table('McrSunatSubmission')->value('McrReconciliationCount'))->toBe(1);
});
