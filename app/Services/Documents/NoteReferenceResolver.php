<?php

namespace App\Services\Documents;

use App\Enums\CreditNoteReason;
use App\Enums\DebitNoteReason;
use App\Enums\DocumentType;
use App\Models\Empresa;
use App\Models\McrDocument;
use App\Support\DecimalAmount;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class NoteReferenceResolver
{
    public function resolve(Empresa $company, array $payload): array
    {
        $type = DocumentType::tryFrom((string) ($payload['tipoDoc'] ?? ''));
        if (! $type?->isNote()) {
            return $payload;
        }
        $reference = $payload['reference'] ?? null;
        if (! is_array($reference)) {
            throw new UnprocessableEntityHttpException('reference is required for credit and debit notes.');
        }
        $reasonCode = (string) ($reference['reason_code'] ?? '');
        $reason = $type === DocumentType::CreditNote
            ? CreditNoteReason::tryFrom($reasonCode)
            : DebitNoteReason::tryFrom($reasonCode);
        if (! $reason) {
            throw new UnprocessableEntityHttpException('The SUNAT note reason code is invalid for this document type.');
        }
        if ($reason instanceof CreditNoteReason && ! $reason->supportedByCurrentTaxContract()) {
            throw new UnprocessableEntityHttpException('The SUNAT reason is catalogued but requires a tax/payment contract not supported by this phase.');
        }
        $kind = (string) ($reference['kind'] ?? '');
        if ($kind === 'internal') {
            $reference = $this->internal($company, $payload, $reference, $reason);
            $original = McrDocument::findOrFail($reference['document_id']);
            $externalCode = $original->McrEstablishmentSnapshot['external_code']
                ?? DB::table('McrEstablishment')->where('McrEstablishmentID', $original->McrEstablishmentID)->value('McrExternalCode');
            if (! $externalCode) {
                throw new UnprocessableEntityHttpException('The referenced document has no resolvable establishment.');
            }
            if (isset($payload['establishment']) && $payload['establishment'] !== $externalCode) {
                throw new UnprocessableEntityHttpException('A note must use the establishment of the referenced document.');
            }
            $payload['establishment'] = $externalCode;
        } elseif ($kind === 'external') {
            if (! isset($payload['establishment']) || trim((string) $payload['establishment']) === '') {
                throw new UnprocessableEntityHttpException('establishment is required for an external note reference.');
            }
            $reference = $this->external($payload, $reference);
        } else {
            throw new UnprocessableEntityHttpException('reference.kind must be internal or external.');
        }
        $reference['reason_code'] = $reasonCode;
        $reference['reason'] = trim((string) ($reference['reason'] ?? '')) ?: $reason->description();
        $payload['reference'] = $reference;

        return $payload;
    }

    public function validateAccumulatedCredit(array $payload): void
    {
        if (($payload['tipoDoc'] ?? null) !== DocumentType::CreditNote->value
            || ($payload['reference']['kind'] ?? null) !== 'internal') {
            return;
        }
        $reason = CreditNoteReason::from($payload['reference']['reason_code']);
        if (! $reason->consumesOriginalBalance()) {
            return;
        }
        $original = McrDocument::whereKey($payload['reference']['document_id'])->lockForUpdate()->firstOrFail();
        $consumingCodes = array_map(
            fn (CreditNoteReason $item): string => $item->value,
            array_filter(CreditNoteReason::cases(), fn (CreditNoteReason $item): bool => $item->supportedByCurrentTaxContract() && $item->consumesOriginalBalance()),
        );
        $reserved = DB::table('McrDocumentReference as reference')
            ->join('McrDocument as note', 'note.McrDocumentID', '=', 'reference.McrDocumentID')
            ->where('reference.ReferencedMcrDocumentID', $original->getKey())
            ->where('note.McrDocumentType', DocumentType::CreditNote->value)
            ->whereIn('reference.McrReasonCode', $consumingCodes)
            ->whereIn('note.McrStatus', ['created', 'queued', 'processing', 'retry_pending', 'accepted', 'accepted_with_observations'])
            ->selectRaw('CAST(note."McrTotalAmount" AS TEXT) AS exact_total')
            ->pluck('exact_total')
            ->reduce(fn (int $sum, mixed $amount): int => $sum + $this->storedCents($amount), 0);
        $requested = DecimalAmount::minorUnits($payload['mtoTotal']);
        if ($reserved + $requested > $this->storedCents($this->storedTotal($original->getKey()))) {
            throw new UnprocessableEntityHttpException('Accumulated credit notes would exceed the referenced document total.');
        }
    }

    private function internal(Empresa $company, array $payload, array $reference, CreditNoteReason|DebitNoteReason $reason): array
    {
        $id = filter_var($reference['document_id'] ?? null, FILTER_VALIDATE_INT);
        $original = $id ? McrDocument::find($id) : null;
        if (! $original) {
            throw new UnprocessableEntityHttpException('The referenced internal document does not exist.');
        }
        if ((int) $original->McrCompanyConfigID !== (int) $company->getKey()) {
            throw new UnprocessableEntityHttpException('The referenced document belongs to another company.');
        }
        if (! in_array($original->McrDocumentType, [DocumentType::Invoice->value, DocumentType::Receipt->value], true)) {
            throw new UnprocessableEntityHttpException('Only invoices and receipts can be referenced by this note pipeline.');
        }
        if (! in_array($original->McrStatus, ['accepted', 'accepted_with_observations'], true)) {
            throw new UnprocessableEntityHttpException('The referenced document has no compatible fiscal state.');
        }
        $facts = [
            'document_type' => $original->McrDocumentType,
            'series' => $original->McrSeriesCode,
            'correlative' => (int) $original->McrCorrelative,
            'issue_date' => (string) $original->McrIssueDate,
            'currency' => $original->McrCurrencyCode,
            'customer_document_type' => $original->McrCustomerDocumentType,
            'customer_document_number' => $original->McrCustomerDocumentNumber,
        ];
        foreach ($facts as $field => $value) {
            if (array_key_exists($field, $reference) && (string) $reference[$field] !== (string) $value) {
                throw new UnprocessableEntityHttpException("reference.$field does not match the persisted document.");
            }
        }
        if ((string) ($payload['tipoMoneda'] ?? '') !== (string) $original->McrCurrencyCode
            || (string) ($payload['clientTipoDoc'] ?? '') !== (string) $original->McrCustomerDocumentType
            || (string) ($payload['clientNumDoc'] ?? '') !== (string) $original->McrCustomerDocumentNumber) {
            throw new UnprocessableEntityHttpException('Currency and customer must match the referenced internal document.');
        }
        $this->validateSeriesFamily((string) ($payload['serie'] ?? ''), $original->McrSeriesCode);
        $noteCents = $this->cents($payload['mtoTotal'] ?? null);
        $originalCents = $this->storedCents($this->storedTotal($original->getKey()));
        if ($reason instanceof CreditNoteReason && $noteCents > $originalCents) {
            throw new UnprocessableEntityHttpException('A credit note cannot exceed the referenced document total.');
        }
        if ($reason instanceof CreditNoteReason && $reason->requiresOriginalTotal() && $noteCents !== $originalCents) {
            throw new UnprocessableEntityHttpException('This credit-note reason requires the original document total.');
        }

        return ['kind' => 'internal', 'document_id' => (int) $original->getKey(), ...$facts, ...array_intersect_key($reference, ['reason_code' => true, 'reason' => true])];
    }

    private function external(array $payload, array $reference): array
    {
        foreach (['document_type', 'series', 'correlative', 'issue_date', 'currency', 'customer_document_type', 'customer_document_number'] as $field) {
            if (! isset($reference[$field]) || $reference[$field] === '') {
                throw new UnprocessableEntityHttpException("reference.$field is required for an external document.");
            }
        }
        if (! in_array($reference['document_type'], [DocumentType::Invoice->value, DocumentType::Receipt->value], true)) {
            throw new UnprocessableEntityHttpException('An external note reference must identify an invoice or receipt.');
        }
        if ((string) $reference['currency'] !== (string) ($payload['tipoMoneda'] ?? '')
            || (string) $reference['customer_document_type'] !== (string) ($payload['clientTipoDoc'] ?? '')
            || (string) $reference['customer_document_number'] !== (string) ($payload['clientNumDoc'] ?? '')) {
            throw new UnprocessableEntityHttpException('External reference currency and customer must match the note.');
        }
        $this->validateSeriesFamily((string) ($payload['serie'] ?? ''), (string) $reference['series']);

        return [
            'kind' => 'external', 'document_id' => null,
            'document_type' => (string) $reference['document_type'],
            'series' => strtoupper((string) $reference['series']),
            'correlative' => (int) $reference['correlative'],
            'issue_date' => substr((string) $reference['issue_date'], 0, 10),
            'currency' => (string) $reference['currency'],
            'customer_document_type' => (string) $reference['customer_document_type'],
            'customer_document_number' => (string) $reference['customer_document_number'],
            ...array_intersect_key($reference, ['reason_code' => true, 'reason' => true]),
        ];
    }

    private function validateSeriesFamily(string $noteSeries, string $originalSeries): void
    {
        if ($noteSeries === '' || ! in_array($noteSeries[0], ['F', 'B'], true) || $noteSeries[0] !== $originalSeries[0]) {
            throw new UnprocessableEntityHttpException('The note series must use the same F/B family as the affected document.');
        }
    }

    private function cents(mixed $value): int
    {
        try {
            return DecimalAmount::minorUnits($value);
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException('Monetary totals require exact non-negative decimals with at most two places.');
        }
    }

    private function storedTotal(int $documentId): string
    {
        return (string) DB::table('McrDocument')
            ->where('McrDocumentID', $documentId)
            ->value(DB::raw('CAST("McrTotalAmount" AS TEXT)'));
    }

    private function storedCents(mixed $value): int
    {
        $exact = (string) $value;
        if (str_contains($exact, '.')) {
            $exact = rtrim(rtrim($exact, '0'), '.');
        }

        return DecimalAmount::minorUnits($exact);
    }
}
