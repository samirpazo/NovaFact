# Fase 5 — Resiliencia fiscal

## Alcance

Esta fase aplica una política durable común a 01, 03, 07, 08, 09 y 31. No incorpora Outbox, webhooks, SignalR ni tipos documentales nuevos. PostgreSQL es el motor de referencia.

## Auditoría del comportamiento anterior

| Camino | Comportamiento previo ante error | Riesgo encontrado |
|---|---|---|
| 01/03 `FacturaService` | Guardaba XML firmado; llamaba SOAP; si obtenía CDR guardaba un checkpoint antes de PDF | Un timeout SOAP caía en un `catch` genérico y acababa en `failed`; no distinguía si SUNAT recibió el XML |
| 07/08 `NoteService` | Igual que 01/03 | Mismo riesgo de reenvío posterior sin evidencia suficiente |
| 09/31 processor | Guardaba XML, ZIP y PDF antes del envío; luego obtenía ticket | El PDF precedía al resultado remoto y un fallo REST sin respuesta no quedaba clasificado como ambiguo |
| `ProcessElectronicDocumentJob` | `$tries = 1`; cualquier excepción terminaba en `failed`, salvo un resultado fiscal ya guardado | No había política durable, checkpoint formal, `NextAttemptAt` ni reconciliación |
| `PollSunatSubmissionJob` | `$tries = 1`; backoff local 5/15/30/60; mantenía `awaiting_sunat` | Backoff y límite estaban embebidos; dos workers podían salir a red después de soltar el row lock |
| lifecycle/submission/attempt | Un intento por ejecución y respuesta persistida | Submission no expresaba ambigüedad, checkpoint, claim, próxima ejecución ni contadores por operación |
| Greenter y fachadas legacy | El pipeline persistido pasaba empresa explícita; helpers SOAP todavía permitían `getActive()` | Una recuperación legacy podía seleccionar credenciales de otra empresa |

Los controllers no tenían un endpoint de retry manual. Por ello no se agregó un endpoint que pudiera interpretarse como “reenviar”; la operación soportada es `billing:reconcile`, que vuelve a evaluar evidencia.

## Modelo de fallos

`FailureCategory` usa códigos cerrados y persistibles:

- `validation_failure`: payload o identidad fiscal inválida; no retry.
- `local_technical_retryable`: error local anterior al contacto remoto con causa recuperable.
- `local_technical_non_retryable`: configuración local que requiere corrección.
- `remote_rejected`, `remote_accepted`, `remote_accepted_with_observations`: hechos fiscales terminales.
- `remote_pending`: SUNAT recibió y continúa procesando.
- `remote_transient_known_safe`: fallo remoto con evidencia de que no hubo admisión; sólo esta categoría permite repetir submission.
- `ambiguous_submission`: hubo o pudo haber contacto remoto sin resultado verificable; nunca reenvía automáticamente.
- `artifact_failure`: el resultado fiscal ya existe y sólo se recuperan archivos.
- `worker_interrupted` y `unknown`: evidencian fallos técnicos sin guardar textos sensibles.

## Checkpoints

La submission conserva uno de: `admitted`, `payload_loaded`, `xml_generated`, `xml_signed`, `submission_started`, `remote_response_received`, `remote_result_persisted`, `artifacts_generated`, `completed`.

`submission_started` es la frontera conservadora. Toda excepción desde ese punto se considera ambigua salvo una respuesta SUNAT verificable. El XML firmado guardado antes del contacto es inmutable; la recuperación no fabrica otro XML fiscal.

## Matriz de decisión

`SubmissionRecoveryPolicy` recibe únicamente evidencia persistida y devuelve `RecoveryAction`.

| Evidencia | Acción |
|---|---|
| Resultado fiscal terminal, artefactos completos | `MarkTerminal` |
| Resultado fiscal terminal, artefactos faltantes | `RegenerateArtifacts` |
| Ticket GRE presente | `PollTicket` |
| Contacto remoto o flag ambiguo sin resultado | `Reconcile` |
| Fallo local retryable anterior al contacto y bajo límite | `RetrySubmission` |
| Documento admitido/queued sin contacto | `Process` |
| Validación, causa no retryable o límite agotado | `ManualReview` |

Esta decisión no se basa en el texto de una excepción.

## Estados y evidencia

Se agregan dos estados documentales técnicos: `reconciliation_pending` y `manual_review`. `accepted`, `accepted_with_observations` y `rejected` siguen siendo terminales. El contrato GET submission expone temporalmente `reconciliation_pending` como `processing` y `manual_review` como `failed` en `status`, además de entregar el valor preciso en `recovery_status`; esto preserva el consumidor Nova actual hasta su versión de contrato posterior.

`McrSunatSubmission` agrega categoría, checkpoint, ambigüedad, `McrNextAttemptAt`, contadores de retry/poll/reconciliación, última reconciliación, razón cerrada de revisión y claim con token/fecha. El índice `IX_McrSunatSubmission_RecoveryDue (McrStatus, McrNextAttemptAt)` limita la búsqueda programada.

`McrSunatAttempt` continúa siendo una fila nueva por operación técnica. Cada fila puede registrar operación, outcome, HTTP, retryability, ambigüedad, checkpoint, ticket y categoría sanitizada. Finalizar la fila activa completa ese evento; nunca modifica filas de intentos anteriores.

## SOAP 01/03/07/08

