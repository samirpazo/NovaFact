# Fase 2 — identidad, idempotencia y numeración

Esta fase aplica el principio permanente [PostgreSQL como fuente de verdad](PRINCIPIOS-TECNICOS.md#postgresql-como-fuente-de-verdad).

## Problema y decisión

La idempotencia anterior vivía en middleware, comparaba bytes HTTP y tenía una clave global. Podía dejar registros `processing`, devolver 409 para una repetición legítima y no era atómica con correlativo, documento, submission o job. La empresa se seleccionaba implícitamente y una serie ausente se creaba durante la emisión.

La admisión recibe ahora `AdmissionContext(clientId, companyId, idempotencyKey, externalReference)` antes de entrar al dominio. Dentro de una transacción se valida cliente/empresa/serie, se reclama la clave idempotente mediante UNIQUE, se bloquea la fila de serie, se reserva el correlativo, se persisten documento/líneas/payload/submission y se inserta el job durable. Cualquier fallo revierte todo.

El orden es deliberado: la clave se inserta antes de bloquear/incrementar la serie. En PostgreSQL, un INSERT concurrente sobre la misma restricción espera la decisión de la transacción ganadora. Tras un `23505`, la aplicación abre una lectura fuera de la transacción abortada, compara hashes y reconstruye la identidad ganadora o devuelve 409. No existe un `SELECT → INSERT` desprotegido.

Las series deben existir, pertenecer a la empresa y tipo, y estar activas. Si no se envía serie, sólo se selecciona implícitamente cuando existe exactamente una serie activa para empresa/tipo; cero o varias producen 422. No hay autocreación.

## Hashes canónicos

Se almacenan dos hashes con propósitos distintos:

- `McrIdempotency.McrRequestHash` y `McrDocument.McrRequestHash`: identidad semántica de admisión antes del correlativo asignado por la plataforma.
- `McrDocumentPayload.McrPayloadHash` y `McrDocument.McrPayloadHash`: snapshot exacto que procesa el worker, incluido el correlativo reservado. El worker recalcula este hash antes de resolver el processor.

`SalesPayloadNormalizer` aplica los defaults efectivos del DTO antes del hash. El objeto incluye tipo, serie resuelta, fecha normalizada ISO-8601, moneda, cliente, totales, tasa y líneas. `Idempotency-Key`, `external_reference` y cualquier correlativo enviado por el consumidor no forman parte del documento tributario procesado; la plataforma siempre asigna el correlativo. Los importes y cantidades se convierten a números PHP, por lo que `118`, `118.0` y `"118.00"` válidos son equivalentes. Las claves de objetos se ordenan recursivamente. El orden de arrays —en particular items— se conserva.

Los campos opcionales conocidos se materializan con su default. Para campos nullable conocidos, ausente y `null` son equivalentes. Un valor no nulo incompatible cambia el hash. Campos desconocidos no pasan el FormRequest ni se incorporan silenciosamente al snapshot. El snapshot canónico, no el cuerpo HTTP, es la entrada del worker.

## Idempotency-Key y external_reference

`Idempotency-Key` identifica el intento HTTP lógico dentro de `(client, company)`. `external_reference` identifica la operación del sistema origen dentro del mismo ámbito.

- Misma key y mismo hash: mismo `document_id` y `submission_id`, con estado actual.
- Misma key y hash diferente: 409.
- Misma external reference, key diferente y mismo hash: misma operación. Se registra además la nueva key como alias idempotente de esa operación.
- Misma external reference y hash diferente: 409.
- Si key y external reference apuntan a operaciones diferentes: 409.
- Cambiar sólo la key no permite duplicar una external reference admitida.

## Contexto HTTP transitorio

La fachada `POST /api/facturacion/emitir-factura` sigue siendo la única entrada HTTP para 01/03. `LegacyAdmissionContextResolver` resuelve antes del dominio:

- cliente activo mediante `X-Client-Code`, con default configurable `BILLING_LEGACY_CLIENT_CODE=legacy`;
- empresa activa del ambiente mediante `X-Company-Id` o `BILLING_LEGACY_COMPANY_ID`;
- si no hay ID configurado/header, sólo acepta cuando existe exactamente una empresa activa;
- Idempotency-Key obligatoria y external_reference validada desde el payload.

Esta resolución no es autenticación definitiva: el bearer token global sigue temporalmente. La fase de clientes/scopes reemplazará el resolver HTTP sin cambiar `AdmissionContext` ni los índices.

## Migración y datos históricos

`2026_09_13_000100_add_admission_identity.php` crea `McrApiClient`, añade identidad explícita al documento y amplía `McrIdempotency`. Crea un cliente técnico de migración con code `legacy`. Los documentos históricos reciben ese cliente; las identidades históricas se asocian con documento/submission cuando la relación puede demostrarse por `McrIdempotencyKey`. En ese caso heredan del documento `McrApiClientID` y `McrCompanyConfigID`, además de enlazar `McrDocumentID` y el primer submission histórico cuando existe.

Una identidad que no pueda vincularse inequívocamente a un documento conserva `McrCompanyConfigID`, `McrDocumentID` y `McrSunatSubmissionID` en `NULL`. Conserva el cliente técnico `legacy` sólo como marca del origen de migración; éste no representa una empresa fiscal. La migración no consulta la primera empresa, empresa activa ni configuración default para completar datos históricos.

Las columnas de identidad permanecen nullable únicamente para conservar esos registros históricos. Toda admisión nueva entra mediante `AdmissionContext`; `AdmitElectronicDocument` exige un cliente activo, una empresa activa y una serie activa perteneciente a esa empresa antes de insertar `McrIdempotency`, `McrDocument` o `McrSunatSubmission`. Por tanto, el dominio no crea operaciones nuevas incompletas aunque el esquema permita los `NULL` históricos.

Auditoría read-only de la BD compartida antes de migrar: 23 claves idempotentes, todas únicas y no nulas; 14 pueden relacionarse inequívocamente con un documento y por ello tienen empresa demostrable; 9 quedan huérfanas; ninguna clave tiene más de un documento candidato. Existen 17 documentos, sin números duplicados; 3 documentos no tienen clave; los 17 tienen `McrSeriesID` nulo. Las series F001 next=2 y B001 next=25 son coherentes con máximos 1 y 24. Actualmente `McrCompanyConfig` contiene una empresa. No se modificó ningún dato remoto.

El downgrade restaura las restricciones globales antiguas únicamente si no existen claves iguales entre scopes. Si ya hay solapamientos legítimos, aborta antes de tocar el esquema. Esto evita un rollback parcial/inconsistente.

## Restricciones e índices

- Se conserva `UX_McrDocument_Number(company, document_type, series, correlative)`.
- Se conserva `UX_McrSeries_CompanyTypeCode(company, type, series)`.
- `UX_McrIdempotency_ScopeKey(client, company, key)` reemplaza la key global.
- `UX_McrDocument_ClientCompanyExternal` es UNIQUE parcial para external_reference no nula.
- `IX_McrDocument_Client` soporta consulta por consumidor.
- `IX_McrDocument_CompanyStatus` soporta operación por empresa/estado.
- `IX_McrIdempotency_Operation` permite reconstrucción documento/submission.
- Los índices existentes cubren número documental, submission/estado y limpieza de idempotencia. No se agregó otro índice idéntico al UNIQUE parcial.

PostgreSQL usa por defecto `NULLS DISTINCT` en una restricción UNIQUE: dos filas con el mismo cliente y key, pero `company_id = NULL`, no colisionan porque cada `NULL` se considera distinto. Esto es aceptable para identidades históricas huérfanas, que no participan en reconstrucción scoped de operaciones nuevas. No se añade una restricción artificial para ellas. Las nuevas admisiones siempre llevan empresa no nula por la validación del dominio y quedan protegidas por el UNIQUE `(client, company, key)` con la semántica scoped prevista.

## Archivos

Nuevos: `McrApiClient`, `AdmissionContext`, `AdmissionResult`, `SalesPayloadNormalizer`, `LegacyAdmissionContextResolver`, migración de identidad y pruebas PostgreSQL concurrentes.

Modificados: `AdmitElectronicDocument`, controller/FormRequest/ruta/bootstrap, `PayloadCodec`, configuración/env, fixtures y suites de pipeline. Eliminado: `IdempotencyMiddleware` y `McrPersistenceService`; su última responsabilidad de numeración pasó a la admisión estándar.

## Garantía arquitectónica y límites

Factura y boleta conservan una sola ruta: `AdmitElectronicDocument → ProcessElectronicDocumentJob → DocumentProcessorResolver → processor`. El controller sólo valida, resuelve contexto y delega. No reaparecieron job/action/método legacy ni callback Nova. El processor usa exclusivamente el número y snapshot persistidos.

Esta fase no implementa notas, GRE, retries SUNAT, outbox, webhooks, scopes ni administración de tokens. Tampoco aplica migraciones a la BD compartida.

## Pruebas ejecutadas

- Suite normal SQLite: 40 passed, 159 assertions, 7 pruebas PostgreSQL omitidas de forma explícita, 1 deprecación preexistente.
- Suite completa PostgreSQL temporal: 47 passed, 240 assertions, 1 deprecación preexistente.
- Concurrencia real mediante procesos y conexiones independientes: 20 solicitudes con misma key/payload; conflicto simultáneo de key; 20 solicitudes con misma external reference y keys diferentes; conflicto simultáneo de external reference; 50 numeraciones concurrentes; y scopes simultáneos por cliente/empresa.
- Migración con datos históricos: `up → down → up`, dos empresas, documentos con correlativos 77 y 88, sus submissions e identidades asociables, más una identidad huérfana que conserva empresa nula. Se verificaron FK, conservación de filas, índices PostgreSQL reales y `NULLS DISTINCT` mediante una segunda key huérfana idéntica.
- Se mantuvieron los tests de rollback de payload/submission/job, hash del snapshot, ejecución del job serializado, resultados SUNAT y garantía contra el pipeline eliminado.

No se realizaron llamadas a SUNAT, no se arrancaron workers sobre la BD compartida y no se aplicaron migraciones remotas.
