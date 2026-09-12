# Pruebas automatizadas de facturación

## Variables de entorno

`base_url` (por ejemplo `http://127.0.0.1:8081`), `billing_token`, `submission_id`, `idempotency_key` y `document_id`.

## Flujo recomendado

1. Enviar `POST /api/facturacion/emitir-factura` con `Authorization: Bearer {{billing_token}}` e `Idempotency-Key: {{$guid}}`.
2. Guardar `submission_id` de la respuesta 202 en `submission_id`.
3. Ejecutar `php artisan queue:work database --tries=3`.
4. Consultar `GET /api/facturacion/submissions/{{submission_id}}` hasta obtener `accepted`, `rejected` o `failed`.
5. Con el `document_id`, validar la descarga protegida del PDF/XML/CDR.

## Escenarios

- Factura aceptada: respuesta inicial 202 y luego estado `accepted`.
- Boleta aceptada: cambiar `tipoDoc` a `03` y usar cliente DNI.
- Doble envío: repetir exactamente el mismo payload y `Idempotency-Key`; debe devolver la misma respuesta sin crear otro documento.
- Payload diferente con la misma clave: debe responder 409.
- Dos emisiones simultáneas: usar claves distintas; los correlativos deben ser consecutivos y no repetirse.
- Reintento: detener temporalmente el worker, emitir, volver a levantarlo y verificar `McrAttemptNumber`.
- Descarga segura: probar `/archivo/pdf`, `/archivo/xml` y `/archivo/cdr` con token; sin token debe responder 401.

La colección `SunExpert_API.postman_collection.json` contiene los endpoints base. Estos escenarios pueden ejecutarse con Runner o Newman.
