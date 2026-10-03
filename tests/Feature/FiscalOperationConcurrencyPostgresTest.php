<?php

use App\Jobs\PollFiscalOperationJob;
use App\Jobs\ProcessFiscalOperationJob;
use App\Models\Empresa;
use App\Models\FiscalOperation;
use App\Services\Fiscal\AdmitFiscalOperation;
use App\Services\Fiscal\FiscalOperationProcessor;
use App\Services\Sunat\GreenterService;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\StatusResult;
use Greenter\Model\Response\SummaryResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/PipelineDatabase.php';

final class ConcurrentFiscalTransport extends GreenterService
{
    public function __construct(private string $counterPath) {}

    public function getXml(DocumentInterface $document, Empresa $company): string
    {
        return '<FiscalFixture><Signature>fixture</Signature></FiscalFixture>';
    }

    public function sendFiscalXml(string $protocol, string $identifier, string $xml, Empresa $company): object
    {
        $this->countRemoteEffect();

        return (new SummaryResult)->setSuccess(true)->setTicket('concurrent-fiscal-ticket');
    }

    public function getStatus(?string $ticket, Empresa $company): object
    {
        $this->countRemoteEffect();

        return (new StatusResult)->setSuccess(true)->setCode('98');
    }

    private function countRemoteEffect(): void
    {
        $handle = fopen($this->counterPath, 'c+');
        flock($handle, LOCK_EX);
        $count = (int) stream_get_contents($handle);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string) ($count + 1));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        usleep(300000);
    }
}

beforeEach(function () {
    if (getenv('PIPELINE_TEST_POSTGRES') !== '1') {
        $this->markTestSkipped('Requires PIPELINE_TEST_POSTGRES=1 and independent PostgreSQL connections.');
    }
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

function runConcurrentFiscalTasks(array $tasks, Closure $execute): array
{
    $children = [];
    // Close the parent's connection before fork so child disconnect cannot terminate a shared socket.
    DB::disconnect(config('database.default'));
    foreach ($tasks as $task) {
        $path = sys_get_temp_dir().'/nova-fiscal-race-'.bin2hex(random_bytes(8)).'.json';
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Cannot fork fiscal test worker.');
        }
        if ($pid === 0) {
            try {
                DB::purge(config('database.default'));
                $result = $execute($task);
                $output = ['ok' => true, 'operation_id' => $result];
            } catch (Throwable $exception) {
                $output = ['ok' => false, 'class' => get_class($exception),
                    'status' => method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : null];
            }
            file_put_contents($path, json_encode($output, JSON_THROW_ON_ERROR));
            exit(0);
        }
        $children[] = [$pid, $path];
    }
    $results = [];
    foreach ($children as [$pid, $path]) {
        pcntl_waitpid($pid, $status);
        if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0 || ! is_file($path)) {
            throw new RuntimeException('Fiscal test worker failed.');
        }
        $results[] = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        unlink($path);
    }
    DB::purge(config('database.default'));

    return $results;
}

function concurrentlyAdmitFiscal(array $tasks): array
{
    return runConcurrentFiscalTasks($tasks, fn ($task) => app(AdmitFiscalOperation::class)
        ->execute($task['context'], $task['kind'], $task['payload'])->getKey());
}

function concurrentFiscalVoid(int $id, string $reason = 'Documento no entregado'): array
{
    return ['document_id' => $id, 'motivo' => $reason, 'not_delivered' => true];
}

it('deduplicates twenty concurrent summaries into one operation and one job', function () {
    $results = concurrentlyAdmitFiscal(array_fill(0, 20, [
        'context' => pipelineContext('parallel-summary-001'), 'kind' => 'summary', 'payload' => ['fecha' => now()->toDateString()],
    ]));
    expect(collect($results)->where('ok', true))->toHaveCount(20)
        ->and(collect($results)->pluck('operation_id')->unique())->toHaveCount(1)
        ->and(FiscalOperation::count())->toBe(1)->and(DB::table('jobs')->count())->toBe(1)
        ->and((int) FiscalOperation::value('correlative'))->toBe(1);
});

