<?php

namespace App\Services\Webhooks;

use App\Enums\DocumentIntegrationEvent;
use App\Enums\DocumentState;
use App\Models\McrDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DocumentIntegrationEventPublisher
{
    public function publish(McrDocument $document, int $submissionId, DocumentState $state, int $stateVersion): ?string
    {
        $type = DocumentIntegrationEvent::fromState($state);
        if ($type === null || $document->McrApiClientID === null) {
            return null;
        }
        $occurredAt = now();
        $eventId = (string) Str::uuid();
        $payload = [
            'event_id' => $eventId, 'event_type' => $type->value, 'event_version' => 1,
            'occurred_at' => $occurredAt->toIso8601String(), 'client_id' => (int) $document->McrApiClientID,
            'company_id' => (int) $document->McrCompanyConfigID, 'state_version' => $stateVersion,
            'data' => [
                'document_id' => $document->getKey(), 'submission_id' => $submissionId,
                'external_reference' => $document->McrExternalReference, 'document_type' => $document->McrDocumentType,
                'series' => $document->McrSeriesCode, 'correlative' => (int) $document->McrCorrelative,
                'number' => $document->McrSeriesCode.'-'.$document->McrCorrelative, 'status' => $state->value,
                'establishment' => [
                    'external_code' => $document->McrEstablishmentSnapshot['external_code'] ?? null,
                    'sunat_code' => $document->McrEstablishmentSnapshot['sunat_code'] ?? null,
                    'name' => $document->McrEstablishmentSnapshot['name'] ?? null,
                ],
                'sunat_code' => $document->McrSunatCode, 'message' => $this->sanitize($document->McrSunatDescription),
                'artifacts' => ['pdf' => (bool) $document->McrPdfPath, 'xml' => (bool) $document->McrXmlPath,
                    'cdr' => (bool) $document->McrCdrPath, 'ticket' => (bool) $document->McrSunatTicket],
            ],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $inserted = DB::table('McrOutboxEvent')->insertOrIgnore([
            'McrOutboxEventID' => $eventId, 'McrDocumentID' => $document->getKey(), 'McrSunatSubmissionID' => $submissionId,
            'McrApiClientID' => $document->McrApiClientID, 'McrCompanyConfigID' => $document->McrCompanyConfigID,
            'McrEventType' => $type->value, 'McrEventVersion' => 1, 'McrStateVersion' => $stateVersion,
            'McrDeduplicationKey' => $document->getKey().':'.$submissionId.':'.$type->value.':'.$stateVersion,
            'McrPayload' => $body, 'McrPayloadBody' => $body, 'McrOccurredAt' => $occurredAt,
        ]);

        return $inserted === 1 ? $eventId : null;
    }

    private function sanitize(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return mb_substr(strip_tags($message), 0, 500);
    }
}
