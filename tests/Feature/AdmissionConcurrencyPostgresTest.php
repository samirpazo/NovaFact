<?php

use App\Enums\DocumentState;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\PollSunatSubmissionJob;
use App\Models\Empresa;
use App\Models\McrApiClient;
use App\Models\McrDocument;
use App\Models\McrEstablishment;
use App\Models\McrSeries;
use App\Services\Documents\AdmitElectronicDocument;
use App\Services\Documents\DocumentLifecycle;
use App\Services\Documents\ProcessingResult;
use App\Services\Sunat\GreCdrParser;
use App\Services\Sunat\GrePollResult;
use App\Services\Sunat\GreSendResult;
use App\Services\Sunat\GreTransport;
use App\Services\Webhooks\DocumentIntegrationEventPublisher;
use App\Services\Webhooks\WebhookBackoffPolicy;
use App\Services\Webhooks\WebhookTransport;
use App\Services\Webhooks\WebhookTransportResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class ConcurrentWebhookTransport implements WebhookTransport
{
    public static string $path;

    public function deliver(string $url, string $eventId, string $rawBody, string $secret): WebhookTransportResult
    {
        $h = fopen(self::$path, 'c+');
        flock($h, LOCK_EX);
        $count = (int) stream_get_contents($h);
        rewind($h);
        ftruncate($h, 0);
        fwrite($h, (string) ($count + 1));
        fflush($h);
        flock($h, LOCK_UN);
        fclose($h);
        usleep(300000);

        return new WebhookTransportResult(true, false, 204, null, null);
    }
}

final class RecoveryCountingTransport implements GreTransport
{
    public static string $path;

    public function send(Empresa $company, string $name, string $zip): GreSendResult
    {
        throw new LogicException('send must not run');
    }

    public function poll(Empresa $company, string $ticket): GrePollResult
    {
        $handle = fopen(self::$path, 'c+');
        flock($handle, LOCK_EX);
        $count = (int) stream_get_contents($handle);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string) ($count + 1));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        usleep(300000);

        return new GrePollResult('98');
    }
}

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

it('allocates independent gapless correlatives for two establishments', function () {
    $companyId = (int) DB::table('McrCompanyConfig')->value('McrCompanyConfigID');
    $branchB = McrEstablishment::create([
        'McrCompanyConfigID' => $companyId, 'McrExternalCode' => 'RST-BRANCH-2',
        'McrSunatCode' => '0001', 'McrName' => 'Sucursal B', 'McrAddress' => 'Av. B 456',
        'McrUbigeo' => '150102', 'McrCountryCode' => 'PE', 'McrIsDefault' => false,
        'McrIsActive' => true, 'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
    ]);
    DB::table('McrSeries')->insert([
        'McrCompanyConfigID' => $companyId, 'McrEstablishmentID' => $branchB->getKey(),
        'McrDocumentType' => '01', 'McrSeriesCode' => 'F002', 'McrNextCorrelative' => 1,
        'McrIsActive' => true, 'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
    ]);
    $operations = [];
    foreach (range(1, 50) as $number) {
        $a = pipelinePayload();
        $a['clientRznSocial'] = "Branch A $number";
        $b = pipelinePayload();
        $b['establishment'] = 'RST-BRANCH-2';
        $b['serie'] = 'F002';
        $b['clientRznSocial'] = "Branch B $number";
        $operations[] = ['context' => pipelineContext("branch-a-$number"), 'payload' => $a];
        $operations[] = ['context' => pipelineContext("branch-b-$number"), 'payload' => $b];
    }
    $results = concurrentAdmissions($operations);
    expect(collect($results)->where('ok', true))->toHaveCount(100)
        ->and(McrDocument::where('McrSeriesCode', 'F001')->orderBy('McrCorrelative')->pluck('McrCorrelative')->map(fn ($v) => (int) $v)->all())->toBe(range(1, 50))
        ->and(McrDocument::where('McrSeriesCode', 'F002')->orderBy('McrCorrelative')->pluck('McrCorrelative')->map(fn ($v) => (int) $v)->all())->toBe(range(1, 50));
});

it('rolls back fresh installation in dependency order and recreates its constraints', function () {
    $files = glob(database_path('migrations/*.php'));
    foreach (array_reverse($files) as $file) {
        (require $file)->down();
    }
    foreach ($files as $file) {
        (require $file)->up();
    }
    expect(DB::table('McrDocument')->count())->toBe(0)
        ->and(DB::table('pg_indexes')->where('schemaname', $this->pipelineSchema)
            ->where('indexname', 'UX_McrDocument_Number')->exists())->toBeTrue();
});

