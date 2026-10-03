# NovaFact: preparación como API fiscal

**La implementación local y los flujos SOAP beta comprobados están preparados para
integración. El pase integral a producción sigue condicionado a la configuración y validación real de producción.
Ambas guías fueron aceptadas por el proveedor GRE de pruebas.** No se emitió ningún documento productivo.

## Fortalezas conservadas

Se mantienen los seis tipos 01,03,07,08,09,31; admisión transaccional, idempotencia,
correlativos reservados, recuperación de resultados ambiguos, referencias de notas,
cálculos decimales, XML firmado, PDF y outbox con HMAC, reintentos y versiones.
Los cambios reutilizan ese pipeline para la emisión y añaden operaciones durables RC/RA.

## Hallazgos y correcciones

| Gravedad inicial | Problema | Resultado |
|---|---|---|
| Crítica | Token compartido y encabezados que podían seleccionar otro consumidor/empresa | Credenciales SHA-256 revocables, empresas autorizadas, permisos read/emit/admin y rechazo de identidad suplantada |
| Crítica | Consultas, tickets y descargas sin aislamiento | Consultas filtradas por consumidor y empresa; objetos ajenos devuelven 404, sin descarga de archivos huérfanos |
| Alta | Bajas y resúmenes enviados directamente, sin recuperación equivalente | Persistencia antes del efecto remoto, cola transaccional, numeración bloqueada, idempotencia, intentos y reconciliación |
| Alta | Boletas encaminadas como Voided | RC con estado 3 para boletas/notas B; RA para facturas/notas F |
| Alta | Éxito sin comprobar CDR | Estado pending hasta obtener CDR; XML/CDR conservados antes de confirmar; ambigüedad sin reenvío automático |
| Alta | Certificado subido en una ruta distinta a la leída | Lectura del disco privado, compatibilidad de ubicación histórica y validación de PKCS#12/contraseña antes de sustituir configuración |
| Alta | RUC editable después de admitir documentos | Cambio de identidad bloqueado cuando existen documentos |
| Alta | Dependencias instaladas desalineadas con lock y vulnerabilidades conocidas | Vendor sincronizado, Twig/CommonMark actualizados y correcciones npm compatibles; auditorías sin avisos conocidos |
| Media | Uso de RC/RA como tipo de transporte Greenter | Conversión a Summary::class/Voided::class, con regresiones y aceptación beta real |
| Media | Reutilización del contexto SOAP | Contexto independiente al configurar cada operación/empresa |
| Media | Documentación, ejemplos y salud desalineados | Contrato, colección de seis tipos, ejemplos actuales, workers/scheduler y /up documentados |
| Media | Pruebas de migración antiguas incompatibles con el down destructivo actual | Sustituidas por pruebas de creación/restauración de esquema y preservación de datos en migraciones aditivas |

## Evidencia y matriz

Suite PostgreSQL aislada: **243 pruebas, 899 aserciones, cero omitidas y cero
advertencias de deprecación**. Incluye dos consumidores/dos empresas, revocación,
permisos, notas ajenas, archivos/tickets ajenos, sobreacreditación, idempotencia,
concurrencia de numeración y envío/consulta, recuperación antes/después del contacto, resúmenes sucesivos sin duplicar documentos y exclusión de bajas durante un resumen pendiente.

| Flujo | Integración local | Aceptación externa |
|---|---|---|
| Factura 01 | HTTP → cola → consulta → XML/PDF/CDR | SUNAT beta, CDR 0 |
| Boleta 03 | HTTP → cola → consulta → XML/PDF/CDR | SUNAT beta, CDR 0 |
| Nota de crédito 07 | Referencia interna a factura beta, HTTP/cola/artefactos | SUNAT beta, CDR 0 |
| Nota de débito 08 | Referencia interna a factura beta, HTTP/cola/artefactos | SUNAT beta, CDR 0 |
| Guía remitente 09 | HTTP, cola, consulta, XML/ZIP/PDF con logo y QR/CDR | Nubefact GRE demo, CDR 0 con observación «CDR de prueba» |
| Guía transportista 31 | HTTP, cola, consulta, XML/ZIP/PDF con logo y QR/CDR | Nubefact GRE demo, CDR 0 con observación «CDR de prueba» |
| Resumen diario RC | Admisión HTTP, envío y polling por cola, XML/CDR | SUNAT beta, CDR 0; ticket 1790998283517 |
| Baja factura RA | Admisión HTTP, envío y polling por cola, estado fiscal posterior | SUNAT beta, CDR 0; ticket 1790998334382 |
| Baja boleta RC estado 3 | Admisión HTTP, envío y polling por cola, estado fiscal posterior | SUNAT beta, CDR 0; ticket 1790998334513 |
| Webhooks | Seis entregas HTTP a receptor aislado, HMAC válido | Receptor local; no servicio externo |

Playwright MCP comprobó diez respuestas del contrato y /docs sin errores de consola;
además admitió y consultó documentos/operaciones beta y descargó los 18 artefactos
XML/PDF/CDR correspondientes. Los seis webhooks fueron cuatro accepted y dos
void_accepted. El respaldo PostgreSQL se restauró en otra base aislada: cuatro
documentos, tres operaciones y seis entregas conservadas.

