# Fase 6.6B — Multisucursal fiscal

## Modelo y propiedad

`McrCompanyConfig` conserva RUC, razón social, certificado, SOL/OAuth, ambiente y política tributaria.
`McrEstablishment` representa el punto fiscal de emisión y pertenece obligatoriamente a una empresa.
Contiene código externo estable, código SUNAT de cuatro dígitos, nombres, dirección, referencia opcional,
ubigeo, departamento, provincia, distrito, país, estado y marca de default.

El código externo es único por empresa. PostgreSQL impone un solo establecimiento activo/default mediante
un índice parcial. Series y documentos usan una FK compuesta por establecimiento y empresa, impidiendo
cruces entre compañías.

## Series, numeración y admisión

Cada serie pertenece a un establecimiento. La unicidad continúa siendo
`company + document_type + series`; la serie identifica inequívocamente el local y su propia fila mantiene
el contador con `lockForUpdate()`. F001 y F002 avanzan independientemente. La emisión nunca crea series.

El payload acepta `establishment` con el external code. Se valida antes de serie y correlativo. Si se omite,
sólo se usa un default activo inequívoco; nunca “el primero”. El local resuelto entra en el payload canónico:
la misma idempotency key con otro local produce 409. El scope `client + company + key` y
`external_reference` no cambian.

## Documento y snapshot

`McrDocument` conserva `McrEstablishmentID` y un snapshot JSONB con códigos, nombres, dirección, referencia,
ubigeo y divisiones geográficas. XML firmado, PDF, recuperación y Outbox usan el estado congelado. Cambiar
la dirección actual no altera comprobantes históricos.

01/03 usan el snapshot y `Address::setCodLocal()`. 07/08 internos heredan el local original y rechazan otro;
referencias externas deben declararlo. 09/31 conservan el establecimiento emisor sin reemplazar partida o
llegada, que siguen siendo datos logísticos independientes.

## Eventos, administración y compatibilidad

Outbox v1 añade `data.establishment` con `external_code`, `sunat_code` y `name`. No cambian event ID,
state version, HMAC ni scope de webhook. Los endpoints autenticados de establecimientos permiten listar,
crear, actualizar y desactivar; no hay borrado destructivo. ExternalCode y código SUNAT quedan bloqueados
cuando existen documentos. La asignación administrativa de series acepta el external code.

La migración crea `DEFAULT/0000` por empresa sólo si dirección y ubigeo existentes permiten un default
inequívoco; vincula sus series/documentos y congela snapshot. Datos incompletos permanecen históricos con
NULL, mientras toda admisión nueva exige configuración completa. Clientes legacy sin campo sólo funcionan
con ese default inequívoco.

PostgreSQL es la evidencia de persistencia: FKs, índice parcial, migraciones y 100 admisiones simultáneas
repartidas entre F001/F002. SQLite queda como ciclo rápido. SUNAT productivo no se utiliza.
