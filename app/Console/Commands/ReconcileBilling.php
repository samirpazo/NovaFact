<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileSunatSubmissionJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\McrDocument;
use App\Services\Documents\RecoveryEvidenceFactory;
use App\Services\Documents\SubmissionRecoveryPolicy;

final class ReconcileBilling extends Command
{
    protected $signature = 'billing:reconcile {--company=} {--document=} {--submission=} {--limit=100}';
    protected $description = 'Queue durable recovery decisions for due or stale fiscal submissions';

    public function handle(): int
    {
        $limit = min(max((int) $this->option('limit'), 1), 1000);
        $ids = DB::transaction(function () use ($limit) {
            $query = DB::table('McrSunatSubmission as s')->join('McrDocument as d', 'd.McrDocumentID', '=', 's.McrDocumentID')
                ->where(function ($q): void {
                    $q->where(function ($due): void {
                        $due->whereIn('s.McrStatus', ['queued', 'retry_pending', 'awaiting_sunat', 'reconciliation_pending'])
                            ->where(fn ($time) => $time->whereNull('s.McrNextAttemptAt')->orWhere('s.McrNextAttemptAt', '<=', now()));
                    })->orWhere(function ($stale): void {
                        $stale->where('s.McrStatus', 'processing')->where('s.McrClaimedAt', '<=', now()->subMinutes(2));
                    });
                });
            if ($this->option('company')) $query->where('d.McrCompanyConfigID', (int) $this->option('company'));
            if ($this->option('document')) $query->where('d.McrDocumentID', (int) $this->option('document'));
            if ($this->option('submission')) $query->where('s.McrSunatSubmissionID', (int) $this->option('submission'));
            $query->orderByRaw('COALESCE("s"."McrNextAttemptAt", "s"."McrUpdatedAt", "s"."CreateDate")')->limit($limit);
            DB::getDriverName() === 'pgsql' ? $query->lock('for update skip locked') : $query->lockForUpdate();
            return $query->pluck('s.McrSunatSubmissionID')->map(fn ($id) => (int) $id)->all();
        });
        $summary = ['examined' => count($ids), 'process_or_retry' => 0, 'polling' => 0, 'reconciliation' => 0,
            'artifact_recovery' => 0, 'terminal_ignored' => 0, 'manual_review' => 0, 'errors' => 0];
        foreach ($ids as $id) {
            try {
                $submission = DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $id)->first();
                $document = McrDocument::findOrFail($submission->McrDocumentID);
                $action = app(SubmissionRecoveryPolicy::class)->decide(app(RecoveryEvidenceFactory::class)->make($submission, $document));
                $bucket = match ($action->value) {
                    'process', 'retry_submission' => 'process_or_retry', 'poll_ticket' => 'polling', 'reconcile' => 'reconciliation',
                    'regenerate_artifacts' => 'artifact_recovery', 'manual_review' => 'manual_review', default => 'terminal_ignored',
                };
                $summary[$bucket]++;
                ReconcileSunatSubmissionJob::dispatch($id)->onConnection('documents');
            } catch (\Throwable) { $summary['errors']++; }
        }
        $this->table(['metric', 'count'], collect($summary)->map(fn ($count, $metric) => [$metric, $count])->values()->all());
        return self::SUCCESS;
    }
}
