# Fase 4 — Guía de Remisión Electrónica 09/31

## Auditoría y arquitectura

El flujo retirado era `POST emitir-guia → EmitGuiaAction → GuiaService → XML → SunatRestService/OAuth → envío REST → hasta seis sleep(2) → consulta → respuesta HTTP`. Elegía `EmpresaRepository::getActive()`, generaba dos veces el XML, no persistía documento/submission/ticket y exponía errores del transporte. Los comandos `sunat:test-guia*` duplicaban ese envío y accedían a privados con reflection.

El único flujo es `HTTP → LegacyAdmissionContextResolver → AdmitElectronicDocument → ProcessElectronicDocumentJob → DocumentProcessorResolver → SenderDespatchProcessor/CarrierDespatchProcessor → XML/ZIP → GRE REST → ticket persistido → PollSunatSubmissionJob delayed → CDR → terminal`. `emitir-guia` queda como fachada delgada. `historial-guia/{ticket}` sólo lee persistencia. Se eliminaron `EmitGuiaAction`, `GuiaService`, `GuiaData`, `GreValidatorService`, `SunatRestService` y los comandos directos. No existe `EmitGuiaJob` o `EmitGreJob`.

## Greenter y SUNAT

Se inspeccionaron `greenter/greenter 5.2.0` y `greenter/gre-api 1.0.2`. La API real usa `Despatch`, `Shipment`, `Direction`, `Transportist`, `Driver`, `Vehicle`, `AdditionalDoc`, `DespatchDetail` y `DespatchBuilder`; la plantilla `despatch2022.xml.twig` genera UBL 2.1. No existen builders separados por 09/31. REST usa `AuthApi`, `CpeApi`, `CpeDocument` y `CpeDocumentArchivo`. El paquete monolítico contiene `HtmlReport` y `despatch.html.twig`.

SUNAT exige XML firmado, ZIP `RUC-TIPO-SERIE-CORRELATIVO.zip`, OAuth2, ticket y consulta posterior. `98` sigue en proceso; `99` informa error; sólo el CDR determina aceptación, observaciones o rechazo. Fuentes: [sistema GRE y reglas publicadas al 20-06-2026](https://cpe.sunat.gob.pe/node/116), [catálogo 20](https://cpe.sunat.gob.pe/node/171), [tipos GRE](https://cpe.sunat.gob.pe/node/114) y [manual de servicios](https://cpe.sunat.gob.pe/sites/default/files/inline-files/Manual_Servicios_GRE.pdf).

Greenter representa el remitente de 31 mediante `setTercero()`/`SellerSupplierParty`; se eliminó el parche DOM/reflection. Las pruebas inspeccionan el XML generado y firmado por la dependencia instalada.

## Contrato, catálogos y reglas

`DespatchData` conserva el snapshot anidado. `DespatchPayloadNormalizer` valida antes de numerar: fecha, destinatario, motivo, modalidad, peso, origen/destino con ubigeo y bienes. 09 requiere serie T; 31 requiere serie V y remitente. Transporte público requiere transportista; privado requiere conductor y vehículo.

Catálogos implementados: motivos 01 venta, 02 compra, 04 entre establecimientos, 08 importación, 09 devolución, 10 exportación, 13 otros y 19 sin destinación aduanera; modalidades 01 pública y 02 privada; identidades 0/1/4/6/7/A; unidades KGM/TNE/NIU/ZZ. Documentos relacionados incluyen tipo, número, emisor y descripción. Escenarios aduaneros que necesitan contenedor, precinto, puerto, manifiesto o DAM quedan rechazados por falta de contrato específico; no se crea XML parcial.

Peso admite tres decimales y cantidades seis mediante `DecimalMeasure`. Se rechazan floats, notación científica, coma, exceso de escala y cero. El hash usa strings canónicos; sólo la frontera tipada de Greenter convierte a float.

## Persistencia, polling y seguridad

09/31 reutilizan `McrDocument`, líneas, payload, submission, response, attempts, idempotencia scoped y `external_reference`. La logística queda en JSON estructurado; las columnas monetarias del documento/líneas permanecen en cero. La migración reversible `2026_09_13_000300_add_gre_artifacts.php` agrega `McrZipPath` y `McrZipFilID`. Rutas incluyen empresa/tipo/serie/correlativo. Se persisten XML firmado exacto, ZIP enviado, ticket/respuesta inicial, CDR y PDF.

`awaiting_sunat` separa recepción SUNAT del procesamiento local. Envío es attempt `gre_rest`; cada consulta es `gre_poll`. El job sólo transporta `submissionId` y reconstruye empresa/ticket desde PostgreSQL. Backoff: 5, 15, 30 y 60 segundos, sin bucles ni `sleep()`. Timeout, respuesta ambigua o límite agotado conservan `awaiting_sunat`; nunca se inventa rechazo.

OAuth se cachea por `expires_in` menos un margen de cinco minutos, bajo una clave con `company_id` y hash de `client_id`. Perder cache sólo renueva credencial. La empresa siempre sale de `McrDocument.McrCompanyConfigID`. TLS se verifica y hay timeouts 10/30 s. No se guardan ni registran token, password SOL o client secret.

## Evidencia y límites

SQLite: suite completa aprobada. PostgreSQL 18 temporal: suite completa, migración `up → down → up`, 50 correlativos concurrentes para T001, 50 para V001, 20 requests simultáneos por key y 20 external references por tipo. Se probaron XML UBL firmado, PDF, ZIP exacto, ticket, pending→accepted, rechazo, timeout→retry y recuperación desde IDs persistidos.

Auditoría compartida read-only: una empresa, cero series 09/31, cero documentos GRE; las estructuras ticket/attempt ya existen. No se aplicó ninguna migración ni escritura. No se llamó SUNAT beta porque no había series/logística GRE segura; el transporte fue simulado. No se usó SUNAT productivo y Nova no fue modificado.

Deuda: contrato/catálogos aduaneros avanzados, reglas adicionales y reconciliación amplia del futuro retry engine. Antes del despliegue se deben crear explícitamente series T/V y aplicar la migración. No se implementaron eventos GRE, outbox, webhooks, scopes ni Fase 5.
