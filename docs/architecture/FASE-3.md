# Fase 3 — notas de crédito 07 y notas de débito 08

## Auditoría previa

El proyecto usa `greenter/greenter` 5.3.0. La dependencia instalada contiene `Greenter\Model\Sale\Note`, `NoteBuilder` con plantillas UBL 2.1 separadas para `CreditNote` y `DebitNote`, resolución SOAP como comprobante, lectura de `BillResult`/CDR y la plantilla de `greenter/report` que presenta tipo, documento afectado y motivo. Antes de esta fase el resolver sólo registraba 01/03; no había DTO, processor ni admisión completa para notas. `McrDocumentReference` existía, pero sólo almacenaba tipo y número textual, sin FK opcional ni campos estructurados.

La auditoría PostgreSQL de la BD compartida se ejecutó en una transacción `READ ONLY`: `McrDocumentReference` tiene cero filas y `McrDocument` no contiene tipos 07/08. No se alteró el esquema ni los datos remotos.

## Arquitectura

Los cuatro tipos usan el mismo flujo:

`AdmitElectronicDocument → ProcessElectronicDocumentJob → DocumentProcessorResolver → processor → Greenter → DocumentLifecycle`

`DocumentType` centraliza 01, 03, 07 y 08. El resolver dirige 07 a `CreditNoteProcessor` y 08 a `DebitNoteProcessor`. Ambos processors reciben exclusivamente el documento y snapshot ya persistidos y delegan el mapeo/emisión a `NoteService`; no reservan números, crean identidades, resuelven empresa ni modifican referencias.

`NoteService` construye el modelo real `Greenter\Model\Sale\Note`, obtiene XML firmado por `GreenterService`, persiste exactamente esos bytes antes del envío, usa el transporte SOAP común y conserva el checkpoint fiscal antes de generar CDR/PDF. Los estados continúan siendo `accepted`, `accepted_with_observations`, `rejected` y `failed`.

## Referencias

La migración `2026_09_13_000200_extend_document_references_for_notes.php` amplía `McrDocumentReference` con:

- `ReferencedMcrDocumentID`, FK nullable con `ON DELETE RESTRICT`;
- `McrIsExternal`, nullable para no clasificar arbitrariamente filas históricas;
- serie, correlativo, fecha, moneda y documento del adquirente del comprobante afectado;
- índice por documento interno e índice por número externo estructurado.

Una referencia interna exige `reference.kind=internal` y `document_id`. El documento persistido es la fuente de verdad: debe pertenecer a la misma empresa, ser 01/03, estar `accepted` o `accepted_with_observations`, y coincidir con moneda/adquirente. Cualquier tipo, serie, correlativo u otro dato redundante enviado que contradiga al documento causa 422. Se persisten la FK y los datos tributarios resueltos.

Una referencia externa exige `reference.kind=external`, tipo 01/03, serie, correlativo, fecha, moneda y documento del adquirente. No se crea un `McrDocument` ficticio. SUNAT contempla notas respecto de comprobantes no emitidos en el mismo sistema; el UBL transmite tipo y número de referencia, no una clave interna del microservicio. La empresa emisora siempre es la empresa explícita de `AdmissionContext`.

Cada nota modifica un solo comprobante. La familia de la serie de la nota debe coincidir con la del documento: F para factura y B para boleta. Las series deben estar preconfiguradas para 07 u 08; nunca se autocrean.

## Catálogos y reglas

`CreditNoteReason` representa el catálogo SUNAT 09: 01 anulación de operación, 02 error en RUC, 03 error en descripción, 04 descuento global, 05 descuento por ítem, 06 devolución total, 07 devolución por ítem, 08 bonificación, 09 disminución en el valor, 10 otros conceptos, 11 ajustes de exportación, 12 ajustes IVAP y 13 corrección de monto/fechas de pago. `DebitNoteReason` mantiene separado el catálogo 10 admitido por este contrato: 01 intereses por mora, 02 aumento de valor y 03 penalidades/otros conceptos.

Reglas SUNAT implementadas: motivo perteneciente al catálogo del tipo de nota, una sola referencia, documento afectado 01/03 y familia F/B coherente. Los códigos de crédito 08, 11, 12 y 13 están catalogados pero se rechazan explícitamente con 422: bonificación necesita afectación no onerosa; exportación e IVAP necesitan categorías tributarias que este contrato aún no representa; y código 13 necesita cuotas/condiciones de pago. No se acepta silenciosamente un XML tributariamente incompleto.

Invariantes internas: ownership estricto, estado fiscal aceptado, coincidencia de moneda/adquirente, total de nota de crédito no mayor al original y total idéntico para motivos 01, 02, 03 y 06. Los importes comparados con el original se convierten a centavos enteros. La validación HTTP conserva la comprobación de sumas de base e IGV ya usada por 01/03.

Política del microservicio: se permiten varias notas legítimas sobre un origen y no se bloquea la fila origen, pues en esta fase no existe un acumulado fiscal autorizado que deba serializarse. Cada nota mantiene su propia identidad, serie y FK. No se implementa un límite acumulado entre notas parciales: hacerlo correctamente requiere considerar notas aceptadas, rechazadas y anulaciones posteriores. Tampoco se permiten notas encadenadas; este alcance admite únicamente origen 01/03.

