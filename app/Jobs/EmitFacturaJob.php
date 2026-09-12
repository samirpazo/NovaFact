<?php

namespace App\Jobs;

use App\Actions\Facturacion\EmitFacturaAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class EmitFacturaJob implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public $tries = 3;

    public $backoff = [10, 30, 60];

    public function __construct(public array $data, public int $submissionId) {}

    public function handle(EmitFacturaAction $action): void
    {
        // Cada ejecución del worker representa un intento real de comunicación con SUNAT.
        DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->update([
            'McrAttemptNumber' => DB::raw('COALESCE("McrAttemptNumber", 0) + 1'),
            'McrStatus' => 'processing', 'McrSentAt' => now(), 'McrError' => null, 'McrUpdatedAt' => now(),
        ]);
        if (! empty($this->data['_idempotency_key'])) {
            $existing = DB::table('McrDocument')->where('McrIdempotencyKey', $this->data['_idempotency_key'])->where('SecStatus', true)->first();
            if ($existing) {
                DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->update(['McrDocumentID' => $existing->McrDocumentID, 'McrStatus' => 'accepted', 'McrCompletedAt' => now(), 'McrMetadata' => json_encode(['deduplicated' => true]), 'McrUpdatedAt' => now()]);
                $this->notifyNova('accepted', $existing->McrDocumentID);

                return;
            }
        }
        $result = $action->execute($this->data);
        DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->update(['McrDocumentID' => $result['document_id'] ?? null, 'McrStatus' => ($result['success'] ?? false) ? 'accepted' : 'rejected', 'McrTicket' => $result['ticket'] ?? null, 'McrError' => $result['error'] ?? null, 'McrCompletedAt' => now(), 'McrMetadata' => json_encode($result), 'McrUpdatedAt' => now()]);
        $this->notifyNova(($result['success'] ?? false) ? 'accepted' : 'rejected', $result['document_id'] ?? null, $result['error'] ?? null);
    }

    public function failed(\Throwable $e): void
    {
        DB::table('McrSunatSubmission')->where('McrSunatSubmissionID', $this->submissionId)->update(['McrStatus' => 'failed', 'McrError' => $e->getMessage(), 'McrCompletedAt' => now(), 'McrUpdatedAt' => now()]);
        $this->notifyNova('failed', null, $e->getMessage());
    }

    private function notifyNova(string $status, ?int $documentId, ?string $error = null): void
    {
        $url = env('NOVA_BILLING_CALLBACK_URL', 'http://127.0.0.1:8080/RstSale/billing-callback');
        $token = env('NOVA_BILLING_CALLBACK_TOKEN', env('BILLING_SERVICE_TOKEN'));
        if (! $url || ! $token) {
            return;
        }
        try {
            Http::timeout(5)->withToken($token)->post($url, ['submission_id' => $this->submissionId, 'status' => $status, 'document_id' => $documentId, 'error' => $error]);
        } catch (\Throwable) { /* callback best effort; state remains queryable */
        }
    }
}
