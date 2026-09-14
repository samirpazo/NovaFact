<?php

namespace App\Services\Documents;

use App\Enums\DocumentState;
use App\Enums\DocumentType;
use App\Jobs\ProcessElectronicDocumentJob;
use App\Models\Empresa;
use App\Models\McrApiClient;
use App\Models\McrDocument;
use App\Models\McrSeries;
use App\Support\DecimalAmount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class AdmitElectronicDocument
{
    public function __construct(private SalesPayloadNormalizer $normalizer, private DespatchPayloadNormalizer $despatchNormalizer, private NoteReferenceResolver $noteReferences) {}

    public function execute(AdmissionContext $context, array $payload): AdmissionResult
    {
        try {
            return DB::transaction(function () use ($context, $payload): AdmissionResult {
                $client = McrApiClient::whereKey($context->clientId)->where('McrIsActive', true)->where('SecStatus', true)->first();
                $company = Empresa::whereKey($context->companyId)->where('McrIsActive', true)->where('SecStatus', true)->first();
                if (! $client || ! $company) {
                    throw new UnprocessableEntityHttpException('Client and company must be active before admission.');
                }

                $isDespatch = DocumentType::tryFrom((string) ($payload['tipoDoc'] ?? ''))?->isDespatch() === true;
                $canonicalRequest = $isDespatch
                    ? $this->despatchNormalizer->request($payload)
                    : $this->normalizer->request($this->noteReferences->resolve($company, $payload));

                $series = $this->resolveConfiguredSeries($company, $canonicalRequest);
                $canonicalRequest['serie'] = $series->McrSeriesCode;
                $requestHash = PayloadCodec::hash($canonicalRequest);

                $identityId = (int) DB::table('McrIdempotency')->insertGetId([
                    'McrApiClientID' => $client->getKey(),
                    'McrCompanyConfigID' => $company->getKey(),
                    'McrKey' => $context->idempotencyKey,
                    'McrRequestHash' => $requestHash,
                    'McrStatus' => 'processing',
                    'McrCreatedAt' => now(),
                    'McrUpdatedAt' => now(),
                ], 'McrIdempotencyID');

                if (! $isDespatch) {
                    $this->noteReferences->validateAccumulatedCredit($canonicalRequest);
                }

                $series = McrSeries::whereKey($series->getKey())->lockForUpdate()->firstOrFail();
                if (! $series->McrIsActive || ! $series->SecStatus) {
                    throw new UnprocessableEntityHttpException('The configured series is inactive.');
                }
                $number = (int) $series->McrNextCorrelative;
                $processingPayload = $isDespatch
                    ? $this->despatchNormalizer->processing($canonicalRequest, $series->McrSeriesCode, $number)
                    : $this->normalizer->processing($canonicalRequest, $series->McrSeriesCode, $number, (float) ($company->McrIgvRate ?? 18));
                $json = PayloadCodec::encode($processingPayload);
                $payloadHash = hash('sha256', $json);
                $type = DocumentType::from($processingPayload['tipoDoc']);
                $recipient = $isDespatch ? $processingPayload['destinatario'] : null;

                $document = McrDocument::create([
                    'McrApiClientID' => $client->getKey(),
                    'McrCompanyConfigID' => $company->getKey(),
                    'McrSeriesID' => $series->getKey(),
                    'McrExternalReference' => $context->externalReference,
                    'McrRequestHash' => $requestHash,
                    'McrDocumentType' => $type->value,
                    'McrSeriesCode' => $processingPayload['serie'],
                    'McrCorrelative' => $number,
                    'McrIssueDate' => substr($processingPayload['fechaEmision'], 0, 10),
                    'McrIssuedAt' => $processingPayload['fechaEmision'],
                    'McrCurrencyCode' => $isDespatch ? 'PEN' : $processingPayload['tipoMoneda'],
                    'McrCustomerDocumentType' => $isDespatch ? $recipient['tipo_documento'] : $processingPayload['clientTipoDoc'],
                    'McrCustomerDocumentNumber' => $isDespatch ? $recipient['numero_documento'] : $processingPayload['clientNumDoc'],
                    'McrCustomerName' => $isDespatch ? $recipient['razon_social'] : $processingPayload['clientRznSocial'],
                    'McrTaxableAmount' => $isDespatch ? 0 : $processingPayload['mtoOperGravada'],
                    'McrTaxAmount' => $isDespatch ? 0 : $processingPayload['mtoIGV'],
                    'McrTotalAmount' => $isDespatch ? 0 : $processingPayload['mtoTotal'],
                    'McrStatus' => DocumentState::Created->value,
                    'McrIdempotencyKey' => $context->idempotencyKey,
                    'McrPayloadHash' => $payloadHash,
                    'SecStatus' => true,
                    'CreateUserId' => 0,
                    'CreateDate' => now(),
                ]);
                $this->storeLines($document, $processingPayload, $isDespatch);
                if (! $isDespatch) $this->storeReference($document, $processingPayload);
                DB::table('McrDocumentPayload')->insert([
                    'McrDocumentID' => $document->getKey(),
                    'McrPayload' => $json,
                    'McrPayloadHash' => $payloadHash,
                    'McrCreatedAt' => now(),
                ]);
                $submissionId = (int) DB::table('McrSunatSubmission')->insertGetId([
                    'McrDocumentID' => $document->getKey(),
                    'McrOperation' => 'emitir',
                    'McrTransport' => $isDespatch ? 'gre_rest' : 'soap',
                    'McrAttemptNumber' => 0,
                    'McrStatus' => DocumentState::Created->value,
                    'McrCheckpoint' => \App\Enums\ProcessingCheckpoint::Admitted->value,
                    'SecStatus' => true,
                    'CreateUserId' => 0,
                    'CreateDate' => now(),
                ], 'McrSunatSubmissionID');
                DB::table('McrIdempotency')->where('McrIdempotencyID', $identityId)->update([
                    'McrDocumentID' => $document->getKey(),
                    'McrSunatSubmissionID' => $submissionId,
                    'McrStatus' => 'completed',
                    'McrUpdatedAt' => now(),
                ]);
                $series->update(['McrNextCorrelative' => $number + 1, 'UpdateDate' => now()]);
                app(DocumentLifecycle::class)->transition($document->getKey(), $submissionId, DocumentState::Queued);
                Queue::connection('documents')->push(new ProcessElectronicDocumentJob($document->getKey(), $submissionId));

                return new AdmissionResult($document->getKey(), $submissionId, DocumentState::Queued->value);
            }, 5);
        } catch (QueryException $exception) {
            if (! $this->isAdmissionIdentityViolation($exception)) {
                throw $exception;
            }

            $company = Empresa::find($context->companyId);
            if (! $company) {
                throw $exception;
            }
            $canonicalRequest = DocumentType::tryFrom((string) ($payload['tipoDoc'] ?? ''))?->isDespatch()
                ? $this->despatchNormalizer->request($payload)
                : $this->normalizer->request($this->noteReferences->resolve($company, $payload));

            return $this->resolveConcurrentWinner($context, $canonicalRequest);
        }
    }

    private function resolveConfiguredSeries(Empresa $company, array $payload): McrSeries
    {
        $query = McrSeries::where('McrCompanyConfigID', $company->getKey())
            ->where('McrDocumentType', $payload['tipoDoc'])
            ->where('McrIsActive', true)
            ->where('SecStatus', true);
        if (($payload['serie'] ?? null) !== null) {
            $query->where('McrSeriesCode', $payload['serie']);
        } elseif ((clone $query)->count() !== 1) {
            throw new UnprocessableEntityHttpException('serie is required when the company has zero or multiple active series for this document type.');
        }
        $series = $query->first();
        if (! $series) {
            throw new UnprocessableEntityHttpException('The series does not exist, is inactive, or does not belong to this company and document type.');
        }
        $type = DocumentType::from($payload['tipoDoc']);
        if ($type->isDespatch()) {
            $prefix = $type === DocumentType::SenderDespatch ? 'T' : 'V';
            if (! preg_match('/^'.$prefix.'[A-Z0-9]{3}$/D', $series->McrSeriesCode)) {
                throw new UnprocessableEntityHttpException('The configured series has an invalid prefix for this GRE type.');
            }
        }

        return $series;
    }

    private function resolveConcurrentWinner(AdmissionContext $context, array $canonicalRequest): AdmissionResult
    {
        $identity = DB::table('McrIdempotency')
            ->where('McrApiClientID', $context->clientId)
            ->where('McrCompanyConfigID', $context->companyId)
            ->where('McrKey', $context->idempotencyKey)
            ->first();
        $document = $identity?->McrDocumentID ? McrDocument::find($identity->McrDocumentID) : null;
        $externalDocument = null;
        if ($context->externalReference !== null) {
            $externalDocument = McrDocument::where('McrApiClientID', $context->clientId)
                ->where('McrCompanyConfigID', $context->companyId)
                ->where('McrExternalReference', $context->externalReference)
                ->first();
        }
        if ($document && $context->externalReference !== null && $document->McrExternalReference !== $context->externalReference) {
            throw new ConflictHttpException('Idempotency-Key and external_reference identify incompatible operations.');
        }
        if ($document && $externalDocument && $document->getKey() !== $externalDocument->getKey()) {
            throw new ConflictHttpException('Idempotency-Key and external_reference identify different documents.');
        }
        if (! $document && $context->externalReference !== null) {
            $document = $externalDocument;
        }
        if (! $document) {
            throw new ConflictHttpException('Admission identity conflicted but the winning operation could not be reconstructed.');
        }

        $expectedHash = $document->McrRequestHash ?? $identity?->McrRequestHash;
        $canonicalRequest['serie'] = $document->McrSeriesCode;
        if (! is_string($expectedHash) || ! hash_equals($expectedHash, PayloadCodec::hash($canonicalRequest))) {
            throw new ConflictHttpException('Idempotency-Key or external_reference already belongs to a different payload.');
        }
        $submissionId = DB::table('McrSunatSubmission')->where('McrDocumentID', $document->getKey())
            ->orderBy('McrSunatSubmissionID')->value('McrSunatSubmissionID');
        if ($submissionId === null) {
            throw new ConflictHttpException('The existing operation has no reconstructable submission.');
        }
        if (! $identity) {
            DB::table('McrIdempotency')->insertOrIgnore([
                'McrApiClientID' => $context->clientId,
                'McrCompanyConfigID' => $context->companyId,
                'McrDocumentID' => $document->getKey(),
                'McrSunatSubmissionID' => $submissionId,
                'McrKey' => $context->idempotencyKey,
                'McrRequestHash' => $expectedHash,
                'McrStatus' => 'completed',
                'McrCreatedAt' => now(),
                'McrUpdatedAt' => now(),
            ]);
        }

        return new AdmissionResult($document->getKey(), (int) $submissionId, $document->McrStatus);
    }

    private function isAdmissionIdentityViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if (! in_array($sqlState, ['23000', '23505'], true)) {
            return false;
        }
        $message = $exception->getMessage();

        return str_contains($message, 'UX_McrIdempotency_ScopeKey')
            || str_contains($message, 'UX_McrDocument_ClientCompanyExternal')
            || str_contains($message, 'McrIdempotency.McrApiClientID, McrIdempotency.McrCompanyConfigID, McrIdempotency.McrKey')
            || str_contains($message, 'McrDocument.McrApiClientID, McrDocument.McrCompanyConfigID, McrDocument.McrExternalReference');
    }

    private function storeLines(McrDocument $document, array $payload, bool $isDespatch = false): void
    {
        foreach (($isDespatch ? $payload['bienes'] : $payload['items']) as $index => $line) {
            DB::table('McrDocumentLine')->insert([
                'McrDocumentID' => $document->getKey(),
                'McrLineNumber' => $index + 1,
                'McrProductCode' => $line['codigo'] ?? null,
                'McrDescription' => $line['descripcion'],
                'McrUnitCode' => $line['unidad'] ?? 'NIU',
                'McrQuantity' => $line['cantidad'],
                'McrUnitValue' => $isDespatch ? 0 : $line['mtoValorUnitario'],
                'McrUnitPrice' => $isDespatch ? 0 : $line['mtoPrecioUnitario'],
                'McrTaxBase' => $isDespatch ? 0 : $line['mtoBaseIgv'],
                'McrTaxRate' => $isDespatch ? 0 : $payload['igvRate'],
                'McrTaxAmount' => $isDespatch ? 0 : $line['igv'],
                'McrLineTotal' => $isDespatch ? 0 : (DocumentType::tryFrom($payload['tipoDoc'])?->isNote()
                    ? DecimalAmount::add($line['mtoValorVenta'], $line['igv'])
                    : round($line['mtoPrecioUnitario'] * $line['cantidad'], 6)),
                'SecStatus' => true,
                'CreateUserId' => 0,
                'CreateDate' => now(),
            ]);
        }
    }

    private function storeReference(McrDocument $document, array $payload): void
    {
        $reference = $payload['reference'] ?? null;
        if (! is_array($reference)) {
            return;
        }
        DB::table('McrDocumentReference')->insert([
            'McrDocumentID' => $document->getKey(),
            'ReferencedMcrDocumentID' => $reference['document_id'] ?? null,
            'McrReferenceType' => $reference['kind'] === 'internal' ? 'internal_document' : 'external_document',
            'McrIsExternal' => $reference['kind'] === 'external',
            'McrReferencedDocumentType' => $reference['document_type'],
            'McrReferencedNumber' => $reference['series'].'-'.$reference['correlative'],
            'McrReferencedSeriesCode' => $reference['series'],
            'McrReferencedCorrelative' => $reference['correlative'],
            'McrReferencedIssueDate' => $reference['issue_date'],
            'McrReferencedCurrencyCode' => $reference['currency'],
            'McrReferencedCustomerDocumentType' => $reference['customer_document_type'],
            'McrReferencedCustomerDocumentNumber' => $reference['customer_document_number'],
            'McrReasonCode' => $reference['reason_code'],
            'McrReason' => $reference['reason'],
            'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
        ]);
    }
}
