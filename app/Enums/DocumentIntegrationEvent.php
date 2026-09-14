<?php

namespace App\Enums;

enum DocumentIntegrationEvent: string
{
    case AwaitingSunat = 'document.awaiting_sunat';
    case Accepted = 'document.accepted';
    case AcceptedWithObservations = 'document.accepted_with_observations';
    case Rejected = 'document.rejected';
    case ReconciliationPending = 'document.reconciliation_pending';
    case ManualReview = 'document.manual_review';
    case Failed = 'document.failed';

    public static function fromState(DocumentState $state): ?self
    {
        return self::tryFrom('document.'.$state->value);
    }
}
