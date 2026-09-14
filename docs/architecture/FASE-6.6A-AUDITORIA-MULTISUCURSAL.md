# Fase 6.6A — Auditoría multisucursal / multiestablecimiento

**Fecha:** 2026-09-14  
**Alcance:** lectura únicamente. No se crearon migraciones, no se modificó código funcional, no se cambiaron payloads, series ni bases de datos.

## Dictamen ejecutivo

SunFacturation es **multiempresa**, pero todavía no es multisucursal fiscal real. `McrCompanyConfig` concentra identidad legal, domicilio, credenciales y ambiente; `McrSeries` sólo se distingue por empresa + tipo + serie; `McrDocument` conserva empresa y serie, pero no establecimiento. Nova sí es multisucursal operacional: `RstBranch` tiene PK `BrhID`, pertenece a `BrhResID`, y `RstOrder`/`RstSale` conservan `BrhID`. Falta el vínculo fiscal y el snapshot de establecimiento.

La recomendación es introducir conceptualmente `McrEstablishment` como entidad fiscal de SunFacturation, perteneciente obligatoriamente a una empresa. El documento debe guardar explícitamente el establecimiento que lo originó; la serie debe pertenecer a ese establecimiento cuando la configuración represente puntos de emisión distintos. No se debe ampliar la idempotencia ni `external_reference`: ambos identifican la operación lógica de la venta.

## Fuentes oficiales y separación de responsabilidades

| Tema | Requisito SUNAT | Comportamiento Greenter | Política interna propuesta |
|---|---|---|---|
| Establecimiento | El RUC registra establecimientos anexos y SUNAT asigna su código; el RCP indica que los tres primeros caracteres de la serie identifican el punto de emisión y que las series no se intercambian entre establecimientos. | `Greenter\\Model\\Company\\Address::setCodLocal()` recibe el código del establecimiento; el ejemplo oficial de Greenter usa `0000` cuando no corresponde. | El código SUNAT y la dirección fiscal viven en Establishment; nunca se acepta una dirección fiscal libre de Nova para emitir. |
| 01/03/07/08 | Se emiten bajo los anexos UBL de factura, boleta, NC y ND de SUNAT. | Se construyen como `Invoice`, `Note`/`CreditNote`/`DebitNote` con `Company` + `Address`. | El contexto del emisor se resuelve desde Company + Establishment y queda congelado en payload/XML/documento. |
| 09/31 | GRE remitente/transportista sustentan traslado; partida y llegada son direcciones logísticas independientes; puede existir código de establecimiento cuando corresponda. | La versión instalada expone `Despatch`, `Shipment::setPartida(Direction)` y `setLlegada(Direction)`; `Direction` también tiene `setCodLocal()`. | Establishment identifica al emisor; partida/llegada siguen siendo datos del traslado y pueden ser almacén, fundo, planta o tercero. |