## Contrato

Referencia interna:

```json
{
  "tipoDoc": "07",
  "serie": "FC01",
  "external_reference": "RST-CN-5001",
  "reference": {
    "kind": "internal",
    "document_id": 321,
    "reason_code": "04",
    "reason": "Descuento comercial global"
  },
  "fechaEmision": "2026-09-13",
  "tipoMoneda": "PEN",
  "clientTipoDoc": "6",
  "clientNumDoc": "20123456789",
  "clientRznSocial": "Cliente SAC",
  "mtoOperGravada": 100,
  "mtoIGV": 18,
  "mtoTotal": 118,
  "items": [{
    "codigo": "P001",
    "descripcion": "Ajuste comercial",
    "unidad": "NIU",
    "cantidad": 1,
    "mtoBaseIgv": 100,
    "igv": 18,
    "mtoValorUnitario": 100,
    "mtoValorVenta": 100,
    "mtoPrecioUnitario": 118
  }]
}
```

`items` debe contener al menos una línea con la misma estructura tributaria de factura/boleta. Referencia externa:

```json
{
  "tipoDoc": "08",
  "serie": "FD01",
  "reference": {
    "kind": "external",
    "document_type": "01",
    "series": "F001",
    "correlative": 900,
    "issue_date": "2026-08-01",
    "currency": "PEN",
    "customer_document_type": "6",
    "customer_document_number": "20123456789",
    "reason_code": "02",
    "reason": "Aumento en el valor"
  }
}
```

Los campos tributarios, adquirente e items comunes se agregan igual que en el primer ejemplo. La referencia resuelta forma parte tanto del hash canónico de admisión como del snapshot exacto del worker. Se heredan sin variantes la idempotencia `(client, company, key)` y la unicidad de `external_reference`.

## API y errores

La fachada existente `POST /api/facturacion/emitir-factura` acepta temporalmente 07/08 y delega íntegramente a `AdmitElectronicDocument`. No emite, persiste ni encola por cuenta propia. Serie, referencia, motivo, ownership, estado, moneda o adquirente incompatibles producen 422. Conflictos de idempotencia o `external_reference` producen 409. Los errores técnicos se registran sin exponer mensajes SQL o secretos.

## XML, CDR, PDF y artefactos

Las pruebas construyen XML UBL 2.1 real mediante `NoteBuilder` y comprueban raíz `CreditNote`/`DebitNote`, ID, tipo y número afectado, `ResponseCode`, descripción, moneda, línea e impuestos. El pipeline prueba que el XML firmado devuelto por el borde Greenter se persiste antes de enviar exactamente esos bytes.

El CDR ZIP, código, descripción, observaciones y respuesta normalizada usan `BillResult`, `ProcessingResult`, `McrSunatResponse` y `DocumentLifecycle`. `greenter/report` reutiliza `invoice.html.twig`, que incluye denominación de nota, referencia y motivo; Dompdf genera el PDF. Las rutas incluyen ID interno, RUC incorporado por el nombre Greenter, tipo, serie y correlativo, por lo que no sobrescriben artefactos históricos.

## Evidencia y límites

La suite funcional cubre 07/08 sobre factura y boleta internas, referencia externa, motivos inválidos, empresa incorrecta, origen inexistente/rechazado, serie incompatible, XML, PDF, aceptación/rechazo SUNAT simulados, CDR, idempotencia y `external_reference`. PostgreSQL cubre carreras de 20 solicitudes por key, 20 por referencia externa, 50 numeraciones por serie para cada tipo, dos notas legítimas concurrentes sobre un origen y migración `up → down → up` multiempresa con FK `RESTRICT`.

- Suite rápida: 58 pruebas aprobadas, 275 aserciones, 15 casos PostgreSQL omitidos explícitamente y una deprecación preexistente.
- Suite PostgreSQL real: 73 pruebas aprobadas, 397 aserciones y una deprecación preexistente.
- La migración de referencias superó `up → down → up`, preservó referencias históricas de dos empresas sin inventar FK y verificó `ON DELETE RESTRICT` con una nota distinta del documento origen.

No se llamó a SUNAT, no se aplicaron migraciones remotas y no se implementaron GRE, retries avanzados, polling, outbox, webhooks, retenciones ni percepciones.

Fuentes normativas y técnicas consultadas:

- SUNAT, Anexo N.° 8, catálogo 09: https://www.sunat.gob.pe/legislacion/superin/2020/anexo3-193-2020.pdf
- SUNAT, contenido de Nota de Crédito y regla del código 13: https://www.sunat.gob.pe/legislacion/superin/2021/anexo-165-2021.pdf
- SUNAT, notas sobre comprobantes no emitidos en el mismo sistema: https://orientacion.sunat.gob.pe/01-notas-electronicas-emitidas-respecto-de-comprobantes-de-pago-no-emitidos-en-el-sistema
- SUNAT, catálogo 10: https://www.sunat.gob.pe/legislacion/superin/2017/anexosV-318-2017.pdf
- Greenter 5.3.0 y ejemplo oficial de `Note`: https://github.com/thegreenter/greenter y https://github.com/thegreenter/demo/blob/master/examples/nota-debito.php
