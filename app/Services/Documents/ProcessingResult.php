<?php

namespace App\Services\Documents;

use App\Enums\DocumentState;
use Greenter\Model\Response\BillResult;

final readonly class ProcessingResult
{
    public function __construct(
        public DocumentState $state,
        public ?string $code = null,
        public ?string $description = null,
        public array $notes = [],
        public ?string $error = null,
    ) {}

    public static function fromBillResult(BillResult $result): self
    {
        $cdr = $result->getCdrResponse();
        if (! $result->isSuccess() || $cdr === null || $cdr->getCode() === null || ! ctype_digit($cdr->getCode())) {
            // Without a parsed CDR, transport/configuration failure is not a fiscal rejection.
            return new self(DocumentState::Failed, $result->getError()?->getCode(), error: 'SUNAT transport did not provide a verifiable CDR. Reconciliation required before resending.');
        }
        $notes = $cdr->getNotes() ?? [];
        $state = ! $cdr->isAccepted() ? DocumentState::Rejected
            : (($notes !== [] || (int) $cdr->getCode() >= 4000) ? DocumentState::AcceptedWithObservations : DocumentState::Accepted);

        return new self($state, $cdr->getCode(), $cdr->getDescription(), $notes);
    }

    public static function fromArray(array $result): self
    {
        return new self(DocumentState::from($result['status']), $result['code'] ?? null,
            $result['description'] ?? null, $result['notes'] ?? [], $result['error'] ?? null);
    }

    public function toArray(): array
    {
        return ['status' => $this->state->value, 'code' => $this->code, 'description' => $this->description, 'notes' => $this->notes, 'error' => $this->error];
    }
}
