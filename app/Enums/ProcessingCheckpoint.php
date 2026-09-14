<?php

namespace App\Enums;

enum ProcessingCheckpoint: string
{
    case Admitted = 'admitted';
    case PayloadLoaded = 'payload_loaded';
    case XmlGenerated = 'xml_generated';
    case XmlSigned = 'xml_signed';
    case SubmissionStarted = 'submission_started';
    case RemoteResponseReceived = 'remote_response_received';
    case RemoteResultPersisted = 'remote_result_persisted';
    case ArtifactsGenerated = 'artifacts_generated';
    case Completed = 'completed';

    public function contactedRemote(): bool
    {
        return array_search($this, self::cases(), true) >= array_search(self::SubmissionStarted, self::cases(), true);
    }
}
