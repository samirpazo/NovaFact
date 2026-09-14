<?php

namespace App\Enums;

enum FailureCategory: string
{
    case Validation = 'validation_failure';
    case LocalRetryable = 'local_technical_retryable';
    case LocalNonRetryable = 'local_technical_non_retryable';
    case RemoteRejected = 'remote_rejected';
    case RemoteAccepted = 'remote_accepted';
    case RemoteAcceptedWithObservations = 'remote_accepted_with_observations';
    case RemotePending = 'remote_pending';
    case RemoteTransientSafe = 'remote_transient_known_safe';
    case AmbiguousSubmission = 'ambiguous_submission';
    case ArtifactFailure = 'artifact_failure';
    case WorkerInterrupted = 'worker_interrupted';
    case Unknown = 'unknown';
}
