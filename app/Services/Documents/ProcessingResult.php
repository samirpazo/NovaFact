<?php

namespace App\Services\Documents;

use App\Enums\DocumentState;
use App\Enums\FailureCategory;
use Greenter\Model\Response\BillResult;

final readonly class ProcessingResult
{
    public function __construct(
        public DocumentState $state,
        public ?string $code = null,
        public ?string $description = null,
        public array $notes = [],
        public ?string $error = null,
        public ?string $ticket = null,
        public array $metadata = [],
        public ?FailureCategory $failureCategory = null,
        public bool $retryable = false,
        public bool $ambiguous = false,
    ) {}

    public static function fromBillResult(BillResult $result): self
    {
        $cdr = $result->getCdrResponse();
        if (! $result->isSuccess() || $cdr === null || $cdr->getCode() === null || ! ctype_digit($cdr->getCode())) {
            // Without a parsed CDR, transport/configuration failure is not a fiscal rejection.
            return new self(DocumentState::ReconciliationPending, $result->getError()?->getCode(), error: 'SUNAT outcome could not be verified.', failureCategory: FailureCategory::AmbiguousSubmission, ambiguous: true);
        }
        $notes = $cdr->getNotes() ?? [];
        $state = ! $cdr->isAccepted() ? DocumentState::Rejected
            : (($notes !== [] || (int) $cdr->getCode() >= 4000) ? DocumentState::AcceptedWithObservations : DocumentState::Accepted);

        $category = match ($state) {
            DocumentState::Accepted => FailureCategory::RemoteAccepted,
            DocumentState::AcceptedWithObservations => FailureCategory::RemoteAcceptedWithObservations,
            default => FailureCategory::RemoteRejected,
        };
        return new self($state, $cdr->getCode(), $cdr->getDescription(), $notes, failureCategory: $category);
    }

    public static function fromArray(array $result): self
    {
        return new self(DocumentState::from($result['status']), $result['code'] ?? null,
            $result['description'] ?? null, $result['notes'] ?? [], $result['error'] ?? null,
            $result['ticket'] ?? null, $result['metadata'] ?? [], isset($result['failure_category']) ? FailureCategory::tryFrom($result['failure_category']) : null,
            (bool) ($result['retryable'] ?? false), (bool) ($result['ambiguous'] ?? false));
    }

    public function toArray(): array
    {
        return ['status' => $this->state->value, 'code' => $this->code, 'description' => $this->description, 'notes' => $this->notes, 'error' => $this->error, 'ticket' => $this->ticket, 'metadata' => $this->metadata,
            'failure_category' => $this->failureCategory?->value, 'retryable' => $this->retryable, 'ambiguous' => $this->ambiguous];
    }
}
