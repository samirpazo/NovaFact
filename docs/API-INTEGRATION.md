# Integración NovaFact

El contrato conserva las rutas de `/api/facturacion`. Se requiere `Accept: application/json`
y `Authorization: Bearer <credencial>`. El token identifica al consumidor; los encabezados
no pueden cambiarlo. `X-Company-Id` selecciona una empresa autorizada y es obligatorio
cuando la credencial permite varias. `X-Client-Code` es opcional y, si se envía, debe coincidir.

## Migración y permisos

Ejecutar primero las migraciones. Registrar cliente y empresas activas, y emitir:

```bash
php artisan billing:credential issue --client=nova-restaurant --company=1 --permission=read --permission=emit
php artisan billing:credential revoke --id=1
```

El secreto se muestra una sola vez; solo se almacena su SHA-256. `read` permite consultas
y artefactos, `emit` admisión, y `admin` configuración y suscripciones. `admin` no implica
los otros permisos. Emitir una credencial administrativa separada. Los tokens anteriores
BILLING_SERVICE_TOKEN dejaron de autenticar. La revocación se aplica en la siguiente petición.

## Emisión y resultados

POST `emitir-factura` admite tipos 01,03,07,08; POST `emitir-guia` admite 09,31.
Usar los payloads de `docs/ejemplos` y la colección Postman como ejemplos de campos;
las notas requieren `reference` (interna: document_id; externa: identidad fiscal,
moneda, cliente y motivo). Una referencia interna debe pertenecer al mismo consumidor.

La admisión devuelve 202 y submission_id. No equivale a aceptación fiscal. Consultar
GET `submissions/{submissionId}` y sus artefactos. Mantener una Idempotency-Key estable
por operación (8–128 caracteres: letras, dígitos, punto, guion, dos puntos, guion bajo).
Repetir exactamente el payload devuelve la operación previa; cambiarlo con la misma
clave devuelve 409. No usar una nueva clave para resolver un timeout ambiguo.

401: credencial ausente, revocada o expirada. 403: permiso o empresa no autorizados.
404: objeto ajeno o inexistente. 422: validación. 409: conflicto de idempotencia o
reserva fiscal. Los artefactos ajenos también responden 404, sin acceso directo por nombre.

## Bajas y resúmenes

```bash
curl "$BASE/api/facturacion/boletas/baja" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -H 'X-Company-Id: 1' \
  -H 'Idempotency-Key: nova-void-0001' \
  -d '{"document_id":123,"motivo":"Documento no entregado","not_delivered":true}'

curl "$BASE/api/facturacion/boletas/resumen-diario" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -H 'X-Company-Id: 1' \
  -H 'Idempotency-Key: nova-summary-0001' -d '{"fecha":"2026-10-02"}'
```

La baja aplica a comprobantes no otorgados; los otorgados requieren nota de crédito
según el caso. Las series B usan RC con estado 3; facturas y notas de serie F usan RA.
No se ofrece baja GRE por estas rutas. El plazo implementado es siete días calendario;
se toma aceptación registrada, o fecha de emisión para registros históricos.
Los resúmenes seleccionan documentos B aceptados del consumidor, fecha y empresa;
incluyen solo documentos todavía no reservados o resumidos, y no mezclan monedas. Una moneda mixta se rechaza explícitamente; usa currency=PEN o USD para seleccionarla.

Ambas rutas devuelven 202, operation_id, protocolo y estado. Consultar
GET `operations/{id}` o `boletas/resumen/{ticket}`. Descargar
GET `operations/{id}/files/xml` y `/cdr` cuando estén disponibles.
Estados: queued → processing → pending → polling → accepted/rejected.
`manual_review` retiene resultados ambiguos sin reenvío automático. El documento
solo queda void_accepted después de persistir un CDR verificable.

## Webhooks y operación

Las suscripciones requieren admin y quedan limitadas a consumidor/empresa. Verificar
HMAC sobre los bytes originales y timestamp; deduplicar event_id y aplicar state_version.
La entrega puede repetirse. Se añadió `document.void_accepted`, con data.fiscal_operation y enlaces al XML/CDR de baja; las operaciones de resumen
se consultan por su endpoint y no generan un evento de documento por sí mismas.

Ejecutar worker `queue:work documents --queue=default --tries=1 --timeout=60` y scheduler
cada minuto. Consultar PRODUCCION.md. Los seis tipos no incluyen regímenes especiales;
no interpretar un HTTP 202 ni un ticket como aceptación SUNAT.