Antes de SOAP, un error de validación termina en revisión y un error local recuperable conserva número, documento y XML. Al iniciar SOAP se persiste `submission_started`. Timeout, conexión cortada o respuesta no verificable pasan a `reconciliation_pending`; el processor no vuelve a ejecutarse.

SUNAT publica `getStatusCdr(ruc, tipo, serie, numero)` en `billConsultService`, y Greenter lo expone mediante `ConsultCdrService::getStatusCdr`. La reconciliación usa siempre la empresa del documento. Cuando retorna CDR, se persisten CDR y resultado antes de cualquier artefacto. El servicio oficial de consulta está configurado sólo para el entorno productivo; no se consulta producción para documentos beta. Como no hay un mecanismo beta automático confiable equivalente, una ambigüedad beta pasa a `manual_review` con razón `soap_consult_unavailable_environment`. Esto evita resolver la incertidumbre mediante reenvío.

Fuentes técnicas: [Manual del programador SUNAT](https://cpe.sunat.gob.pe/sites/default/files/inline-files/manual_programador%20%281%29.pdf) y [implementación oficial del cliente Greenter `ConsultCdrService`](https://github.com/thegreenter/greenter/blob/master/packages/ws/src/Ws/Services/ConsultCdrService.php).

## GRE 09/31

El envío persiste XML y ZIP exactos, marca contacto y obtiene ticket. El PDF se posterga hasta que exista resultado fiscal. Con ticket, toda recuperación ejecuta polling y jamás vuelve a `send`. El poller persiste un claim antes de salir a red, registra una fila de intento, mantiene ticket y programa la siguiente consulta mediante `McrNextAttemptAt`. Un CDR terminal se persiste antes de encolar recuperación del PDF.

## Retry, backoff y límites

`RetryBackoffPolicy` centraliza segundos y topes:

| Operación | Backoff | Máximo |
|---|---|---:|
| procesamiento seguro | 10, 30, 120 | 3 |
| polling GRE | 5, 15, 30, 60, 120, 300, 600, 900 | 8 |
| reconciliación | 30, 120, 600, 1800 | 4 |

No existe `sleep()`. Al agotar el límite el resultado no se convierte en rechazo: queda `manual_review` con código de razón.

## Reinicios, stale processing y locking

Un claim se vuelve stale a los dos minutos. `billing:reconcile` busca estados recuperables vencidos y `processing` con claim stale. Cada job vuelve a adquirir `FOR UPDATE`; el comando usa `FOR UPDATE SKIP LOCKED` en PostgreSQL. `McrClaimToken` mantiene exclusión mientras el worker está fuera de la transacción y en contacto con SUNAT. La entrega duplicada converge: estados terminales, claims activos y ejecuciones antes de `McrNextAttemptAt` terminan sin acción.

Escenarios de crash:

- antes de SUNAT: puede reusar documento, número y XML mediante retry técnico limitado;
- después de iniciar envío y antes de respuesta: reconciliación, nunca reenvío;
- después de resultado fiscal y antes de PDF: recuperación de artefactos;
- después de ticket GRE: polling del ticket persistido.

## Artefactos

`RecoverDocumentArtifactsJob` requiere un resultado fiscal persistido. Para 01/03/07/08 reconstruye sólo el modelo visual desde el snapshot y registra/genera PDF; para 09/31 genera el PDF GRE. Reutiliza el XML firmado existente y registra CDR existente. Si falta el XML exacto, se detiene con `artifact_recovery_failed`: no firma otro XML. La recuperación SOAP guarda el CDR recuperado por `getStatusCdr` antes de completar el resultado.

## Multiempresa y seguridad

Procesadores, transportes, polling y reconciliación cargan `Empresa` desde `McrCompanyConfigID` del documento. `GreenterService` ya no acepta empresa nullable ni usa `EmpresaRepository::getActive()`. Las fachadas de resumen/baja/estado legacy exigen `X-Company-Id`. Logs estructurados incluyen IDs, empresa, tipo, referencia externa, intento, operación y categoría; no incluyen exception message, credenciales ni tokens.

## Operación

El comando manual es:

```bash
php artisan billing:reconcile --limit=100
```

Admite `--company`, `--document` y `--submission`, limita a 1000 por ejecución y muestra examinadas, process/retry, polling, reconciliación, artefactos, terminales, revisión y errores. Laravel Scheduler lo ejecuta cada cinco minutos con `withoutOverlapping`. En producción debe existir un único `schedule:run` por minuto o `schedule:work` administrado por el supervisor.

## Validación PostgreSQL

La migración `2026_09_13_000400_add_fiscal_recovery.php` es reversible. La suite PostgreSQL cubre `up → down → up`, preservación de 01/03/07/08/09/31, uso del índice mediante `EXPLAIN`, claims simultáneos, polling idempotente, recuperación multiempresa y `NextAttemptAt`. SQLite se mantiene para feedback rápido de lógica no dependiente del motor.

## Limitaciones pendientes

- La consulta SOAP segura automática queda limitada al servicio oficial productivo; los documentos beta ambiguos requieren revisión manual.
- Si SUNAT no devuelve un CDR recuperable y tampoco existe ticket, el sistema conserva incertidumbre y no reenvía.
- No existe UI de revisión manual; se opera por base/command y logs hasta una fase posterior.
- No hay endpoint de retry manual porque antes no existía; cualquier futuro endpoint debe invocar la política de recuperación, nunca el processor o transporte directamente.
- Outbox y webhooks pertenecen a Fase 6.