El [manifiesto depurado](verification/2026-10-02.json) contiene estados, tickets,
tamaños y SHA-256. Los artefactos están en
`storage/app/private/readiness/2026-10-02/facturacion`, excluidos de Git.
Las claves privadas y los tokens no forman parte del manifiesto.

## Cierre GRE con MODDATOS

Se configuró `credentials.local.json` y la base local con SOL público MODDATOS/MODDATOS.
SOAP conserva el RUC ficticio 20123456789; GRE usa una empresa demo separada, RUC
20161515648, requerido por el proveedor. La empresa GRE local es 2; su credencial
API está en `storage/app/private/novafact-gre-demo-credential.json` (0600).
El certificado autofirmado `novafact-demo.pem` se usa exclusivamente para pruebas.
Los demás apartados privados de `credentials.local.json` se conservaron.

La primera guía transportista recibió 3383. Se corrigió el remitente dentro de
`Shipment/Delivery/Despatch/DespatchParty`, antes de firmar el XML, y se preservó
el rechazo original. Cuatro guías posteriores/del remitente obtuvieron CDR 0,
con observación «CDR de prueba». Los PDFs incluyen logo Nova y el QR demo devuelto
por el CDR; dicho QR no acredita una verificación productiva.

Playwright comprobó admisión, duplicados, conflicto 409, consultas, cinco tickets y
18 descargas. Tras reiniciar la API, las mismas claves conservaron sus IDs.
Cinco webhooks se entregaron con HMAC válido. El respaldo se restauró en otra base:
cinco documentos, cinco entregas y cero trabajos pendientes.
[Manifiesto GRE](verification/2026-10-02-gre.json),
[admisión](verification/2026-10-02-gre-admission.json) y
[resultados HTTP](verification/2026-10-02-gre-results.json).
Los archivos y respaldo están en `storage/app/private/readiness/2026-10-02/gre`.

## Base demo y migración de consumidores

La base local nova_structural_test permanece en beta. Munay se sustituyó por
NovaFact Demo S.A.C./NovaFact en empresa y establecimiento; el seed se actualizó.
No fue necesario vaciar tablas. Se aplicaron ambas migraciones nuevas.
La credencial read/emit para nova-restaurant y empresa 1 está en
`storage/app/private/novafact-demo-credential.json`, con permisos de archivo 0600.
El token compartido anterior ya no autentica: cada consumidor debe migrar siguiendo
[API-INTEGRATION](API-INTEGRATION.md). La administración utiliza una credencial aparte.

## Pendientes y límites

1. **GRE demo completado:** ambos tipos aceptados por gre-test.nubefact.com; no equivale
   a aceptación GRE productiva de SUNAT. Falta validar con credenciales propias,
   certificado válido y las variantes reales de traslado antes de producción.
2. **Producción:** certificado y SOL reales, credenciales GRE productivas, TLS,
   APP_DEBUG=false, WEBHOOK_ALLOW_UNSAFE_LOCAL=false, supervisor de workers/scheduler,
   respaldo de base/artefactos/APP_KEY y restauración ensayada con esa infraestructura.
   Esta entrega no desplegó ni habilitó producción.
3. **Alcance:** ventas gravadas habituales y traslado implementado; regímenes
   especiales y variantes adicionales deben evaluarse antes de ofrecerlas.
   Facturas al crédito/cuotas no están en el contrato inicial (forma de pago contado).
4. **Anulaciones:** baja para documentos no otorgados. Documentos otorgados se
   corrigen mediante notas según la regla aplicable. No se ofrece baja GRE por RC/RA.
   Los registros históricos sin aceptación fechada usan la fecha de emisión para el plazo.
5. **Operación:** manual_review exige contrastar evidencia remota; no existe un
   botón que reenvíe automáticamente resultados ambiguos. El rollback de las
   migraciones originales elimina tablas; no representa una migración de datos legacy.
6. **Capacidad:** se probaron concurrencia y recuperación, sin fijar un SLA de carga
   ni medir volumen máximo diario. Los resúmenes de monedas distintas se separan
   usando currency. Una combinación de monedas sin selección se rechaza.

Los seis tipos cuentan con evidencia externa de los entornos de pruebas disponibles:
SOAP en SUNAT beta y GRE en el proveedor demo. El pase a producción continúa sujeto
a los requisitos anteriores; ninguna emisión de esta entrega fue productiva.

## Reglas consultadas

SUNAT describe la anulación de boletas mediante resumen diario en
[Boleta de venta electrónica](https://cpe.sunat.gob.pe/tipos_de_comprobantes/boleta).
La comunicación de baja y plazo de siete días calendario se contrastaron con
[Operatividad SEE del contribuyente](https://orientacion.sunat.gob.pe/3529-operatividad).
La aceptación beta no sustituye el cumplimiento de las condiciones de emisión de
cada empresa ni la validación de sus credenciales productivas.

Para repetir la validación: [scripts y comandos](../scripts/verification/README.md).
