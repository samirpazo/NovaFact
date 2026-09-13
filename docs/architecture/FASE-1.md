# Fase 1 — pipeline de ventas existente

## Alcance implementado

Factura 01 y boleta 03 entran por el endpoint legacy existente y recorren admisión → documento/líneas/payload/submission → job de base de datos → resolver → processor → mapper Greenter existente → XML persistido/envío → resultado fiscal/submission/intento/respuesta. No se presentan como implementadas las fases posteriores ni la cobertura tributaria general de boletas mediante resúmenes.

La auditoría y el plan se entregaron antes de los cambios de aplicación en `AUDITORIA-2026-09-13.md`.

## Archivos

Nuevos:

- `app/Enums/DocumentState.php`: estados y transiciones permitidas.
- `app/Services/Documents/ElectronicDocumentProcessor.php`, `DocumentProcessorResolver.php`, `ProcessingResult.php`: contrato, resolución y clasificación del CDR.
- `app/Services/Documents/Processors/InvoiceProcessor.php`, `ReceiptProcessor.php`: conexión con el servicio de ventas existente.
- `app/Services/Documents/AdmitElectronicDocument.php`: transacción de admisión, numeración y cola.
- `app/Services/Documents/DocumentLifecycle.php`: bloqueo, claim único, resultado e historial de intento/respuesta.
- `app/Services/Documents/PayloadCodec.php`: snapshot y hash canónico compatible con jsonb.
- `app/Jobs/ProcessElectronicDocumentJob.php`: transporta sólo documentId/submissionId, no el payload completo.
- `database/migrations/2026_09_13_000000_add_document_pipeline.php`.
- `tests/Feature/DocumentPipelineTest.php`, `tests/Unit/DocumentProcessingResultTest.php`, `tests/Support/PipelineDatabase.php`.

Modificados:

- `app/Http/Controllers/Api/FacturacionController.php`: emisión mediante admisión, ticket imprimible también para accepted_with_observations.
- `app/Services/Facturacion/FacturaService.php`: nueva entrada para documento reservado, empresa del documento, checkpoint SUNAT y generación de artefactos sin volver a numerar.
- `app/Services/Facturacion/InvoicePdfService.php`: nombre de archivo interno opcional y comprobación de escritura.
- `app/Services/Facturacion/BoletaSummaryService.php`: incluye accepted_with_observations en la selección existente; no cambia todavía su ciclo síncrono.
- `app/Services/Sunat/GreenterService.php`: empresa explícita opcional y envío de los bytes firmados previamente mediante See::sendXml.
- `config/queue.php`: conexión documents de base de datos sobre la misma conexión de la transacción de admisión.

## Migración

Añade McrDocumentPayload (JSON y hash), McrSunatAttempt (historial individual, transporte, resultado y duración) y McrDocument.McrProcessingResult/McrArtifactError. No altera documentos históricos ni elimina tablas/índices existentes. Up/down/up probado en SQLite aislado y PostgreSQL 18 temporal.

**No aplicada en la base de datos compartida suministrada.** La auditoría remota sólo ejecutó SELECT dentro de READ ONLY. Las migraciones se ejecutaron únicamente en esquemas de pruebas locales. No se enviaron comprobantes a SUNAT ni callbacks reales.

## Decisiones

1. Conservar URL, validación y respuesta HTTP 202 legacy (`status: pending`, `submission_id`). El estado persistido inicial es `queued`. La API v1 queda pendiente.
2. Encolar usando `Queue::connection('documents')->push` dentro de la transacción, en la misma DB. Se evita tanto ejecución sync accidental como la ventana commit → dispatch sin cola durable. La tabla jobs existente es reutilizada.
3. Número reservado una sola vez; el worker carga el snapshot y fija serie/correlativo desde el documento. La política concurrente de creación/configuración de series todavía se aborda en fase 2.
4. Claim transaccional bloquea documento y submission. Una reentrega de un documento processing o terminal no invoca el processor otra vez.
5. No confundir isSuccess del transporte con aceptación fiscal. Clasificar CDR mediante isAccepted de Greenter, código y notes; falta de CDR verificable se registra failed, nunca accepted.
6. Guardar checkpoint del resultado SUNAT antes de PDF/registro local. Un timeout posterior puede finalizar con el resultado conocido. Un error local queda en McrArtifactError; no dispara otra emisión.
7. `EmitFacturaJob`, `EmitFacturaAction` y el método síncrono `FacturaService::emitir` fueron eliminados después de rastrear sus referencias. Factura y boleta sólo pueden llegar al transporte mediante `AdmitElectronicDocument` y `ProcessElectronicDocumentJob`.
8. Los artefactos nuevos incluyen ID del documento en el nombre y las descargas legacy siguen funcionando. El firmado se hace una vez; sendXml utiliza los mismos bytes guardados.
9. Seguridad de fallo: no persistir mensajes arbitrarios de excepciones de transporte; registrar clase de excepción e IDs. Descripción/código CDR sí se conservan.
10. Cambio de seguridad explícito frente al retry antiguo: el job nuevo tiene un intento automático. Mientras no exista clasificación y reconciliación de fase 5, un resultado técnico incierto queda failed con el número original y exige revisión; no se crea automáticamente un segundo comprobante.

