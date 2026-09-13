# Auditoría previa y plan de evolución — 2026-09-13

Documento redactado antes de modificar código de aplicación. Base: checkout limpio, lectura de rutas, controlador, acciones, DTO, servicios, modelos, migraciones, configuración, pruebas, dependencias instaladas y despliegue. La conexión PostgreSQL fue exclusivamente `BEGIN READ ONLY`, con SSL y límite de consulta. No se emitió a SUNAT ni se ejecutaron migraciones en la BD compartida.

## 1. Estado real

| Capacidad | Estado y evidencia |
| --- | --- |
| Factura 01 | Flujo conectado: StoreFacturaRequest → submission → EmitFacturaJob → acción → Invoice Greenter → XML/SOAP/CDR/PDF → documento/líneas. Persistencia demasiado tardía; aceptación mal clasificada. Existe 1 documento registrado accepted en BD, no validado contra CDR en esta auditoría. |
| Boleta 03 | Mismo flujo individual SOAP de factura; 16 documentos registrados accepted. No acredita un ciclo correcto de comunicación mediante resumen diario. |
| Nota 07/08 | Sin endpoints, DTO, mapper ni processor propios. McrDocumentReference existe pero no se escribe. Greenter instalado usa `Greenter\Model\Sale\Note` + `NoteBuilder` para ambos tipos, no clases CreditNote/DebitNote separadas. |
| GRE 09/31 | DTO, validador, mapper Despatch y REST conectados, síncronos. Guarda XML y eventualmente CDR; no documento, líneas, submission, ticket durable ni PDF GRE. Tipo 31 modifica XML manualmente mediante reflexión/DOM sin prueba de regresión. No hay documentos GRE en McrDocument. |
| Resumen diario | Servicio conectado, toma boletas ya accepted, número max+1 sin bloqueo, SOAP síncrono, persiste cabecera y ticket. No detalles, control isSuccess, finalización, CDR ni actualización de documentos. |
| Baja | Servicio conectado, max+1, SOAP síncrono, McrDocumentID nulo, no finaliza ni relaciona original. Debe distinguir RA de baja de boleta mediante resumen. |
| Consulta | Submission local disponible; tickets consultan SUNAT desde HTTP sin persistir resultado ni verificar pertenencia por cliente. |
| Archivos | XML/CDR/PDF de ventas y descarga con rutas limitadas. Hash SHA-256 en GenFile. Se sobrescriben nombres históricos; fallos de put no comprobados. Respuestas de acciones contienen base64. |
| Idempotencia | Middleware con hash de bytes HTTP y restricción única global. No aislamiento, recuperación tras crash ni atomicidad con documento/cola. |
| Webhook | Nova hardcoded, token global, llamada best-effort; descarta fallos. No firma, outbox, intentos persistentes ni DLQ. |
| Auth | ValidateSessionToken delega realmente a ValidateBillingToken: comparación constante de token global. SessionTokenService es stub sin uso efectivo. Sin clientes, scopes ni autorización por empresa. |
| Pruebas | Baseline: 5 passed, 1 deprecated, 11 assertions. Dos ejemplos básicos, 3 comprobaciones HTTP, 1 XML tributario. No integración persistencia, concurrencia, retries, GRE ni webhooks. |

BD observada: las 11 tablas Mcr del repositorio, GenFile, jobs, failed_jobs y migrations existen. Las 14 migraciones actuales están registradas. Submissions: 15 accepted, 8 rejected. Existen UX_McrDocument_Number, UX_McrSeries_CompanyTypeCode, UX_McrDocument_Idempotency y UX_McrIdempotency_Key; las últimas dos son globales. No existen tablas de clientes, outbox, webhooks ni payloads. No hay tablas cache/cache_locks en public. Los agregados no prueban aceptación tributaria ni correspondencia exacta entre submissions y documentos.

## 2. Arquitectura encontrada

Laravel 12.69.2, Greenter 5.3.0, gre-api 1.0.2 y dompdf. composer.json anuncia PHP ^8.2, pero las dependencias instaladas de desarrollo exigen >=8.4; el contenedor usa 8.4 y el CLI local 8.5.10. Debe distinguirse compatibilidad de producción y tooling antes de prometer PHP 8.2.

Controlador único concentra emisión, configuración, archivos, resumen y baja. EmpresaRepository elige la primera empresa activa por ambiente global. FacturaService mezcla reserva de número, mapeo, firmado, envío, PDF y persistencia. GreenterService vuelve a seleccionar empresa al configurar transporte. ManagedFileService depende de GenFile, que este repositorio no crea: una BD independiente no se instala de forma autosuficiente.

## 3. Problemas técnicos prioritarios

