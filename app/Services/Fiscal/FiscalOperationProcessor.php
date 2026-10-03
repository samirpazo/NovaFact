<?php

namespace App\Services\Fiscal;

use App\Enums\DocumentState;
use App\Models\Empresa;
use App\Models\FiscalOperation;
use App\Services\Documents\DocumentLifecycle;
use App\Services\Sunat\GreenterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class FiscalOperationProcessor
{
    public function __construct(private GreenterService $transport, private FiscalOperationXmlBuilder $builder) {}

    public function process(int $id): void
    {
        $attempt = $this->claim($id, 'queued', 'processing', 'send');
        if ($attempt === null) {
            return;
        }
        $operation = FiscalOperation::findOrFail($id);
        try {
            $company = Empresa::findOrFail($operation->company_id);
            if ($company->McrEnvironment !== (config('sunat.production') ? 'production' : 'beta')) {
                throw new \LogicException('Environment mismatch.');
            }
            $disk = Storage::disk('local');
            if ($operation->xml_path) {
                $xml = $disk->get($operation->xml_path);
                if (! hash_equals($operation->xml_hash, hash('sha256', $xml))) {
                    throw new \LogicException('XML integrity failed.');
                }
            } else {
                $xml = $this->transport->getXml($this->builder->build($operation), $company);
                if ($xml === '') {
                    throw new \LogicException('Empty signed XML.');
                }
                $path = 'facturacion/operations/'.$operation->identifier.'.xml';
                if (! $disk->put($path, $xml)) {
                    throw new \RuntimeException('XML persistence failed.');
                }
                $operation->update(['xml_path' => $path, 'xml_hash' => hash('sha256', $xml)]);
            }
            // Durable remote-contact marker precedes the external effect. Unknown outcomes never resend.
            $operation->update(['remote_started_at' => now()]);
            $result = $this->transport->sendFiscalXml($operation->protocol, $operation->identifier, $xml, $company);
            if ($result->isSuccess() && is_string($result->getTicket()) && $result->getTicket() !== '') {
                $this->finish($operation, $attempt, 'pending', ['ticket' => $result->getTicket(), 'next_attempt_at' => now()]);
            } else {
                $code = (string) $result->getError()?->getCode();
                $state = ctype_digit($code) && (int) $code >= 2000 && (int) $code < 4000 ? 'rejected' : 'manual_review';
                $this->finish($operation, $attempt, $state, ['error' => 'SUNAT did not accept the submission.'], $code);
            }
        } catch (\Throwable $e) {
            $operation->refresh();
            $this->finish($operation, $attempt, ($operation->remote_started_at || $operation->attempts >= 20) ? 'manual_review' : 'queued',
                ['error' => $operation->remote_started_at ? 'Remote result is ambiguous; do not resend.' : 'Local processing failed before remote contact.', 'next_attempt_at' => now()->addMinute()]);
        }
    }

    public function poll(int $id): void
    {
        $attempt = $this->claim($id, 'pending', 'polling', 'poll');
        if ($attempt === null) {
            return;
        }
        $operation = FiscalOperation::findOrFail($id);
        try {
            $company = Empresa::findOrFail($operation->company_id);
            if ($company->McrEnvironment !== (config('sunat.production') ? 'production' : 'beta')) {
                throw new \LogicException('Environment mismatch.');
            }
            $result = $this->transport->getStatus($operation->ticket, $company);
            $code = (string) $result->getCode();
            if ($code === '98') {
                $this->pending($operation, $attempt);

                return;
            }
            $cdr = $result->getCdrResponse();
            $zip = $result->getCdrZip();
            if (! $result->isSuccess() || ! $cdr || ! is_string($zip) || $zip === '') {
                $this->pending($operation, $attempt);

                return;
            }
            $cdrCode = (string) $cdr->getCode();
            if (! ctype_digit($cdrCode)) {
                $this->pending($operation, $attempt);

                return;
            }
            $state = $cdrCode === '0' || (int) $cdrCode >= 4000 ? 'accepted' : 'rejected';
            $path = 'facturacion/operations/R-'.$operation->identifier.'.zip';
            if (! Storage::disk('local')->put($path, $zip)) {
                throw new \RuntimeException('CDR persistence failed.');
            }
            DB::transaction(function () use ($operation, $attempt, $state, $path, $cdrCode) {
                $this->finish($operation, $attempt, $state, ['cdr_path' => $path, 'error' => $state === 'rejected' ? 'SUNAT rejected the fiscal operation.' : null], $cdrCode);
                if ($state === 'accepted' && $operation->kind === 'void') {
                    foreach ($operation->payload['documents'] as $doc) {
                        $submission = DB::table('McrSunatSubmission')->where('McrDocumentID', $doc['id'])->orderByDesc('McrSunatSubmissionID')->first();
                        if ($submission) {
                            $lifecycle = app(DocumentLifecycle::class);
                            $lifecycle->transition($doc['id'], $submission->McrSunatSubmissionID, DocumentState::VoidPending);
                            $lifecycle->transition($doc['id'], $submission->McrSunatSubmissionID, DocumentState::VoidAccepted);
                        } else {
                            // Historical accepted documents may have no submission; retain a versioned fiscal status.
                            DB::table('McrDocument')->where('McrDocumentID', $doc['id'])->update(['McrStatus' => 'void_accepted', 'McrStateVersion' => DB::raw('"McrStateVersion" + 1')]);
                        }
                    }
                }
            });
        } catch (\Throwable $e) {
            $this->pending($operation, $attempt);
        }
    }

    private function claim(int $id, string $from, string $to, string $action): ?int
    {
        return DB::transaction(function () use ($id, $from, $to, $action) {
            $operation = FiscalOperation::lockForUpdate()->find($id);
            if (! $operation || $operation->state !== $from || $operation->next_attempt_at?->isFuture()) {
                return null;
            }
            if ($action === 'send' && $operation->remote_started_at) {
                $operation->update(['state' => 'manual_review']);

                return null;
            }
            $operation->update(['state' => $to, 'attempts' => $operation->attempts + 1]);

            return DB::table('fiscal_operation_attempts')->insertGetId(['operation_id' => $id, 'action' => $action, 'state' => $to, 'started_at' => now()]);
        });
    }

    private function pending(FiscalOperation $operation, int $attempt): void
    {
        $this->finish($operation, $attempt, $operation->attempts >= 20 ? 'manual_review' : 'pending',
            ['next_attempt_at' => now()->addMinute(), 'error' => 'Waiting for a verifiable CDR.']);
    }

    private function finish(FiscalOperation $operation, int $attempt, string $state, array $values = [], ?string $code = null): void
    {
        DB::transaction(function () use ($operation, $attempt, $state, $values, $code) {
            FiscalOperation::whereKey($operation->id)->update(['state' => $state, 'updated_at' => now()] + $values);
            DB::table('fiscal_operation_attempts')->where('id', $attempt)->update(['state' => $state, 'code' => $code, 'completed_at' => now()]);
        });
    }
}