it('allows one winner and one conflict for incompatible concurrent fiscal replay', function () {
    $results = concurrentlyAdmitFiscal([
        ['context' => pipelineContext('parallel-conflict-001'), 'kind' => 'void', 'payload' => concurrentFiscalVoid($this->receipt->getKey())],
        ['context' => pipelineContext('parallel-conflict-001'), 'kind' => 'void', 'payload' => concurrentFiscalVoid($this->receipt->getKey(), 'Otro motivo')],
    ]);
    expect(collect($results)->where('ok', true))->toHaveCount(1)->and(collect($results)->where('status', 409))->toHaveCount(1)
        ->and(FiscalOperation::count())->toBe(1)->and(DB::table('jobs')->count())->toBe(1);
});

it('allocates twenty unique gapless fiscal numbers for concurrent receipt voids', function () {
    $tasks = [];
    foreach (range(1, 20) as $number) {
        $receipt = $number === 1 ? $this->receipt : persistedOriginal('03');
        $receipt->update(['McrIssueDate' => now()->toDateString(), 'McrIssuedAt' => now()]);
        $tasks[] = ['context' => pipelineContext('parallel-number-'.$number), 'kind' => 'void', 'payload' => concurrentFiscalVoid($receipt->getKey())];
    }
    $results = concurrentlyAdmitFiscal($tasks);
    expect(collect($results)->where('ok', true))->toHaveCount(20)
        ->and(FiscalOperation::orderBy('correlative')->pluck('correlative')->map(fn ($value) => (int) $value)->all())->toBe(range(1, 20))
        ->and(FiscalOperation::pluck('identifier')->unique())->toHaveCount(20)
        ->and(DB::table('jobs')->count())->toBe(20);
});

it('reserves one document for only one of two concurrent void keys', function () {
    $results = concurrentlyAdmitFiscal([
        ['context' => pipelineContext('parallel-document-001'), 'kind' => 'void', 'payload' => concurrentFiscalVoid($this->receipt->getKey())],
        ['context' => pipelineContext('parallel-document-002'), 'kind' => 'void', 'payload' => concurrentFiscalVoid($this->receipt->getKey())],
    ]);
    expect(collect($results)->where('ok', true))->toHaveCount(1)->and(collect($results)->where('status', 409))->toHaveCount(1)
        ->and(FiscalOperation::count())->toBe(1)->and(DB::table('fiscal_operation_items')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('performs the fiscal remote action once across two concurrent workers', function (string $action) {
    $operation = app(AdmitFiscalOperation::class)->execute(pipelineContext('parallel-worker-'.$action), 'void', concurrentFiscalVoid($this->receipt->getKey()));
    if ($action === 'poll') {
        $operation->update(['state' => 'pending', 'ticket' => 'worker-ticket', 'next_attempt_at' => now()->subSecond()]);
    }
    $counterPath = sys_get_temp_dir().'/nova-fiscal-count-'.bin2hex(random_bytes(8));
    file_put_contents($counterPath, '0');
    try {
        $results = runConcurrentFiscalTasks([$operation->getKey(), $operation->getKey()], function ($id) use ($action, $counterPath) {
            app()->instance(GreenterService::class, new ConcurrentFiscalTransport($counterPath));
            $processor = app(FiscalOperationProcessor::class);
            $job = $action === 'send' ? new ProcessFiscalOperationJob($id) : new PollFiscalOperationJob($id);
            $job->handle($processor);

            return $id;
        });
        expect(collect($results)->where('ok', true))->toHaveCount(2)
            ->and((int) file_get_contents($counterPath))->toBe(1)
            ->and(DB::table('fiscal_operation_attempts')->where('operation_id', $operation->getKey())->where('action', $action)->count())->toBe(1)
            ->and($operation->fresh()->state)->toBe('pending');
    } finally {
        unlink($counterPath);
    }
})->with(['send', 'poll']);
