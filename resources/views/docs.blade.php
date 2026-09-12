<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nova Facturación · Documentación</title>
    <style>
        :root { color-scheme: dark; --blue: #002aff; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Inter, Arial, sans-serif; background: #0b1020; color: #f5f7ff; }
        main { width: min(680px, calc(100% - 40px)); padding: 40px; border: 1px solid #26345f; border-radius: 18px; background: #121a30; box-shadow: 0 20px 60px #0006; }
        h1 { margin-top: 0; color: #6f8cff; } a { color: #9db0ff; } code { color: #b8c6ff; }
    </style>
</head>
<body><main>
    <h1>Nova Facturación Electrónica</h1>
    <p>Microservicio para emisión y consulta de comprobantes electrónicos.</p>
    <h2>API</h2>
    <p><code>POST /api/facturacion/emitir-factura</code></p>
    <p><code>GET /api/facturacion/submissions/{submissionId}</code></p>
    <p><code>GET /api/facturacion/archivo/{tipo}/{nombre}</code></p>
    <p>Consulta la colección de Postman incluida en <code>docs/postman</code>.</p>
    <p><a href="/">← Volver al inicio</a></p>
</main></body>
</html>
