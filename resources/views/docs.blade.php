@extends('layouts.public')
@section('title', 'NovaFact · Documentación API')
@section('content')
<div class="docs-layout"><aside class="docs-sidebar" data-open="false"><button class="mobile-docs-nav" aria-expanded="false" aria-controls="indice">En esta guía ↓</button><div class="sidebar-content" id="indice"><label class="docs-search-label" for="buscar">Buscar una sección</label><input id="buscar" class="docs-search" type="search" placeholder="Ej. bajas, webhooks"><nav class="docs-nav" aria-label="Índice de documentación"><a href="#inicio">Primeros pasos</a><a href="#auth">Autenticación y permisos</a><a href="#documentos">Documentos y ejemplos</a><a href="#idempotencia">Idempotencia</a><a href="#operaciones">Bajas y resúmenes</a><a href="#estados">Estados y errores</a><a href="#archivos">Archivos fiscales</a><a href="#webhooks">Webhooks</a><a href="#operacion">Operación</a><a href="#validacion">Validación comprobada</a><a href="#produccion">Pase a producción</a></nav><p class="nav-empty" hidden>Sin coincidencias.</p><a class="sidebar-resource" href="/docs/postman">Descargar colección Postman ↓</a></div></aside><main class="docs-main" id="contenido">
<section id="inicio"><h1>Integra tu sistema con NovaFact.</h1><p class="docs-lead">Emite comprobantes para tus empresas, consulta su estado fiscal y recibe cada resultado en tu sistema.</p><div class="docs-tools"><a class="button primary" href="/docs/postman">Descargar Postman ↓</a><a href="#documentos">Ver solicitudes completas</a></div><ol><li>Obtén una credencial y selecciona una empresa autorizada.</li><li>Envía el documento con una clave de idempotencia.</li><li>Conserva el identificador de envío y consulta el resultado o recibe un webhook.</li></ol><p class="docs-note"><strong>HTTP 202 confirma la admisión.</strong> La aceptación fiscal llega después, durante el procesamiento y la consulta del resultado remoto.</p></section>
<section id="auth"><h2>Autenticación y permisos</h2><p>Cada credencial identifica a un sistema consumidor y limita las empresas y acciones disponibles. El token compartido anterior ya no concede acceso general.</p><div class="table-wrap"><table><thead><tr><th>Encabezado</th><th>Uso</th></tr></thead><tbody><tr><td><code>Authorization: Bearer TOKEN</code></td><td>Credencial revocable del consumidor.</td></tr><tr><td><code>X-Company-Id</code></td><td>Empresa autorizada. Obligatorio si hay varias empresas disponibles.</td></tr><tr><td><code>X-Client-Code</code></td><td>Opcional; debe coincidir con el cliente de la credencial.</td></tr><tr><td><code>Idempotency-Key</code></td><td>Identificador estable de la solicitud de emisión u operación.</td></tr></tbody></table></div><p><span class="permission">read</span> permite consultar y descargar; <span class="permission">emit</span> permite emitir; <span class="permission">admin</span> administra configuración fiscal, certificados y suscripciones. Las credenciales de consumidor se emiten o revocan por CLI. Los permisos se conceden por separado.</p></section>
<section id="documentos"><h2>Documentos y ejemplos</h2><p>Las solicitudes siguientes coinciden con la colección Postman. Sustituye sus variables con los datos de tu entorno y usa documentos relacionados de la misma empresa para las notas.</p><details class="doc-details"><summary><span class="doc-code">01</span>Factura</summary><div class="endpoint-title"><span class="method post">POST</span><code>/api/facturacion/emitir-factura</code></div><div class="code-block"><header><span>Solicitud JSON · 01</span><button class="copy-button" type="button">Copiar</button></header><pre><code>{
    "tipoDoc": "01",
    "establishment": "DEFAULT",
    "serie": "F001",
    "fechaEmision": "2026-10-03T10:00:00-05:00",
    "tipoMoneda": "PEN",
    "clientTipoDoc": "6",
    "clientNumDoc": "20123456789",
    "clientRznSocial": "Test client",
    "mtoOperGravada": 100,
    "mtoIGV": 18,
    "mtoTotal": 118,
    "items": [
        {
            "descripcion": "Test item",
            "cantidad": 1,
            "mtoBaseIgv": 100,
            "igv": 18,
            "mtoValorUnitario": 100,
            "mtoPrecioUnitario": 118,
            "mtoValorVenta": 100
        }
    ]
}</code></pre></div></details><details class="doc-details"><summary><span class="doc-code">03</span>Boleta</summary><div class="endpoint-title"><span class="method post">POST</span><code>/api/facturacion/emitir-factura</code></div><div class="code-block"><header><span>Solicitud JSON · 03</span><button class="copy-button" type="button">Copiar</button></header><pre><code>{
    "tipoDoc": "03",
    "establishment": "DEFAULT",
    "serie": "B001",
    "fechaEmision": "2026-10-03T10:00:00-05:00",
    "tipoMoneda": "PEN",
    "clientTipoDoc": "1",
    "clientNumDoc": "12345678",
    "clientRznSocial": "Test client",
    "mtoOperGravada": 100,
    "mtoIGV": 18,
    "mtoTotal": 118,
    "items": [
        {
            "descripcion": "Test item",
            "cantidad": 1,
            "mtoBaseIgv": 100,
            "igv": 18,
            "mtoValorUnitario": 100,
            "mtoPrecioUnitario": 118,
            "mtoValorVenta": 100
        }
    ]
}</code></pre></div></details><details class="doc-details"><summary><span class="doc-code">07</span>Nota Credito</summary><div class="endpoint-title"><span class="method post">POST</span><code>/api/facturacion/emitir-factura</code></div><div class="code-block"><header><span>Solicitud JSON · 07</span><button class="copy-button" type="button">Copiar</button></header><pre><code>{
    "tipoDoc": "07",
    "establishment": "DEFAULT",
    "serie": "FC01",
    "fechaEmision": "2026-10-03T10:00:00-05:00",
    "tipoMoneda": "PEN",
    "clientTipoDoc": "6",
    "clientNumDoc": "20123456789",
    "clientRznSocial": "Test client",
    "mtoOperGravada": 100,
    "mtoIGV": 18,
    "mtoTotal": 118,
    "items": [
        {
            "descripcion": "Test item",
            "cantidad": 1,
            "mtoBaseIgv": 100,
            "igv": 18,
            "mtoValorUnitario": 100,
            "mtoPrecioUnitario": 118,
            "mtoValorVenta": 100
        }
    ],
    "reference": {
        "kind": "internal",
        "reason_code": "04",
        "reason": "Ajuste probado",
        "document_id": 123
    }
}</code></pre></div></details><details class="doc-details"><summary><span class="doc-code">08</span>Nota Debito</summary><div class="endpoint-title"><span class="method post">POST</span><code>/api/facturacion/emitir-factura</code></div><div class="code-block"><header><span>Solicitud JSON · 08</span><button class="copy-button" type="button">Copiar</button></header><pre><code>{
    "tipoDoc": "08",
    "establishment": "DEFAULT",
    "serie": "FD01",
    "fechaEmision": "2026-10-03T10:00:00-05:00",
    "tipoMoneda": "PEN",
    "clientTipoDoc": "6",
    "clientNumDoc": "20123456789",
    "clientRznSocial": "Test client",
    "mtoOperGravada": 100,
    "mtoIGV": 18,
    "mtoTotal": 118,
    "items": [
        {
            "descripcion": "Test item",
            "cantidad": 1,
            "mtoBaseIgv": 100,
            "igv": 18,
            "mtoValorUnitario": 100,
            "mtoPrecioUnitario": 118,
            "mtoValorVenta": 100
        }
    ],
    "reference": {
        "kind": "internal",
        "reason_code": "02",
        "reason": "Ajuste probado",
        "document_id": 123
    }
}</code></pre></div></details><details class="doc-details"><summary><span class="doc-code">09</span>Guia Remitente</summary><div class="endpoint-title"><span class="method post">POST</span><code>/api/facturacion/emitir-guia</code></div><div class="code-block"><header><span>Solicitud JSON · 09</span><button class="copy-button" type="button">Copiar</button></header><pre><code>{
    "tipoDoc": "09",
    "serie": "T001",
    "fechaEmision": "2026-10-03T10:00:00-05:00",
    "establishment": "DEFAULT",
    "destinatario": {
        "tipo_documento": "6",
        "numero_documento": "20444444441",
        "razon_social": "Destinatario SAC"
    },
    "traslado": {
        "motivo": "01",
        "modalidad": "02",
        "fecha_inicio": "2026-10-04",
        "peso_bruto": "125.375",
        "unidad_peso": "KGM",
        "bultos": 2,
        "origen": {
            "ubigeo": "150101",
            "direccion": "Av. Origen 123"
        },
        "destino": {
            "ubigeo": "150122",
            "direccion": "Av. Destino 456"
        },
        "conductor": {
            "tipo_documento": "1",
            "numero_documento": "12345678",
            "nombres": "Ana",
            "apellidos": "Quispe",
            "licencia": "Q12345678"
        },
        "vehiculo": {
            "placa": "ABC123"
        }
    },
    "bienes": [
        {
            "codigo": "P001",
            "descripcion": "Producto de prueba",
            "unidad": "NIU",
            "cantidad": "10.500000"
        }
    ],
    "documentos_relacionados": [
        {
            "tipo": "01",
            "numero": "F001-123",
            "emisor": "20123456789"
        }
    ]
}</code></pre></div></details><details class="doc-details"><summary><span class="doc-code">31</span>Guia Transportista</summary><div class="endpoint-title"><span class="method post">POST</span><code>/api/facturacion/emitir-guia</code></div><div class="code-block"><header><span>Solicitud JSON · 31</span><button class="copy-button" type="button">Copiar</button></header><pre><code>{
    "tipoDoc": "31",
    "serie": "V001",
    "fechaEmision": "2026-10-03T10:00:00-05:00",
    "establishment": "DEFAULT",
    "destinatario": {
        "tipo_documento": "6",
        "numero_documento": "20444444441",
        "razon_social": "Destinatario SAC"
    },
    "traslado": {
        "motivo": "01",
        "modalidad": "02",
        "fecha_inicio": "2026-10-04",
        "peso_bruto": "125.375",
        "unidad_peso": "KGM",
        "bultos": 2,
        "origen": {
            "ubigeo": "150101",
            "direccion": "Av. Origen 123"
        },
        "destino": {
            "ubigeo": "150122",
            "direccion": "Av. Destino 456"
        },
        "conductor": {
            "tipo_documento": "1",
            "numero_documento": "12345678",
            "nombres": "Ana",
            "apellidos": "Quispe",
            "licencia": "Q12345678"
        },
        "vehiculo": {
            "placa": "ABC123"
        }
    },
    "bienes": [
        {
            "codigo": "P001",
            "descripcion": "Producto de prueba",
            "unidad": "NIU",
            "cantidad": "10.500000"
        }
    ],
    "documentos_relacionados": [
        {
            "tipo": "01",
            "numero": "F001-123",
            "emisor": "20123456789"
        }
    ],
    "remitente": {
        "tipo_documento": "6",
        "numero_documento": "20333333331",
        "razon_social": "Remitente SAC"
    }
}</code></pre></div></details></section><section id="idempotencia"><h2>Idempotencia</h2><p>Genera una clave por operación de negocio y conserva esa misma clave en los reintentos. Debe tener entre 8 y 128 caracteres: letras, números, puntos, guiones, dos puntos o guiones bajos.</p><p>La misma clave y el mismo contenido recuperan la operación existente. Una clave reutilizada con contenido distinto devuelve <code>409</code>. Conserva también <code>external_reference</code> para relacionar el documento con tu venta.</p></section>
<section id="operaciones"><h2>Bajas y resúmenes</h2><p>Estas operaciones se persisten y procesan en cola. Su respuesta de admisión incluye <code>operation_id</code>; consulta <code>GET /api/facturacion/operations/{id}</code> hasta conocer el resultado fiscal.</p><h3>Resumen</h3><div class="endpoint-title"><span class="method post">POST</span><code>/api/facturacion/boletas/resumen-diario</code></div><div class="code-block"><header><span>Solicitud JSON</span><button class="copy-button" type="button">Copiar</button></header><pre><code>{
    "fecha": "2026-10-03"
}</code></pre></div><h3>Baja</h3><div class="endpoint-title"><span class="method post">POST</span><code>/api/facturacion/boletas/baja</code></div><div class="code-block"><header><span>Solicitud JSON</span><button class="copy-button" type="button">Copiar</button></header><pre><code>{
    "document_id": 123,
    "motivo": "Documento no entregado",
    "not_delivered": true
}</code></pre></div><p>Las bajas de boletas y notas de serie B se comunican mediante resumen diario RC. Las facturas y notas de serie F usan comunicación de baja RA. La solicitud exige <code>document_id</code>, <code>motivo</code> y <code>not_delivered=true</code>.</p><p>El resumen incluye documentos aceptados disponibles para esa fecha. Si hay varias monedas, selecciona la moneda. Cada documento queda reservado para evitar que dos operaciones lo incluyan simultáneamente.</p><p class="docs-note">Solo el CDR confirma una baja. Una respuesta ambigua requiere reconciliación; no reenvíes automáticamente ni reutilices su número.</p></section>
<section id="estados"><h2>Estados y errores</h2><div class="endpoint-title"><span class="method get">GET</span><code>/api/facturacion/submissions/{submissionId}</code></div><p>Consulta el estado, el error fiscal, <code>state_version</code> y la disponibilidad de archivos. Usa la versión para procesar actualizaciones en orden.</p><div class="table-wrap"><table><thead><tr><th>Estado</th><th>Qué hacer</th></tr></thead><tbody><tr><td><code>queued</code> / <code>processing</code></td><td>Esperar el procesamiento.</td></tr><tr><td><code>awaiting_sunat</code></td><td>Esperar la respuesta o consulta del ticket.</td></tr><tr><td><code>accepted</code> / <code>accepted_with_observations</code></td><td>Conservar el CDR y revisar las observaciones.</td></tr><tr><td><code>reconciliation_pending</code> / <code>manual_review</code></td><td>Resolver el resultado ambiguo antes de reenviar.</td></tr><tr><td><code>rejected</code> / <code>failed</code></td><td>Revisar el error; distinguir rechazo fiscal de fallo técnico.</td></tr><tr><td><code>void_accepted</code></td><td>Baja confirmada del documento.</td></tr></tbody></table></div><p><code>401</code>: credencial inválida; <code>403</code>: permisos o empresa no autorizados; <code>404</code>: recurso no disponible para ese consumidor; <code>409</code>: conflicto; <code>422</code>: solicitud inválida. Inspecciona el cuerpo del error antes de reintentar.</p></section>
<section id="archivos"><h2>Archivos fiscales</h2><p>Descarga XML firmado, PDF y CDR cuando la consulta indique su disponibilidad. Las descargas mantienen el mismo aislamiento por consumidor y empresa.</p><div class="code-block"><header><span>Rutas de consulta</span><button class="copy-button" type="button">Copiar</button></header><pre><code>GET /api/facturacion/archivo/{tipo}/{nombre}
GET /api/facturacion/operations/{id}/files/xml
GET /api/facturacion/operations/{id}/files/cdr
GET /api/facturacion/boletas/resumen/{ticket}</code></pre></div><p>El PDF es una representación del comprobante. Conserva el XML y el CDR como evidencia del documento y su respuesta fiscal.</p></section>
<section id="webhooks"><h2>Webhooks</h2><p>Configura una suscripción con una credencial <code>admin</code>. La entrega usa outbox, firma HMAC y reintentos; tu receptor debe tolerar eventos duplicados.</p><div class="code-block"><header><span>Encabezados del evento</span><button class="copy-button" type="button">Copiar</button></header><pre><code>X-SunFacturation-Event-Id: identificador-del-evento
X-SunFacturation-Timestamp: timestamp
X-SunFacturation-Signature: v1=&lt;hex&gt;</code></pre></div><p>Verifica HMAC-SHA256 sobre <code>timestamp.cuerpo_original</code> con el secreto de la suscripción y una comparación de tiempo constante. Valida la antigüedad del timestamp; deduplica por <code>event_id</code> y aplica solo versiones nuevas.</p><p>El evento de baja aceptada incluye <code>data.fiscal_operation</code> para vincular el documento con la operación y sus archivos fiscales.</p></section>
<section id="operacion"><h2>Operación y validación</h2><div class="code-block"><header><span>Procesos necesarios</span><button class="copy-button" type="button">Copiar</button></header><pre><code>php artisan queue:work documents --queue=default --tries=1 --timeout=60
php artisan schedule:run
php artisan webhooks:status
php artisan billing:reconcile --limit=100
php artisan billing:fiscal-reconcile --limit=100</code></pre></div><p>Supervisa el worker y ejecuta el scheduler cada minuto. Configura <code>retry_after</code> mayor que el timeout del worker. La ruta <code>/up</code> comprueba que la aplicación responde; complementa con comprobaciones de base de datos y cola.</p></section>
<section id="validacion"><h2>Validación comprobada</h2><p>Factura, boleta, notas, bajas y resumen diario cuentan con CDR de aceptación en SUNAT beta SOAP. Ambas guías fueron aceptadas por el proveedor GRE demo con código 0 y observación «CDR de prueba».</p><p>Se verificaron cola, consultas, descargas, idempotencia, reinicio, webhooks con HMAC y restauración. La suite PostgreSQL pasó 243 pruebas sin omisiones. Los PDFs de guías comparten cabecera, tipografía y tablas con los comprobantes.</p><div class="notice"><strong>El entorno de pruebas no habilita producción.</strong><p>La evidencia GRE pertenece al proveedor demo. Para operar con SUNAT real, configura credenciales de la empresa y confirma sus condiciones de emisión.</p></div></section>
<section id="produccion"><h2>Preparar el pase a producción</h2><p>Despliega una instancia independiente con PostgreSQL, almacenamiento privado, dominio HTTPS, workers supervisados y scheduler. Mantén beta separada de producción.</p><ol><li><strong>Confirma el sistema de emisión de la empresa.</strong> Revisa RUC, establecimientos, series y próximos correlativos. Si corresponde OSE, el transporte directo actual requiere evaluar esa integración.</li><li><strong>Obtén SOL y certificado digital.</strong> Usa credenciales de la empresa y un certificado vigente con clave privada. El CDT se solicita en SOL cuando se cumplen los requisitos.</li><li><strong>Genera OAuth GRE en SOL.</strong> Busca «Credenciales de API SUNAT / Gestión Credenciales de API SUNAT» y registra la aplicación para obtener <code>client_id</code> y <code>client_secret</code>.</li><li><strong>Configura cada empresa.</strong> SOL y certificado se cargan mediante las rutas administrativas; OAuth GRE, empresa y series se registran por modelos en una sesión administrativa. El secreto se cifra con <code>APP_KEY</code>.</li><li><strong>Activa el entorno productivo.</strong> Usa <code>SUNAT_PRODUCTION=true</code>, empresa con entorno <code>production</code>, <code>APP_DEBUG=false</code> y <code>WEBHOOK_ALLOW_UNSAFE_LOCAL=false</code>. Conserva la clave de cifrado en las actualizaciones.</li><li><strong>Verifica la operación.</strong> Ensaya restauración, revisa colas y realiza una emisión real autorizada. HTTP 202 confirma admisión; el resultado fiscal se comprueba después.</li></ol><p>SOL, OAuth SUNAT y la credencial de consumidor NovaFact cumplen funciones distintas. MODDATOS y las claves públicas demo se usan exclusivamente en pruebas. Nunca entregues certificados o claves fiscales a los sistemas consumidores.</p><div class="docs-tools"><a class="button primary" href="/docs/produccion">Descargar guía de producción ↓</a><a href="https://cpe.sunat.gob.pe/certificado-digital">Procedimiento oficial del certificado</a></div><p>La guía incluye requisitos, origen de credenciales, configuración por empresa, comandos de despliegue, workers, scheduler, respaldos, restauración e incidentes.</p></section></main></div><div class="copy-status" role="status" aria-live="polite"></div>
@endsection
