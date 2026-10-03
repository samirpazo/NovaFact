<?php

namespace App\Services\Fiscal;

use App\Jobs\ProcessFiscalOperationJob;
use App\Models\Empresa;
use App\Models\FiscalOperation;
use App\Models\McrDocument;
use App\Services\Documents\AdmissionContext;
use App\Services\Documents\PayloadCodec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class AdmitFiscalOperation
{
    public function execute(AdmissionContext $context, string $kind, array $payload): FiscalOperation
    {
        if (! in_array($kind, ['summary', 'void'], true)) {
            throw new UnprocessableEntityHttpException('Unsupported fiscal operation.');
        }
        if (! preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $context->idempotencyKey)) {
            throw new UnprocessableEntityHttpException('Invalid Idempotency-Key.');
        }
        $hash = PayloadCodec::hash(['kind' => $kind, 'payload' => $payload]);

        return DB::transaction(function () use ($context, $kind, $payload, $hash) {
            $company = Empresa::whereKey($context->companyId)->where('McrIsActive', true)->where('SecStatus', true)->lockForUpdate()->firstOrFail();
            $existing = FiscalOperation::where('client_id', $context->clientId)->where('company_id', $company->getKey())
                ->where('idempotency_key', $context->idempotencyKey)->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new ConflictHttpException('Idempotency-Key was used for another fiscal operation.');
                }

                return $existing;
            }
            $query = McrDocument::where('McrCompanyConfigID', $company->getKey())->where('McrApiClientID', $context->clientId);
            if ($kind === 'void') {
                if (($payload['not_delivered'] ?? false) !== true) {
                    throw ValidationException::withMessages(['not_delivered' => 'A void requires not_delivered=true; use a credit note for granted documents.']);
                }
                $query->whereKey($payload['document_id'] ?? 0);
                $documents = $query->lockForUpdate()->get();
                if ($documents->isEmpty()) {
                    throw new NotFoundHttpException('Document not found.');
                }
                $doc = $documents->first();
                if (! in_array($doc->McrDocumentType, ['01', '03', '07', '08'], true) || ! in_array($doc->McrStatus, ['accepted', 'accepted_with_observations', 'void_rejected'], true)) {
                    throw new UnprocessableEntityHttpException('Document cannot be voided in this state.');
                }
                $acceptedAt = DB::table('McrSunatSubmission')->where('McrDocumentID', $doc->getKey())
                    ->whereIn('McrStatus', ['accepted', 'accepted_with_observations'])->max('McrCompletedAt') ?? $doc->McrIssueDate;
                if (CarbonImmutable::parse($acceptedAt)->startOfDay()->addDays(7)->lt(CarbonImmutable::today())) {
                    throw new UnprocessableEntityHttpException('The seven calendar day void deadline has expired.');
                }
                $date = substr((string) $doc->McrIssueDate, 0, 10);
                if (isset($payload['fecha']) && $payload['fecha'] !== $date) {
                    throw new UnprocessableEntityHttpException('fecha does not match the original document.');
                }
                if (trim((string) ($payload['motivo'] ?? '')) === '' || strlen($payload['motivo']) > 500) {
                    throw new UnprocessableEntityHttpException('motivo is required (maximum 500 bytes).');
                }
                $protocol = str_starts_with($doc->McrSeriesCode, 'B') ? 'RC' : 'RA';
            } else {
                $date = $payload['fecha'] ?? '';
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
                if (! $parsed || $parsed->format('Y-m-d') !== $date || $parsed->gt(CarbonImmutable::today()) || $parsed->addDays(7)->lt(CarbonImmutable::today())) {
                    throw new UnprocessableEntityHttpException('Summary date must be within the last seven calendar days.');
                }
                if (isset($payload['currency'])) {
                    $query->where('McrCurrencyCode', $payload['currency']);
                }
                $reserved = DB::table('fiscal_operation_items as i')->join('fiscal_operations as o', 'o.id', '=', 'i.operation_id')
                    ->select('i.document_id')->where(function ($q) {
                        $q->whereIn('o.state', ['queued', 'processing', 'pending', 'polling', 'manual_review'])
                            ->orWhere(fn ($accepted) => $accepted->where('o.kind', 'summary')->where('o.state', 'accepted'));
                    });
                $documents = $query->whereNotIn('McrDocumentID', $reserved)->whereIn('McrDocumentType', ['03', '07', '08'])->where('McrSeriesCode', 'like', 'B%')
                    ->whereIn('McrStatus', ['accepted', 'accepted_with_observations'])->whereDate('McrIssueDate', $date)->orderBy('McrDocumentID')->lockForUpdate()->get();
                $protocol = 'RC';
                if ($documents->isEmpty()) {
                    throw new UnprocessableEntityHttpException('No accepted receipt-family documents for this date.');
                }
            }
            $busy = DB::table('fiscal_operation_items as i')->join('fiscal_operations as o', 'o.id', '=', 'i.operation_id')
                ->whereIn('i.document_id', $documents->modelKeys())->where(function ($q) use ($kind) {
                    $q->whereIn('o.state', ['queued', 'processing', 'pending', 'polling', 'manual_review'])
                        ->orWhere(fn ($accepted) => $accepted->where('o.kind', $kind)->where('o.state', 'accepted'));
                })->exists();
            if ($busy) {
                throw new ConflictHttpException('Documents already belong to an active or accepted operation.');
            }
            if ($kind === 'summary' && $documents->pluck('McrCurrencyCode')->unique()->count() !== 1) {
                throw new UnprocessableEntityHttpException('Separate summaries are required for each currency.');
            }
            $number = ((int) FiscalOperation::where('company_id', $company->getKey())->where('protocol', $protocol)->where('issue_date', today()->toDateString())->max('correlative')) + 1;
            $snapshot = $documents->map(fn ($doc) => ['id' => $doc->getKey(), 'type' => $doc->McrDocumentType, 'series' => $doc->McrSeriesCode,
                'number' => $doc->McrCorrelative, 'customer_type' => $doc->McrCustomerDocumentType, 'customer_number' => $doc->McrCustomerDocumentNumber,
                'currency' => $doc->McrCurrencyCode, 'total' => (string) $doc->McrTotalAmount, 'taxable' => (string) $doc->McrTaxableAmount, 'tax' => (string) $doc->McrTaxAmount,
                'reference' => DB::table('McrDocumentReference')->where('McrDocumentID', $doc->getKey())->first(['McrReferencedDocumentType', 'McrReferencedNumber'])])->all();
            $operation = FiscalOperation::create(['client_id' => $context->clientId, 'company_id' => $company->getKey(), 'kind' => $kind,
                'protocol' => $protocol, 'issue_date' => today()->toDateString(), 'reference_date' => $date, 'correlative' => $number,
                'identifier' => $company->McrRuc.'-'.$protocol.'-'.today()->format('Ymd').'-'.$number,
                'idempotency_key' => $context->idempotencyKey, 'request_hash' => $hash,
                'payload' => ['documents' => $snapshot, 'reason' => $payload['motivo'] ?? null], 'state' => 'queued']);
            DB::table('fiscal_operation_items')->insert(array_map(fn ($doc) => ['operation_id' => $operation->id, 'document_id' => $doc['id']], $snapshot));
            Queue::connection('documents')->push(new ProcessFiscalOperationJob($operation->id));

            return $operation;
        }, 5);
    }
}