## Pruebas y resultados

- Antes de fase 1: `php artisan test`: **5 passed, 1 deprecated, 11 assertions**.
- Después de fase 1: `php artisan test`: **31 passed, 1 deprecated, 120 assertions**.
- `PIPELINE_TEST_POSTGRES=1 php artisan test`: **31 passed, 1 deprecated, 120 assertions**. Los 17 casos de pipeline ejecutan las migraciones reales en un esquema nuevo por caso sobre PostgreSQL 18 local; la suite restante conserva su configuración normal.
- Se verifican factura y boleta, respuesta 202 con cola realmente persistida aun bajo queue.default=sync, rollback si falla enqueue, integridad del payload jsonb, accepted/observations/rejected, excepción técnica, no reenvío terminal, claim repetido, pertenencia submission-documento, timeout, checkpoint, mapper/resolver/processor conectados, bytes XML guardados=enviados, XML/CDR/PDF registrados, descarga legacy, job serializado ejecutado por el handler real y rollback de migración.
- SUNAT y PDF se sustituyen por dobles en las pruebas del pipeline; el test tributario existente genera XML real con InvoiceBuilder. No son pruebas de homologación ni comprobación fiscal de todos los catálogos.
- La deprecación ya existía en FacturaTaxRateTest bajo PHP 8.5; no se actualizaron dependencias.
- No se afirma haber probado alta concurrencia de numeración/idempotencia: requiere fase 2.

## Operación y deuda pendiente

Antes de desplegar, aplicar la migración en el entorno elegido con procedimiento habitual. Como `EmitFacturaJob` ya no existe, una cola que contenga payloads serializados con esa clase debe limpiarse o terminarse antes de desplegar esta versión. El worker nuevo puede ejecutarse con `php artisan queue:work documents --timeout=60`; retry_after de documents es 180 segundos. Un worker database existente que lea la misma tabla/cola también puede ejecutar los jobs; comprobar siempre timeout < retry_after. No se arrancó un worker contra la BD compartida.

No revertir la migración mientras existan jobs nuevos pendientes: necesitan payloads e intentos. El rollback fue probado sólo en esquemas aislados vacíos de trabajo.

Pendiente: aislamiento cliente/empresa, idempotencia y external_reference atómicas, carrera de creación/configuración de series, notas, GRE asíncrona, resúmenes/bajas completos, polling/retries con jitter y reconciliación, outbox/webhooks firmados/DLQ, API clients/scopes, v1, salud, observabilidad, recuperación de artefactos y OpenAPI/Postman. El callback Nova best-effort fue eliminado; las notificaciones regresarán mediante outbox/webhooks durables en las fases 6–7. GenFile sigue siendo dependencia externa. La configuración del emisor no está versionada en el snapshot.

## Cierre del flujo legacy de factura/boleta

1. `EmitFacturaJob` fue eliminado.
2. Sus referencias reales eran `EmitFacturaAction`, la importación/inyección residual del action en `FacturacionController` y la documentación de esta fase. Ya no había una ruta que lo despachara después del primer cambio de Fase 1.
3. Factura 01 y boleta 03 persisten, reservan numeración, crean submission y encolan exclusivamente en `AdmitElectronicDocument`; el procesamiento ocurre exclusivamente en `ProcessElectronicDocumentJob` mediante `DocumentProcessorResolver`.
4. Permanece `POST /api/facturacion/emitir-factura` como fachada HTTP. Sólo valida y delega a `AdmitElectronicDocument`; no numera, persiste, crea submissions, llama a SUNAT ni selecciona jobs.
5. Se eliminaron `EmitFacturaJob`, `EmitFacturaAction`, `LegacyBillingCallback`, `FacturaService::emitir`, sus getters de resultado mutable y `McrPersistenceService::storeAccepted`.
6. No queda otro camino de emisión de factura/boleta en rutas, controladores, actions, services, jobs, tests, commands, recursos o documentación ejecutable. `BoletaSummaryService` sigue siendo un flujo posterior de resumen diario y no emite una factura/boleta nueva.

La ventana inevitable entre una respuesta remota y su checkpoint sigue requiriendo reconciliación. Si se interrumpe ahí, failed significa fallo técnico con resultado SUNAT potencialmente desconocido; nunca autoriza cambiar el correlativo y volver a emitir automáticamente.
