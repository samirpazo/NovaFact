<?php

namespace App\Enums;

enum DocumentState: string
{
    case Created = 'created';
    case Queued = 'queued';
    case Processing = 'processing';
    case Sent = 'sent';
    case PendingSunat = 'pending_sunat';
    case Accepted = 'accepted';
    case AcceptedWithObservations = 'accepted_with_observations';
    case Rejected = 'rejected';
    case RetryPending = 'retry_pending';
    case Failed = 'failed';
    case VoidPending = 'void_pending';
    case VoidAccepted = 'void_accepted';
    case VoidRejected = 'void_rejected';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Created => [self::Queued],
            self::Queued, self::RetryPending => [self::Processing, self::Failed],
            self::Processing => [self::Sent, self::PendingSunat, self::Accepted, self::AcceptedWithObservations, self::Rejected, self::RetryPending, self::Failed],
            self::Sent, self::PendingSunat => [self::PendingSunat, self::Accepted, self::AcceptedWithObservations, self::Rejected, self::RetryPending, self::Failed],
            self::Accepted, self::AcceptedWithObservations, self::VoidRejected => [self::VoidPending],
            self::VoidPending => [self::VoidAccepted, self::VoidRejected],
            self::Rejected, self::Failed, self::VoidAccepted => [],
        }, true);
    }
}
