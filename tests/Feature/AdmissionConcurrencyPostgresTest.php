<?php

use App\Models\McrApiClient;
use App\Models\McrDocument;
use App\Services\Documents\AdmitElectronicDocument;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Support/PipelineDatabase.php';

beforeEach(function () {
    if (getenv('PIPELINE_TEST_POSTGRES') !== '1') {
        $this->markTestSkipped('Requires PIPELINE_TEST_POSTGRES=1 and independent PostgreSQL connections.');
    }
    bootPipelineDatabase();
});

afterEach(function () {
    if (isset($this->pipelineSchema)) {
        DB::statement('DROP SCHEMA "'.$this->pipelineSchema.'" CASCADE');
        DB::disconnect('pipeline_test');
    }
});

function concurrentAdmissions(array $operations): array
{
    $children = [];
    foreach ($operations as $index => $operation) {
        $path = sys_get_temp_dir().'/nova-admission-'.getmypid().'-'.$index.'-'.bin2hex(random_bytes(4)).'.json';
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                DB::disconnect(config('database.default'));
                $result = app(AdmitElectronicDocument::class)->execute($operation['context'], $operation['payload']);
                $output = ['ok' => true, ...$result->toArray()];
            } catch (Throwable $exception) {
                $output = [
                    'ok' => false,
                    'class' => get_class($exception),
                    'status' => method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : null,
                ];
            }
            file_put_contents($path, json_encode($output, JSON_THROW_ON_ERROR));
            exit(0);
        }
        $children[] = [$pid, $path];
    }

    $results = [];
    foreach ($children as [$pid, $path]) {
        pcntl_waitpid($pid, $status);
        $results[] = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        unlink($path);
    }
    DB::disconnect(config('database.default'));

    return $results;
}