1. **Duplicación tributaria tras error técnico:** la reserva de número ocurre en cada ejecución del job; el documento aparece sólo después del envío, PDF y registro de archivos. Un timeout o fallo posterior al envío permite reservar otro número al reintentar.
2. **Falsos accepted/rejected:** isSuccess de BillResult no sustituye al código CDR. La deduplicación del job acepta cualquier documento SecStatus=true. Errores de configuración se clasifican rejected. GRE confunde recepción de ticket con aceptación.
3. **Concurrencia:** lockForUpdate protege una serie existente, no la creación simultánea de una serie ausente. updateSeries no comparte bloqueo y puede retroceder reservas todavía no persistidas. Los max+1 de resumen/baja también tienen carreras.
4. **Idempotencia:** key global, JSON equivalente con distinto orden genera otro hash; crash deja processing permanente. Encolar y guardar respuesta no son una transacción. Mismo request concurrente devuelve 409 en lugar de operación original.
5. **Auditoría incompleta:** job serializa payload; documento no guarda snapshot, McrPayloadHash no se llena, McrSunatResponse no se usa, intentos se sobreescriben. McrSeriesID y datos tributarios completos de líneas no se guardan.
6. **Desacoplamiento:** callback Nova, token compartido, GenFile externo, empresa implícita y rutas de configuración fijadas a beta. Campos source existen pero no se completan.
7. **GRE:** hasta seis sleep(2); Guzzle sin timeout explícito y TLS desactivado en beta; errores se convierten en arrays sin clasificación. Comentario de token cacheado no corresponde al código. RegExp `[T|V]` acepta `|`; no liga prefijo a tipo. Parseo de fechas se ejecuta tras validación fallida. Validación de items/catálogos incompleta. Descripción de otros motivos no llega al DTO/mapper.
8. **Credenciales:** casts encrypted para las tres contraseñas existen, pero faltan hidden en Empresa. Certificado se sube a app/private/certificates y se lee de app/certificates; no se comprueba PKCS12 antes de guardar. Logs de excepciones OAuth pueden incluir respuestas sensibles.
9. **Validación ventas:** Request descarta anticipos/guías que DTO sí modela; régimen gravado fijo, no cubre exoneradas, inafectas, gratuitas, crédito/cuotas. No confundir esta cobertura con implementación tributaria general.
10. **Operación:** sólo /up; no readiness, correlation, métricas, outbox, DLQ webhook ni recuperación de trabajos interrumpidos. QUEUE_CONNECTION=sync permite ejecutar SUNAT en HTTP aun con ShouldQueue. retry_after/timeout y supervisor deben alinearse.
11. **Migraciones:** SQL de cambio UUID es PostgreSQL específico; FK GenFile bloquea una instalación aislada. Reversiones de desacoplamiento reintroducen tablas Restaurante externas.

## 4. Reutilización

Conservar mapeo Invoice y prueba de tasa IGV, Greenter firmado/SOAP, DTOs como punto de partida, Despatch/REST sujeto a fixtures, generación PDF, tablas Mcr, índices de numeración, cifrado Laravel y descargas acotadas. Extraer responsabilidades progresivamente. **Decisión posterior:** el usuario descartó compatibilidad con jobs históricos; `EmitFacturaJob` y su cadena fueron eliminados al cerrar Fase 1.

## 5. Modelo objetivo

API client y empresa explícitos → validación por tipo → transacción de idempotencia, número, documento, payload, submission y cola durable → job con IDs → resolver → processor documental → transporte → resultado tipado → máquina de estados y respuesta/artefactos. El polling tiene jobs independientes y delays. La aceptación exige evidencia CDR; los errores técnicos y el resultado desconocido nunca se convierten en rechazo tributario.

Procesadores Invoice/Receipt/Notes/Despatch especializados; estados centralizados: created, queued, processing, sent, pending_sunat, accepted, accepted_with_observations, rejected, retry_pending, failed, void_pending, void_accepted, void_rejected. Transiciones bloqueadas transaccionalmente; no se vuelve a enviar un terminal. Ambigüedad tras timeout requiere reconciliación usando número/XML original.

Cada transición genera evento con UUID y correlación; cambio terminal y outbox en la misma transacción. Relay independiente crea deliveries únicas por evento/endpoint. Firma HMAC sobre timestamp + punto + cuerpo exacto UTF-8; retries persistentes con jitter y dead-letter. Consumidores Nova/SunExpert/etc. tienen iguales capacidades mediante scopes, sin nombres incrustados en processors.

## 6. Migraciones necesarias

- Payload por documento: JSON normalizado inmutable, SHA-256; relación única con documento.
- Submission attempts: operación, transporte, tiempos, duración, respuesta/código/error, número único por submission. Correlation IDs y estado de recuperación.
- Clientes API, permisos cliente/empresa, hash de token; idempotencia compuesta y external_reference única por cliente/empresa; preservar claves históricas con ámbito legacy explícito.
- Referencias: ID de documento origen además de tipo/número/motivo.
- Artefactos propios inmutables con hashes y versión por intento, sin FK obligatoria a GenFile.
- Outbox, endpoints, deliveries e intentos con índices de disponibilidad y unicidad evento/endpoint; secretos webhook cifrados.
- Resumen/baja: relaciones con documentos, numeración bloqueada y submissions; ajustes para quitar supuestos exclusivos de venta.
- Instalación autónoma y tablas de infraestructura necesarias. Backfill verificable; nunca migrar datos compartidos implícitamente ni borrar historia.