it('allocates fifty gapless correlatives for each GRE series', function (string $type) {
    $operations = [];
    foreach (range(1, 50) as $number) {
        $payload = despatchPayload($type);
        $payload['destinatario']['razon_social'] = 'GRE destination '.$number;
        $operations[] = ['context' => pipelineContext("gre-number-$type-$number"), 'payload' => $payload];
    }
    $results = concurrentAdmissions($operations);
    $correlatives = McrDocument::where('McrDocumentType', $type)->orderBy('McrCorrelative')->pluck('McrCorrelative')->map(fn ($v) => (int) $v)->all();
    expect(collect($results)->where('ok', true))->toHaveCount(50)->and($correlatives)->toBe(range(1, 50))
        ->and((int) DB::table('McrSeries')->where('McrDocumentType', $type)->value('McrNextCorrelative'))->toBe(51);
})->with(['09', '31']);

it('deduplicates twenty concurrent GRE admissions and external references', function (string $type) {
    $sameKey = array_fill(0, 20, ['context' => pipelineContext("gre-idem-$type"), 'payload' => despatchPayload($type)]);
    $first = concurrentAdmissions($sameKey);
    expect(collect($first)->where('ok', true))->toHaveCount(20)->and(collect($first)->pluck('document_id')->unique())->toHaveCount(1);
    $operations = [];
    foreach (range(1, 20) as $i) {
        $operations[] = ['context' => pipelineContext("gre-ext-$type-$i", "WMS-GRE-$type-100"), 'payload' => despatchPayload($type)];
    }
    $second = concurrentAdmissions($operations);
    expect(collect($second)->where('ok', true))->toHaveCount(20)->and(collect($second)->pluck('document_id')->unique())->toHaveCount(1);
})->with(['09', '31']);