Fuentes: [SUNAT: anexos y estándares CPE](https://orientacion.sunat.gob.pe/10-anexos), [RCP: series y puntos de emisión](https://www.sunat.gob.pe/legislacion/comprob/regla/capituloIII.pdf), [SUNAT: GRE y código de establecimiento](https://cpe.sunat.gob.pe/node/114), [SUNAT: requisitos de dirección de partida/llegada](https://orientacion.sunat.gob.pe/01-definicion-y-aspectos-generales), [Greenter: ejemplo de factura y `setCodLocal`](https://github.com/thegreenter/firststeps/blob/master/factura.php), [Greenter API `Address`](https://reference.greenter.dev/doc-index.html), [Greenter `Despatch`](https://github.com/thegreenter/greenter/blob/master/packages/core/src/Core/Model/Despatch/Despatch.php).

SUNAT es la fuente normativa; Greenter sólo documenta el modelo técnico. Una capacidad de Greenter no crea por sí misma un requisito tributario.

## Auditoría de SunFacturation

### Company actual

La migración `2026_09_12_000000_create_mcr_billing_tables.php:21-44` confirma que `McrCompanyConfig` contiene `McrRuc`, razón social, nombre comercial, ubigeo, departamento, provincia, distrito, urbanización, dirección, SOL, certificado, OAuth (`McrClientId/McrClientSecret`) y `McrEnvironment`. Estos datos son de empresa/RUC y credenciales; no deben duplicarse por sucursal. `McrIsActive` y `SecStatus` también son de la empresa.

### Series y numeración

`McrSeries` (`:46-56`) tiene hoy scope **company + document type + series code**, con `McrNextCorrelative` protegido por `lockForUpdate()` en `AdmitElectronicDocument`. La serie llega opcionalmente en el payload; si hay cero o más de una activa para el tipo se exige enviarla (`AdmitElectronicDocument.php:142-165`). No existe noción de sucursal.

Recomendación: una serie fiscal se asigna a un único Establishment. Si el código de serie es globalmente único dentro de una empresa, el scope efectivo sigue siendo company + type + series y `McrEstablishmentID` aporta validación/consulta; si se desea permitir el mismo código en dos locales, el constraint debe pasar a company + establishment + type + series y la numeración queda separada. La política más segura es **impedir series iguales entre establecimientos de una misma empresa**, porque SUNAT define la serie como identificador del punto de emisión y prohíbe intercambiarla.

El algoritmo de correlativos ya es transaccional y no debe copiar `BrhID` en la tabla de numeración si la serie identifica inequívocamente el establecimiento. Dos series distintas no deben bloquearse lógicamente entre sí.

### Documentos y snapshots

`McrDocument` (`:58-105`) guarda empresa, serie, correlativo, payload hash, estado y artefactos, pero no establecimiento ni snapshot fiscal explícito. Los procesadores actuales construyen Greenter con datos actuales de `McrCompanyConfig` y `Address::setCodLocal('0000')` (`FacturaService.php:119-130`); esto confirma que hoy la dirección emisor se toma de Company y puede cambiar retrospectivamente en reconstrucciones.

El documento debe añadir conceptualmente `McrEstablishmentID` y un snapshot inmutable de código, nombre, dirección, ubigeo y demás campos que hayan entrado al XML/PDF. El payload persistido y el XML firmado son la evidencia primaria; el outbox sólo necesita identidad resumida.

### Idempotencia, external reference y webhooks

La admisión usa `client + company + McrKey`; `external_reference` se resuelve con `client + company + reference`. No hay razón para incluir establecimiento: `RST-SALE-{SalID}` y `RST-SALE-{SalID}-{tipoDoc}` ya representan la operación lógica y la venta contiene la sucursal. Cambiar esas claves introduciría una identidad distinta para la misma venta.

Los webhooks están limitados por client + company. Debe mantenerse ese límite de seguridad; en el futuro puede añadirse filtro opcional de establecimiento en una suscripción, sin convertirlo en requisito de entrega histórica. El envelope puede añadir `data.establishment {id, external_code, name}` sin copiar toda la dirección.

### Notas y GRE

Una NC/ND que referencia un documento debe heredar y validar el mismo establecimiento del original; no debe permitirse cambiar de local arbitrariamente. Para GRE 09/31, el establecimiento del emisor no sustituye `Shipment.partida` ni `Shipment.llegada`; son conceptos independientes y el código local en una `Direction` sólo se usa cuando el escenario SUNAT lo exige.

### PDF y administración

El PDF actual imprime nombre/RUC/dirección de Company (`InvoicePdfService.php`), por lo que no identifica correctamente un local distinto. El diseño futuro debe mostrar razón social/RUC y, debajo, nombre/código/dirección del establecimiento snapshot. La administración futura puede empezar por API administrativa, seeder o CLI controlado; no hace falta UI en esta fase.

## Auditoría de Nova Restaurante

`Restaurant.Domain.Entities.RstBranch` tiene `BrhID`, `BrhResID`, nombre, dirección, teléfono, email, responsable y moneda. No tiene código SUNAT, ubigeo ni mapping fiscal. `RstSale` sí conserva `BrhID` directamente junto con `OrdID`, y `RstCheckoutService.CloseSale` valida que la orden y la sucursal coincidan antes de crear la venta. Por tanto Checkout ya conoce la sucursal y no debe pedirla otra vez al facturar.

`RstSale` mantiene referencias del microservicio (`SalBillingDocumentID`, submission, external reference, estado y artefactos), pero no un snapshot fiscal de la sucursal. Para una venta histórica debe conservarse al menos el `BrhID` y el mapping fiscal resuelto al momento del checkout; el establecimiento fiscal detallado debe permanecer en SunFacturation.

Ownership recomendado:

* Nova: sucursal comercial, nombre interno, operación, mesas, inventario y relación venta→sucursal.
* SunFacturation: establecimiento fiscal, código SUNAT, dirección/ubigeo fiscales, series, numeración y credenciales.
* Integración: `BillingEstablishmentCode` estable y controlado por Nova (por ejemplo `RST-BRANCH-1`) resuelto por SunFacturation dentro de la empresa. No depender del PK remoto.

## Respuestas a las 35 preguntas solicitadas

1. Multiempresa: sí, con CompanyID en documentos, series, idempotencia y webhooks.
2. Multisucursal real: no; sólo Nova es multisucursal operacional.
3. Sirve: aislamiento por empresa, series, locks, idempotencia, payload hash, outbox y `BrhID` en Nova.
4. Falta: Establishment, mapping, snapshot, validaciones y scope fiscal de series.
5. SUNAT exige registrar establecimientos/códigos y respetar punto de emisión; GRE exige direcciones logísticas específicas.
6. Greenter soporta `CodLocal`, `Address`, `Direction`, `Shipment.partida/llegada`.
7. Company: RUC, razón social, credenciales, certificado, ambiente y configuración tributaria global.
8. Establishment: código SUNAT, nombre/local, dirección, ubigeo y estado.
9. Snapshot: código/nombre/dirección/ubigeo del establecimiento, serie, correlativo y payload/XML/PDF resultantes.
10. `McrDocument` necesita `McrEstablishmentID` explícito.
11. `McrSeries` debe conocer Establishment; preferiblemente FK y constraint que impida series ambiguas.
12. Unicidad futura recomendada: company + type + series + establecimiento; política operativa impide repetir el código dentro de la empresa.
13. Numeración: contador independiente por serie; no duplicar branch si la serie ya identifica local.
14. Idempotencia: permanece client + company + key.
15. `external_reference`: permanece `RST-SALE-{SalID}`.
16. 01/03: establishment se resuelve antes de reservar y se usa en Company Address/CodLocal.
17. 07/08: heredan establishment del documento afectado y validan misma empresa/contexto.
18. 09/31: emisor y local son separados de partida/llegada.
19. Outbox: añadir resumen de establishment, no toda la configuración fiscal.
20. Webhooks: company sigue siendo frontera; filtro de establishment opcional futuro.
21. Nova Branch actual: `BrhID`, `BrhResID`, nombre, dirección, teléfono, email, manager, moneda.
22. Faltan: código/mapping fiscal estable, estado de vinculación y, sólo si Nova necesita mostrarlo, datos fiscales de lectura.
23. Mapping: código externo estable `RST-BRANCH-n` hacia Establishment de SunFacturation.
24. Checkout: sí conoce `BrhID` en Order y Sale.
25. RstSale: conserva BranchID, pero no snapshot del establecimiento fiscal.
26. Frontend futuro: sección breve “Configuración fiscal” en Branch, separada de datos operativos.
27. Migración: Establishment, defaults explícitos, series, documentos, snapshots, constraints e índices, en ese orden.
28. Datos actuales: crear un default sólo si la única relación empresa/local es demostrable; nunca escoger el primero.
29. Segunda sucursal: registrar Establishment, asignar código y series, crear mapping Nova y activar después de validar.
30. Ejemplo: Piura F001/B001, Sullana F002/B002, Lima F003/B003; Nova sólo envía el código de su branch.
31. Inactiva: no nuevas emisiones; históricos, artefactos y webhooks siguen consultables.
32. Dirección: nuevo snapshot desde la fecha de cambio; documentos anteriores conservan la anterior.
33. Código/configuración: identidad estable no se edita destructivamente; cambios producen nueva versión/snapshot.
34. Concurrencia: 50 en F001 y 50 en F002 deben generar 1..50 por serie sin colisión.
35. Impacto: aditivo y compatible si el campo de establecimiento es opcional para legacy y obligatorio sólo en nuevas admisiones una vez migrado.

## Migración futura (no ejecutada)

1. Crear `McrEstablishment` con FK obligatoria a Company y `external_code` estable.
2. Crear establecimiento principal por empresa sólo cuando la evidencia sea inequívoca.
3. Vincular series existentes al default únicamente cuando pueda demostrarse.
4. Añadir `McrEstablishmentID` a documentos y snapshots.
5. Backfill documental sólo con evidencia; los no resolubles quedan explícitos.
6. Añadir FK, índices y constraints; exigir establishment en admisiones nuevas.
7. Versionar payload/API y publicar compatibilidad legacy: resolver default sólo si existe exactamente uno.

## Validaciones futuras

Devolver 422 antes de reservar si el establecimiento no existe, pertenece a otra empresa, está inactivo, carece de código/dirección/ubigeo requerido, no tiene serie activa para el tipo, o el mapping Nova no existe. Nunca reservar correlativo para una combinación inválida.

## FASE 6.6B — plan propuesto (no ejecutado)

Implementar en cortes verificables: (1) modelo y administración de Establishment; (2) mapping Nova y contrato de admisión; (3) series/numeración; (4) snapshot documental y PDF/XML; (5) notas y GRE; (6) outbox/webhooks; (7) migración histórica PostgreSQL; (8) pruebas de aislamiento, concurrencia, cambios de dirección, inactividad y compatibilidad legacy; (9) UI mínima de Branch. PostgreSQL será obligatorio para migraciones, constraints, índices, locks y concurrencia; SQLite quedará sólo para lógica independiente del motor.

## Conclusión de la auditoría

El cambio es recomendable antes de producción si se prevén nuevas sucursales. No requiere romper las Fases 1–6.5 ni cambiar idempotencia/external reference. Sí requiere hacer explícito el establecimiento fiscal, separar ownership entre Nova y SunFacturation y congelar el contexto en cada documento. Esta fase no modificó código funcional, migraciones, payloads, series, producción ni la BD compartida.