## 7. Clases nuevas/modificadas

ElectronicDocumentProcessor, DocumentProcessorResolver, InvoiceProcessor, ReceiptProcessor, CreditNoteProcessor, DebitNoteProcessor, SenderDespatchProcessor, CarrierDespatchProcessor; admisión transaccional; ProcessElectronicDocumentJob(documentId, submissionId), CheckSunatTicketJob; DocumentState y servicio de transiciones; RetryPolicy y resultado de transporte; modelos Payload/Attempt/ApiClient/Outbox/WebhookEndpoint/WebhookDelivery; relay/delivery jobs; FormRequests por tipo; middleware API client/scopes/correlation; controladores v1 y health. Modificar FacturaService/McrPersistenceService/EmpresaRepository/GreenterService/GuiaService/SunatRestService de manera gradual. La compatibilidad con `EmitFacturaJob` fue descartada expresamente después de esta auditoría.

## 8. Endpoints propuestos

POST /api/v1/documents; GET /api/v1/documents/{id}, /status, /xml, /cdr, /pdf; POST /api/v1/documents/{id}/void; GET /api/v1/submissions/{id}. Recursos de summaries, endpoints webhook y configuración bajo scopes. /health/live y /health/ready. Rutas /api/facturacion se conservan como adaptadores legacy hasta migrar consumidores. No publicar soporte de tipos cuyo ciclo no esté probado.

## 9. Riesgos y límites

No enviar documentos reales durante pruebas automáticas. No ejecutar migrate/fresh/reset en la BD proporcionada. Montar PostgreSQL aislado para pruebas reales de bloqueo; SQLite no demuestra concurrencia PostgreSQL. Desplegar migraciones antes de código que las usa, drenar workers antiguos y reiniciar. Confirmar política boleta/resumen, régimen emisor y catálogos vigentes antes de ampliar cobertura tributaria. Preservar el workaround XML GRE hasta que una prueba documentada permita sustituirlo. Revisar bytes XML efectivamente enviados frente a guardados: hoy se firman/generan más de una vez.

## 10. Fases y criterios de salida

1. Pipeline conectado para ventas existentes: documento/payload/submission antes de cola, job con IDs, resolver y estados; número estable durante reejecución, pruebas de admisión y resultado. GRE permanece deuda explícita hasta fase 4; no declarar pipeline universal completo.
2. Cliente/empresa explícitos mínimos, idempotencia atómica, external_reference y numeración con pruebas concurrentes PostgreSQL. Adelantar identidad interna de cliente evita rehacer claves en fase 8.
3. Notas con Note de Greenter, validación motivo/original y fixtures UBL; PDF, XML, CDR conectados.
4. GRE 09/31 con snapshots completos y processors; pruebas XML incluyendo remitente, vehículo, conductor, MTC y relacionados.
5. Polling diferido y clasificación de fallos; backoff+jitter, reconciliación y exhaust; incluir resúmenes/bajas (omitidos en el orden sugerido, pero necesarios para cobertura mínima).
6. Eventos y outbox transaccional probado frente a rollback/crash.
7. Webhooks firmados con intentos, timeout, recovery y DLQ; documentación verificador .NET y migración subscriber Nova.
8. API clients/scopes, autorización empresa y rate limiting; compatibilidad legacy acotada.
9. Correlación, salud, seguridad de uploads, artefactos y observabilidad.
10. OpenAPI 3.1, ejemplos, Postman local/beta/production sin secretos, E2E offline y suite PostgreSQL. Sólo documentar capacidades efectivamente conectadas.
11. Retención/percepción condicionadas a soporte del régimen/transporte y evidencia de pruebas. Existen modelos/builders en vendor, pero eso no acredita soporte productivo del servicio. GRE eventos/reversiones quedan como extensiones del contrato, no endpoints vacíos.

Antes y después de cada fase: suite existente + nuevas pruebas; bitácora de archivos, migraciones, decisiones, resultados y deuda. No confundir fixtures/offline con homologación SUNAT.

## Referencias primarias consultadas

- [Greenter: uso y resúmenes/bajas](https://greenter.dev/usage/).
- [Greenter: builders XML UBL](https://greenter.dev/packages/xml/).
- [SUNAT: sistemas GRE y documentos de validación](https://cpe.sunat.gob.pe/node/116).
- [SUNAT: manual de servicios GRE](https://cpe.sunat.gob.pe/sites/default/files/inline-files/Manual_Servicios_GRE.pdf).

La página SUNAT lista reglas GRE de junio de 2026; el comentario del validador local cita abril de 2025. Requiere comparación de catálogos, no sólo cambiar la fecha del comentario.
