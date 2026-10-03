@extends('layouts.public')
@section('title', 'NovaFact · Facturación electrónica para tus sistemas')
@section('content')
<main id="contenido" class="home-main">
<section class="home-intro">
    <div><h1>Una API fiscal.<br>Tus sistemas conectados.</h1><p class="intro-copy">Integra comprobantes electrónicos y guías de remisión en tus aplicaciones. NovaFact admite cada solicitud, procesa su envío y conserva el resultado fiscal.</p><div class="home-actions"><a class="button primary" href="/docs#inicio">Empezar la integración <span aria-hidden="true">→</span></a><a class="text-link" href="/docs#documentos">Ver documentos compatibles</a></div><p class="integration-caption">Credenciales por consumidor · Empresas autorizadas · Procesamiento asíncrono</p></div>
    <div class="code-example"><div class="code-title"><span>Una solicitud, un resultado trazable</span><span class="method post">POST</span></div><pre><code>/api/facturacion/emitir-factura
Authorization: Bearer &lt;credencial&gt;
Idempotency-Key: nova-sale-42-01

<span class="code-muted">// HTTP 202 · solicitud admitida</span>
{
  "document_id": 42,
  "submission_id": 42,
  "status": "queued",
  "document_number": "F001-00000042"
}</code></pre><div class="code-foot">La aceptación se consulta después del procesamiento.</div></div>
</section>
<section class="home-path" aria-labelledby="flujo"><h2 id="flujo">De la solicitud a la evidencia fiscal</h2><ol><li><strong>Admite</strong><p>Valida el documento y reserva su número con una clave de idempotencia.</p></li><li><strong>Procesa</strong><p>El worker envía el XML firmado y consulta el resultado remoto cuando corresponde.</p></li><li><strong>Integra</strong><p>Consulta el estado, descarga los artefactos y recibe cambios mediante webhooks firmados.</p></li></ol></section>
<section class="home-reference"><div><h2>El contrato, en un solo lugar.</h2><p>Autenticación, seis tipos de documento, bajas, resúmenes, errores y operación. Ejemplos completos para integrar otros sistemas.</p><a class="text-link" href="/docs">Abrir documentación <span aria-hidden="true">→</span></a></div><dl><div><dt>Comprobantes</dt><dd>Factura, boleta y notas de crédito y débito</dd></div><div><dt>Traslado</dt><dd>Guía remitente y guía transportista</dd></div><div><dt>Operaciones fiscales</dt><dd>Resumen diario RC y comunicaciones de baja RC/RA</dd></div></dl></section>
<div class="notice"><strong>Estado de validación</strong><p>Los comprobantes, resúmenes y bajas tienen evidencia de aceptación en SUNAT beta. La validación externa de ambas guías GRE está pendiente de credenciales del proveedor de pruebas.</p><a href="/docs#validacion">Consultar alcance y pendientes →</a></div>
</main>
@endsection
