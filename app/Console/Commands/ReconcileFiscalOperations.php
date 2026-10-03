<?php

namespace App\Console\Commands;

use App\Jobs\PollFiscalOperationJob;
use App\Jobs\ProcessFiscalOperationJob;
use App\Models\FiscalOperation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final class ReconcileFiscalOperations extends Command
{
    protected $signature = 'billing:fiscal-reconcile {--limit=100}';

    protected $description = 'Recover durable summary and void operations without repeating ambiguous sends.';

    public function handle(): int
    {
        $ids = FiscalOperation::where(function ($q) {
            $q->whereIn('state', ['queued', 'pending'])->where(fn ($due) => $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orWhere(fn ($stale) => $stale->whereIn('state', ['processing', 'polling'])->where('updated_at', '<=', now()->subMinutes(5)));
        })->orderBy('id')->limit(max(1, min(1000, (int) $this->option('limit'))))->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $op = FiscalOperation::lockForUpdate()->findOrFail($id);
                if (in_array($op->state, ['processing', 'polling'], true) && $op->updated_at->lte(now()->subMinutes(5))) {
                    $op->update(['state' => $op->ticket ? 'pending' : ($op->remote_started_at ? 'manual_review' : 'queued')]);
                    DB::table('fiscal_operation_attempts')->where('operation_id', $op->id)->whereNull('completed_at')
                        ->update(['state' => 'interrupted', 'completed_at' => now()]);
                }
                if (! in_array($op->state, ['queued', 'pending'], true) || $op->next_attempt_at?->isFuture()) {
                    return;
                }
                Queue::connection('documents')->push($op->state === 'pending' ? new PollFiscalOperationJob($op->id) : new ProcessFiscalOperationJob($op->id));
            });
        }
        $this->info('Fiscal operations scheduled: '.$ids->count());

        return self::SUCCESS;
    }
}
