<?php

namespace App\Enums;

enum RecoveryAction: string
{
    case DoNothing = 'do_nothing';
    case Process = 'process';
    case RetrySubmission = 'retry_submission';
    case PollTicket = 'poll_ticket';
    case Reconcile = 'reconcile';
    case RegenerateArtifacts = 'regenerate_artifacts';
    case MarkTerminal = 'mark_terminal';
    case ManualReview = 'manual_review';
}