it('deduplicates twenty truly concurrent admissions by scoped idempotency key', function () {
    $operations = array_fill(0, 20, ['context' => pipelineContext('concurrent-idem-001'), 'payload' => pipelinePayload()]);
    $results = concurrentAdmissions($operations);

    expect(collect($results)->where('ok', true))->toHaveCount(20)
        ->and(collect($results)->pluck('document_id')->unique())->toHaveCount(1)
        ->and(collect($results)->pluck('submission_id')->unique())->toHaveCount(1)
        ->and(McrDocument::count())->toBe(1)
        ->and(DB::table('McrSunatSubmission')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and((int) DB::table('McrSeries')->where('McrDocumentType', '01')->value('McrNextCorrelative'))->toBe(2);
});

it('allows one winner and one 409 for concurrent incompatible idempotent requests', function () {
    $different = pipelinePayload();
    $different['clientRznSocial'] = 'Concurrent conflict';
    $results = concurrentAdmissions([
        ['context' => pipelineContext('concurrent-conflict-001'), 'payload' => pipelinePayload()],
        ['context' => pipelineContext('concurrent-conflict-001'), 'payload' => $different],
    ]);

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('status', 409))->toHaveCount(1)
        ->and(McrDocument::count())->toBe(1)
        ->and(DB::table('McrSunatSubmission')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and((int) DB::table('McrSeries')->where('McrDocumentType', '01')->value('McrNextCorrelative'))->toBe(2);
});

it('deduplicates twenty concurrent external references across different request keys', function () {
    $operations = [];
    foreach (range(1, 20) as $number) {
        $operations[] = [
            'context' => pipelineContext(sprintf('external-key-%03d', $number), 'SAP-OINV-48522'),
            'payload' => pipelinePayload(),
        ];
    }
    $results = concurrentAdmissions($operations);

    expect(collect($results)->where('ok', true))->toHaveCount(20)
        ->and(collect($results)->pluck('document_id')->unique())->toHaveCount(1)
        ->and(McrDocument::count())->toBe(1)
        ->and(DB::table('McrIdempotency')->count())->toBe(20)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('returns 409 for a concurrent incompatible external reference', function () {
    $different = pipelinePayload();
    $different['clientRznSocial'] = 'Other source operation';
    $results = concurrentAdmissions([
        ['context' => pipelineContext('external-conflict-001', 'SUNEXPERT-ORDER-100992'), 'payload' => pipelinePayload()],
        ['context' => pipelineContext('external-conflict-002', 'SUNEXPERT-ORDER-100992'), 'payload' => $different],
    ]);

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('status', 409))->toHaveCount(1)
        ->and(McrDocument::count())->toBe(1)
        ->and(DB::table('McrIdempotency')->count())->toBe(1);
});

it('allocates fifty gapless correlatives through independent concurrent connections', function () {
    $operations = [];
    foreach (range(1, 50) as $number) {
        $payload = pipelinePayload();
        $payload['clientRznSocial'] = 'Numbered operation '.$number;
        $operations[] = ['context' => pipelineContext(sprintf('number-key-%04d', $number)), 'payload' => $payload];
    }
    $results = concurrentAdmissions($operations);
    $correlatives = McrDocument::orderBy('McrCorrelative')->pluck('McrCorrelative')->map(fn ($value) => (int) $value)->all();

    expect(collect($results)->where('ok', true))->toHaveCount(50)
        ->and($correlatives)->toBe(range(1, 50))
        ->and(DB::table('McrSunatSubmission')->count())->toBe(50)
        ->and(DB::table('jobs')->count())->toBe(50)
        ->and((int) DB::table('McrSeries')->where('McrDocumentType', '01')->value('McrNextCorrelative'))->toBe(51);
});

it('scopes a simultaneous shared key across clients and companies', function () {
    $clientB = McrApiClient::create([
        'McrCode' => 'parallel-client-b', 'McrName' => 'Parallel client B', 'McrIsActive' => true,
        'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
    ]);
    $companyB = \App\Models\Empresa::create([
        'McrRuc' => '20999999991', 'McrBusinessName' => 'Parallel company B', 'McrEnvironment' => 'beta',
        'McrIsActive' => true, 'SecStatus' => true, 'McrIgvRate' => 18,
    ]);
    \App\Models\McrSeries::create([
        'McrCompanyConfigID' => $companyB->getKey(), 'McrDocumentType' => '01', 'McrSeriesCode' => 'F001',
        'McrNextCorrelative' => 1, 'McrIsActive' => true, 'SecStatus' => true,
        'CreateUserId' => 0, 'CreateDate' => now(),
    ]);
    $results = concurrentAdmissions([
        ['context' => pipelineContext('parallel-shared-key'), 'payload' => pipelinePayload()],
        ['context' => pipelineContext('parallel-shared-key', clientId: $clientB->getKey()), 'payload' => pipelinePayload()],
        ['context' => pipelineContext('parallel-shared-key', companyId: $companyB->getKey()), 'payload' => pipelinePayload()],
    ]);

    expect(collect($results)->where('ok', true))->toHaveCount(3)
        ->and(collect($results)->pluck('document_id')->unique())->toHaveCount(3)
        ->and(McrDocument::count())->toBe(3);
});

it('migrates legacy data without changing documents and survives down then up', function () {
    $migration = require database_path('migrations/2026_09_13_000100_add_admission_identity.php');
    $migration->down();
    $companyId = (int) DB::table('McrCompanyConfig')->value('McrCompanyConfigID');
    $documentId = (int) DB::table('McrDocument')->insertGetId([
        'McrCompanyConfigID' => $companyId, 'McrSeriesID' => null,
        'McrDocumentType' => '01', 'McrSeriesCode' => 'F001', 'McrCorrelative' => 77,
        'McrIssueDate' => '2026-09-01', 'McrCurrencyCode' => 'PEN',
        'McrCustomerDocumentType' => '6', 'McrCustomerDocumentNumber' => '20123456789',
        'McrCustomerName' => 'Historical fixture', 'McrTotalAmount' => 118,
        'McrStatus' => 'accepted', 'McrIdempotencyKey' => 'historical-key-001',
        'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
    ], 'McrDocumentID');
    $submissionId = (int) DB::table('McrSunatSubmission')->insertGetId([
        'McrDocumentID' => $documentId, 'McrOperation' => 'emitir', 'McrTransport' => 'soap',
        'McrAttemptNumber' => 1, 'McrStatus' => 'accepted',
        'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
    ], 'McrSunatSubmissionID');
    DB::table('McrIdempotency')->insert([
        'McrKey' => 'historical-key-001', 'McrRequestHash' => str_repeat('a', 64),
        'McrStatus' => 'completed', 'McrCreatedAt' => now(), 'McrUpdatedAt' => now(),
    ]);
    DB::table('McrIdempotency')->insert([
        'McrKey' => 'historical-orphan-001', 'McrRequestHash' => str_repeat('b', 64),
        'McrStatus' => 'processing', 'McrCreatedAt' => now(), 'McrUpdatedAt' => now(),
    ]);

    foreach ([1, 2] as $run) {
        $migration->up();
        $document = DB::table('McrDocument')->where('McrDocumentID', $documentId)->first();
        $identity = DB::table('McrIdempotency')->where('McrKey', 'historical-key-001')->first();
        expect((int) $document->McrCorrelative)->toBe(77)
            ->and($document->McrCustomerName)->toBe('Historical fixture')
            ->and($document->McrApiClientID)->not->toBeNull()
            ->and((int) $identity->McrCompanyConfigID)->toBe($companyId)
            ->and((int) $identity->McrDocumentID)->toBe($documentId)
            ->and((int) $identity->McrSunatSubmissionID)->toBe($submissionId)
            ->and(DB::table('McrDocument')->count())->toBe(1)
            ->and(DB::table('McrIdempotency')->count())->toBe(2)
            ->and(DB::table('McrIdempotency')->where('McrKey', 'historical-orphan-001')->exists())->toBeTrue();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $externalIndex = DB::table('pg_indexes')->where('schemaname', $this->pipelineSchema)
            ->where('indexname', 'UX_McrDocument_ClientCompanyExternal')->value('indexdef');
        expect($externalIndex)->toContain('UNIQUE INDEX')->toContain('WHERE ("McrExternalReference" IS NOT NULL)')
            ->and(DB::table('pg_indexes')->where('schemaname', $this->pipelineSchema)
                ->where('indexname', 'UX_McrIdempotency_ScopeKey')->exists())->toBeTrue();
        if ($run === 1) {
            $migration->down();
        }
    }
});
