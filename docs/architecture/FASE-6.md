# Fase 6 — Transactional Outbox y Webhooks

## Alcance y auditoría previa

La auditoría encontró un único punto de convergencia para 01/03/07/08/09/31: `DocumentLifecycle`. La admisión lo usa para `queued`; el processor para `processing` y resultado; el poller GRE y el reconciliador SOAP terminan mediante `finish`; los límites técnicos llegan a `manual_review`. Artifact recovery no cambia el hecho fiscal.

No existían callbacks HTTP, observers, listeners, notifications ni integración saliente con Nova. Los únicos delayed jobs eran procesamiento, polling, reconciliación y artefactos. Por ello la publicación se centraliza en `DocumentIntegrationEventPublisher`, invocado dentro de la misma transacción de lifecycle.

La base compartida se auditó en una transacción `READ ONLY`: 19 documentos (2 tipo 01 y 17 tipo 03, todos accepted), 25 submissions, 2 API clients y 1 empresa. No contenía tablas de Fase 6. La conexión terminó sin escrituras; no se aplicaron migraciones.

## Atomicidad, versión y deduplicación

Cada transición incrementa `McrDocument.McrStateVersion` bajo row lock. Para estados publicados, la misma transacción actualiza documento/submission, persiste respuesta y crea `McrOutboxEvent`. Un rollback revierte ambos.

El evento usa UUID estable, `event_version=1`, `state_version` monotónico, `occurred_at`, scope y snapshot. Se conserva JSONB para consulta y `McrPayloadBody` para enviar exactamente los bytes creados al publicar. No se reconstruye desde el estado futuro.

`UX_McrOutboxEvent_Dedup` y `UX_McrOutboxEvent_DocumentVersion` impiden duplicados bajo concurrencia. Retries crean intentos de delivery, nunca otro evento.

## Catálogo

Se publican:

- `document.awaiting_sunat`: el consumidor conoce que existe ticket/proceso remoto.
- `document.accepted` y `document.accepted_with_observations`.
- `document.rejected`.
- `document.reconciliation_pending` y `document.manual_review`.
- `document.failed`.

No se publican `created`, `queued`, `processing`, `retry_pending`, checkpoints ni eventos de artefactos. Son actividad interna frecuente y producirían ruido. Todas las transiciones incrementan `state_version`, por lo que los saltos de versión son válidos. No se promete orden global; un consumidor ignora cualquier evento con versión menor o igual a la última aplicada para el documento.

## Envelope versión 1

```json
{
  "event_id": "2f0c73f0-…",
  "event_type": "document.accepted",
  "event_version": 1,
  "occurred_at": "2026-09-13T20:00:00-05:00",
  "client_id": 5,
  "company_id": 1,
  "state_version": 3,
  "data": {
    "document_id": 19,
    "submission_id": 25,
    "external_reference": "RST-SALE-37",
    "document_type": "01",
    "series": "F001",
    "correlative": 2,
    "number": "F001-2",
    "status": "accepted",
    "sunat_code": "0",
    "message": "Aceptado",
    "artifacts": {"pdf": true, "xml": true, "cdr": true, "ticket": false}
  }
}
```

No contiene secretos, firmas, tokens, credenciales, Base64 ni rutas internas.

## Entidades

`McrOutboxEvent` representa el hecho inmutable. `McrWebhookSubscription` representa un endpoint habilitado, su cliente y empresa obligatorios, filtro de eventos y secret cifrado. `McrWebhookDelivery` representa el estado durable por evento/suscripción. `McrWebhookDeliveryAttempt` conserva cada intento técnico sin sobrescribir los anteriores.

Una suscripción sólo recibe eventos con el mismo `client_id` y `company_id` y cuyo tipo esté incluido. La restricción única evento/suscripción hace idempotente el fan-out. Un evento puede producir cero, una o muchas deliveries independientes.

## Administración y secrets

Endpoints autenticados:

- `POST/GET /api/webhooks/subscriptions`
- `GET/PATCH/DELETE /api/webhooks/subscriptions/{id}`
- `POST /api/webhooks/subscriptions/{id}/rotate-secret`

Exigen `X-Api-Client-Id` y `X-Company-Id` explícitos y activos. El secret tiene 256 bits aleatorios, se cifra con Laravel Crypt y sólo se devuelve al crear o rotar. GET nunca devuelve plaintext ni ciphertext. La rotación es inmediata.

La autenticación administrativa actual usa el token compartido del microservicio; asociar tokens distintos y scopes propios a cada `McrApiClient` queda como deuda de autorización futura.

## Firma y replay

Cada intento genera un timestamp Unix nuevo y firma exactamente:

```text
HMAC-SHA256(secret, timestamp + "." + raw_body)
```