it('scopes a simultaneous shared key across clients and companies', function () {
    $clientB = McrApiClient::create([
        'McrCode' => 'parallel-client-b', 'McrName' => 'Parallel client B', 'McrIsActive' => true,
        'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
    ]);
    $companyB = Empresa::create([
        'McrRuc' => '20999999991', 'McrBusinessName' => 'Parallel company B', 'McrEnvironment' => 'beta',
        'McrIsActive' => true, 'SecStatus' => true, 'McrIgvRate' => 18,
    ]);
    $establishmentB = McrEstablishment::create([
        'McrCompanyConfigID' => $companyB->getKey(), 'McrExternalCode' => 'RST-BRANCH-1',
        'McrSunatCode' => '0000', 'McrName' => 'Parallel B', 'McrAddress' => 'Av. Parallel 1',
        'McrUbigeo' => '150101', 'McrCountryCode' => 'PE', 'McrIsDefault' => true,
        'McrIsActive' => true, 'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
    ]);
    McrSeries::create([
        'McrCompanyConfigID' => $companyB->getKey(), 'McrEstablishmentID' => $establishmentB->getKey(), 'McrDocumentType' => '01', 'McrSeriesCode' => 'F001',
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

it('deduplicates twenty concurrent note admissions by scoped key', function (string $type) {
    $original = persistedOriginal();
    $payload = notePayload($type, 'internal', $original->getKey());
    $operations = array_fill(0, 20, ['context' => pipelineContext('concurrent-note-'.$type), 'payload' => $payload]);
    $results = concurrentAdmissions($operations);

    expect(collect($results)->where('ok', true))->toHaveCount(20)
        ->and(collect($results)->pluck('document_id')->unique())->toHaveCount(1)
        ->and(McrDocument::where('McrDocumentType', $type)->count())->toBe(1)
        ->and(DB::table('McrDocumentReference')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
})->with(['07', '08']);

it('deduplicates twenty concurrent note external references with different keys', function (string $type) {
    $operations = [];
    foreach (range(1, 20) as $number) {
        $operations[] = [
            'context' => pipelineContext("note-ext-$type-$number", "ERP-NOTE-$type"),
            'payload' => notePayload($type, 'external'),
        ];
    }
    $results = concurrentAdmissions($operations);

    expect(collect($results)->where('ok', true))->toHaveCount(20)
        ->and(collect($results)->pluck('document_id')->unique())->toHaveCount(1)
        ->and(McrDocument::where('McrDocumentType', $type)->count())->toBe(1)
        ->and(DB::table('McrIdempotency')->count())->toBe(20)
        ->and(DB::table('jobs')->count())->toBe(1);
})->with(['07', '08']);

it('allocates fifty gapless correlatives for each note series', function (string $type) {
    $originals = $type === '07'
        ? collect(range(1, 50))->map(fn () => persistedOriginal())->all()
        : [persistedOriginal()];
    $operations = [];
    foreach (range(1, 50) as $number) {
        $original = $originals[$type === '07' ? $number - 1 : 0];
        $payload = notePayload($type, 'internal', $original->getKey());
        $payload['reference']['reason'] = "Concurrent adjustment $number";
        $operations[] = ['context' => pipelineContext("number-note-$type-$number"), 'payload' => $payload];
    }
    $results = concurrentAdmissions($operations);
    $correlatives = McrDocument::where('McrDocumentType', $type)->orderBy('McrCorrelative')
        ->pluck('McrCorrelative')->map(fn ($value) => (int) $value)->all();

    expect(collect($results)->where('ok', true))->toHaveCount(50)
        ->and($correlatives)->toBe(range(1, 50))
        ->and(DB::table('McrDocumentReference')->count())->toBe(50)
        ->and((int) DB::table('McrSeries')->where('McrDocumentType', $type)->where('McrSeriesCode', $type === '07' ? 'FC01' : 'FD01')->value('McrNextCorrelative'))->toBe(51);
})->with(['07', '08']);

it('allows two distinct concurrent notes over the same internal document', function () {
    $original = persistedOriginal();
    $credit = notePayload('07', 'internal', $original->getKey());
    $debit = notePayload('08', 'internal', $original->getKey());
    $results = concurrentAdmissions([
        ['context' => pipelineContext('same-origin-credit'), 'payload' => $credit],
        ['context' => pipelineContext('same-origin-debit'), 'payload' => $debit],
    ]);

    expect(collect($results)->where('ok', true))->toHaveCount(2)
        ->and(McrDocument::whereIn('McrDocumentType', ['07', '08'])->count())->toBe(2)
        ->and(DB::table('McrDocumentReference')->where('ReferencedMcrDocumentID', $original->getKey())->count())->toBe(2);
});

it('serializes concurrent credits on one origin and prevents accumulated over-credit', function () {
    $original = persistedOriginal();
    $payloadA = notePayload('07', 'internal', $original->getKey());
    $payloadB = notePayload('07', 'internal', $original->getKey());
    foreach ([&$payloadA, &$payloadB] as &$payload) {
        $payload['mtoOperGravada'] = $payload['items'][0]['mtoBaseIgv'] = $payload['items'][0]['mtoValorVenta'] = '60.00';
        $payload['mtoIGV'] = $payload['items'][0]['igv'] = '10.80';
        $payload['mtoTotal'] = $payload['items'][0]['mtoPrecioUnitario'] = '70.80';
    }
    unset($payload);
    $results = concurrentAdmissions([
        ['context' => pipelineContext('over-credit-a'), 'payload' => $payloadA],
        ['context' => pipelineContext('over-credit-b'), 'payload' => $payloadB],
    ]);

    expect(collect($results)->where('ok', true))->toHaveCount(1)
        ->and(collect($results)->where('status', 422))->toHaveCount(1)
        ->and(McrDocument::where('McrDocumentType', '07')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('rolls back credential additions without changing existing fiscal identities', function () {
    $document = persistedOriginal();
    $before = $document->only(['McrCompanyConfigID', 'McrSeriesCode', 'McrCorrelative', 'McrStatus']);
    $migration = require database_path('migrations/2026_10_03_000000_create_api_credentials.php');
    foreach ([1, 2] as $run) {
        $migration->down();
        expect($document->fresh()->only(array_keys($before)))->toBe($before);
        $migration->up();
    }
    expect(DB::table('api_credentials')->count())->toBe(0);
});

it('allows only one of two concurrent pollers to perform the remote action', function () {
    $op = app(AdmitElectronicDocument::class)->execute(pipelineContext('concurrent-recovery'), despatchPayload())->toArray();
    DB::table('McrDocument')->where('McrDocumentID', $op['document_id'])->update(['McrStatus' => 'awaiting_sunat']);
    DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $op['submission_id'])->update([
        'McrStatus' => 'awaiting_sunat', 'McrTicket' => 'durable-ticket', 'McrNextAttemptAt' => now()->subSecond(), 'McrClaimedAt' => null, 'McrClaimToken' => null,
    ]);
    RecoveryCountingTransport::$path = sys_get_temp_dir().'/recovery-count-'.bin2hex(random_bytes(5));
    file_put_contents(RecoveryCountingTransport::$path, '0');
    $children = [];
    foreach (range(1, 2) as $_) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::disconnect(config('database.default'));
            (new PollSunatSubmissionJob($op['submission_id']))->handle(new RecoveryCountingTransport, app(GreCdrParser::class), app(DocumentLifecycle::class));
            exit(0);
        } $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    } DB::disconnect(config('database.default'));
    expect((int) file_get_contents(RecoveryCountingTransport::$path))->toBe(1)
        ->and(DB::table('McrSunatAttempt')->where('McrTransport', 'gre_poll')->count())->toBe(1);
    unlink(RecoveryCountingTransport::$path);
});

it('uses the recovery due index and preserves all six document types through recovery migration down up', function () {
    foreach (['01', '03', '07', '08', '09', '31'] as $index => $type) {
        DB::table('McrDocument')->insert([
            'McrCompanyConfigID' => Empresa::value('McrCompanyConfigID'), 'McrDocumentType' => $type, 'McrSeriesCode' => match ($type) {
                '01' => 'F001','03' => 'B001','07' => 'FC01','08' => 'FD01','09' => 'T001',default => 'V001'
            },
            'McrCorrelative' => 900 + $index, 'McrIssueDate' => '2026-09-13', 'McrCurrencyCode' => 'PEN', 'McrCustomerDocumentType' => '6',
            'McrCustomerDocumentNumber' => '20123456789', 'McrCustomerName' => 'Migration preservation', 'McrTotalAmount' => 0, 'McrStatus' => 'accepted',
            'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
        ]);
    }
    $before = DB::table('McrDocument')->where('McrCorrelative', '>=', 900)->orderBy('McrDocumentType')->get(['McrDocumentType', 'McrSeriesCode', 'McrCorrelative'])->toJson();
    $migration = require database_path('migrations/2026_09_12_000200_create_mcr_submission_and_outbox_tables.php');
    $migration->down();
    $migration->up();
    $after = DB::table('McrDocument')->where('McrCorrelative', '>=', 900)->orderBy('McrDocumentType')->get(['McrDocumentType', 'McrSeriesCode', 'McrCorrelative'])->toJson();
    DB::statement('SET enable_seqscan = off');
    $plan = collect(DB::select('EXPLAIN SELECT * FROM "McrSunatSubmission" WHERE "McrStatus" = ? AND "McrNextAttemptAt" <= now()', ['retry_pending']))
        ->pluck('QUERY PLAN')->implode(' ');
    DB::statement('SET enable_seqscan = on');
    expect($after)->toBe($before)->and($plan)->toContain('IX_McrSunatSubmission_RecoveryDue');
});

it('claims one webhook delivery once across concurrent PostgreSQL workers', function () {
    $op = app(AdmitElectronicDocument::class)->execute(pipelineContext('webhook-claim'), pipelinePayload())->toArray();
    $attempt = app(DocumentLifecycle::class)->claim($op['document_id'], $op['submission_id']);
    app(DocumentLifecycle::class)->finish($op['document_id'], $op['submission_id'], $attempt, new ProcessingResult(DocumentState::Accepted, '0', 'Accepted'), 1);
    $event = DB::table('McrOutboxEvent')->first();
    $subscription = (int) DB::table('McrWebhookSubscription')->insertGetId([
        'McrApiClientID' => $event->McrApiClientID, 'McrCompanyConfigID' => $event->McrCompanyConfigID, 'McrUrl' => 'https://example.com/hook', 'McrIsEnabled' => true,
        'McrEncryptedSecret' => Crypt::encryptString('secret'), 'McrEventTypes' => json_encode(['document.accepted']),
        'McrCreatedAt' => now(), 'McrUpdatedAt' => now()], 'McrWebhookSubscriptionID');
    $delivery = (int) DB::table('McrWebhookDelivery')->insertGetId(['McrOutboxEventID' => $event->McrOutboxEventID, 'McrWebhookSubscriptionID' => $subscription,
        'McrStatus' => 'pending', 'McrNextAttemptAt' => now()->subSecond(), 'McrCreatedAt' => now(), 'McrUpdatedAt' => now()], 'McrWebhookDeliveryID');
    ConcurrentWebhookTransport::$path = sys_get_temp_dir().'/webhook-claim-'.bin2hex(random_bytes(5));
    file_put_contents(ConcurrentWebhookTransport::$path, '0');
    $children = [];
    foreach (range(1, 2) as $_) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::disconnect(config('database.default'));
            (new DeliverWebhookJob($delivery))->handle(new ConcurrentWebhookTransport, app(WebhookBackoffPolicy::class));
            exit(0);
        }$children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }DB::disconnect(config('database.default'));
    expect((int) file_get_contents(ConcurrentWebhookTransport::$path))->toBe(1)->and(DB::table('McrWebhookDeliveryAttempt')->count())->toBe(1)
        ->and(DB::table('McrWebhookDelivery')->where('McrWebhookDeliveryID', $delivery)->value('McrStatus'))->toBe('delivered');
    unlink(ConcurrentWebhookTransport::$path);
});

it('deduplicates concurrent publication of the same document state version', function () {
    $op = app(AdmitElectronicDocument::class)->execute(pipelineContext('event-race'), pipelinePayload())->toArray();
    $attempt = app(DocumentLifecycle::class)->claim($op['document_id'], $op['submission_id']);
    app(DocumentLifecycle::class)->finish($op['document_id'], $op['submission_id'], $attempt, new ProcessingResult(DocumentState::Accepted, '0', 'Accepted'), 1);
    DB::table('McrOutboxEvent')->delete();
    $children = [];
    foreach (range(1, 2) as $_) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::disconnect(config('database.default'));
            $doc = McrDocument::findOrFail($op['document_id']);
            app(DocumentIntegrationEventPublisher::class)->publish($doc, $op['submission_id'], DocumentState::Accepted, (int) $doc->McrStateVersion);
            exit(0);
        }$children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }DB::disconnect(config('database.default'));
    expect(DB::table('McrOutboxEvent')->count())->toBe(1);
});

it('keeps historical documents event-free and uses PostgreSQL outbox indexes after down up', function () {
    foreach (['01', '03', '07', '08', '09', '31'] as $i => $type) {
        DB::table('McrDocument')->insert(['McrCompanyConfigID' => Empresa::value('McrCompanyConfigID'),
            'McrDocumentType' => $type, 'McrSeriesCode' => match ($type) {
                '01' => 'F001','03' => 'B001','07' => 'FC01','08' => 'FD01','09' => 'T001',default => 'V001'
            },
            'McrCorrelative' => 1200 + $i, 'McrIssueDate' => '2026-09-13', 'McrCurrencyCode' => 'PEN', 'McrCustomerDocumentType' => '6', 'McrCustomerDocumentNumber' => '20123456789',
            'McrCustomerName' => 'Historical no outbox', 'McrTotalAmount' => 0, 'McrStatus' => 'accepted', 'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now()]);
    }
    $migration = require database_path('migrations/2026_09_12_000200_create_mcr_submission_and_outbox_tables.php');
    $migration->down();
    $migration->up();
    expect(DB::table('McrDocument')->where('McrCorrelative', '>=', 1200)->where('McrStateVersion', 0)->count())->toBe(6)->and(DB::table('McrOutboxEvent')->count())->toBe(0);
    DB::statement('SET enable_seqscan=off');
    $deliveryPlan = collect(DB::select('EXPLAIN SELECT * FROM "McrWebhookDelivery" WHERE "McrStatus"=? AND "McrNextAttemptAt"<=now()', ['retrying']))->pluck('QUERY PLAN')->implode(' ');
    $eventPlan = collect(DB::select('EXPLAIN SELECT * FROM "McrOutboxEvent" WHERE "McrFannedOutAt" IS NULL ORDER BY "McrOccurredAt" LIMIT 1'))->pluck('QUERY PLAN')->implode(' ');
    DB::statement('SET enable_seqscan=on');
    expect($deliveryPlan)->toContain('IX_McrWebhookDelivery_Due')->and($eventPlan)->toContain('IX_McrOutboxEvent_Pending');
});

it('preserves document references when fiscal operation additions roll back', function () {
    $source = persistedOriginal();
    $note = app(AdmitElectronicDocument::class)->execute(pipelineContext('reference-preservation'), notePayload('07', 'internal', $source->getKey()));
    $before = DB::table('McrDocumentReference')->where('McrDocumentID', $note->documentId)->first();
    $migration = require database_path('migrations/2026_10_03_000100_create_fiscal_operations.php');
    foreach ([1, 2] as $run) {
        $migration->down();
        $migration->up();
        $after = DB::table('McrDocumentReference')->where('McrDocumentID', $note->documentId)->first();
        expect((int) $after->ReferencedMcrDocumentID)->toBe($source->getKey())
            ->and($after->McrReferencedNumber)->toBe($before->McrReferencedNumber);
    }
    try {
        DB::table('McrDocument')->where('McrDocumentID', $source->getKey())->delete();
        test()->fail('Internal reference FK must prevent deletion of an origin.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBeIn(['23503', '23001']);
    }
});