Headers:

```text
X-SunFacturation-Event-Id: <event_id>
X-SunFacturation-Timestamp: <unix-seconds>
X-SunFacturation-Signature: v1=<hex>
User-Agent: SunFacturation-Webhooks/1.0
```

Verificación conceptual:

```php
$expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
$valid = abs(time() - (int) $timestamp) <= 300
    && hash_equals($expected, $receivedSignature);
```

La ventana recomendada es cinco minutos. Un retry legítimo usa timestamp/firma nuevos, con el mismo `event_id` y payload. La entrega es at-least-once; el consumidor debe imponer `event_id UNIQUE`.

## Transporte, SSRF y respuestas

`HttpWebhookTransport` centraliza destino, bytes, firma, headers y timeout configurable (8 segundos por defecto). Sólo HTTP 2xx, incluido 204, es éxito. Redirects están deshabilitados.

Clasificación:

| Respuesta | Acción |
|---|---|
| 2xx | delivered |
| 408/425/5xx | retry |
| 429 | retry; respeta `Retry-After` válido hasta 6 horas |
| 400/422 | dead letter, payload rechazado |
| 401/403 | dead letter, configuración/autenticación |
| 404/410 | dead letter, endpoint ausente |
| otros 4xx/redirect | dead letter |
| timeout, DNS o conexión | retry; resultado puede ser at-least-once |

El body de respuesta se limpia y limita a 4096 caracteres. No se almacenan headers de respuesta.

Producción exige HTTPS. Se rechazan loopback, RFC1918, link-local, metadata cloud, IPs privadas/reservadas, userinfo y otros esquemas. Se resuelven A/AAAA, se validan todas las IP y se fija la resolución mediante `CURLOPT_RESOLVE` para reducir DNS rebinding entre validación y conexión. HTTP/red privada sólo se permite con `WEBHOOK_ALLOW_UNSAFE_LOCAL=true`, pensado exclusivamente para desarrollo/test; nunca se activa automáticamente.

## Retry, claims y DLQ

Backoff: 10 s, 30 s, 2 min, 10 min, 30 min, 2 h y 6 h. Máximo siete intentos por ciclo. No hay `sleep()`. `McrNextAttemptAt` permite reconstruir deliveries si se pierde la cola.

Antes de HTTP, `DeliverWebhookJob` bloquea la fila, valida estado/plazo, incrementa contadores y persiste `McrClaimToken/McrClaimedAt`. Un segundo worker no sale a red. El lease expira en dos minutos. Al agotar intentos se conserva evento, delivery, intentos y último error como `dead_letter`; el documento fiscal nunca cambia.

`webhooks:redeliver {deliveryId}` rearma una delivery muerta, conserva el mismo evento/payload y reinicia sólo el contador del ciclo. `webhooks:replay {eventId} {subscriptionId}` reusa o crea la delivery dentro del mismo scope. El historial y el contador total permanecen.

## Dispatcher y operación

```bash
php artisan webhooks:dispatch --limit=100
php artisan webhooks:status
php artisan webhooks:redeliver 123
php artisan webhooks:replay <event-id> 45
```

El dispatcher hace fan-out de eventos no procesados y encola deliveries vencidas o con claim stale. PostgreSQL usa `FOR UPDATE SKIP LOCKED`; índices:

- `IX_McrOutboxEvent_Pending (McrFannedOutAt, McrOccurredAt)`;
- `IX_McrWebhookSubscription_Scope`;
- `IX_McrWebhookDelivery_Due (McrStatus, McrNextAttemptAt)`;
- uniques de deduplicación y evento/suscripción.

Laravel Scheduler ejecuta `webhooks:dispatch --limit=100` cada minuto con `withoutOverlapping`; la seguridad real reside en locks, claims y constraints PostgreSQL.

## Migración, bootstrap y retención

`2026_09_13_000500_add_transactional_outbox_webhooks.php` es reversible. Agregar `McrStateVersion=0` no genera eventos para documentos históricos. Outbox comienza con transiciones posteriores a la activación.

Esta fase no elimina datos. Recomendación futura: eventos y deliveries al menos 12 meses; intentos/excerpts 90–180 días, según auditoría y volumen. Cualquier cleanup requerirá comando y política explícitos.

## Observabilidad y límites

Los logs de delivery incluyen IDs de evento/delivery/subscription, scope, documento, tipo, intento, HTTP y outcome. Nunca incluyen secret ni firma.

Limitaciones: autorización administrativa todavía usa token compartido; no existe dashboard; no se promete orden entre documentos; el pin DNS depende del handler cURL; consumidores deben validar timestamp, HMAC, `event_id` y `state_version`. Nova, SignalR y Fase 6.5 no fueron modificados ni implementados.

