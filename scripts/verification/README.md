# Verificación aislada

## PostgreSQL completo

La suite usa exclusivamente 127.0.0.1:55439, base postgres y usuario pipeline_test,
sin heredar credenciales de .env. Crea un esquema aleatorio por prueba y lo elimina.
Requiere PostgreSQL, extensión PHP pgsql y pcntl para concurrencia.

```bash
/opt/homebrew/opt/postgresql@18/bin/initdb -D /private/tmp/novafact-readiness-pg -U pipeline_test -A trust --encoding=UTF8 --no-locale
/opt/homebrew/opt/postgresql@18/bin/pg_ctl -D /private/tmp/novafact-readiness-pg -l /private/tmp/novafact-readiness-pg.log -o '-h 127.0.0.1 -p 55439 -k /private/tmp' start
PIPELINE_TEST_POSTGRES=1 php -d memory_limit=512M vendor/bin/pest --compact --do-not-cache-result
```

No repetir initdb si el directorio existe. En otro sistema, ajustar la ubicación del
binario PostgreSQL. No ejecutar estas pruebas sobre una base de producción.

## HTTP y Playwright MCP

```bash
mkdir -p /private/tmp/novafact-http-storage/framework/{views,sessions,cache/data} /private/tmp/novafact-http-storage/logs
export APP_ENV=testing APP_STORAGE_PATH=/private/tmp/novafact-http-storage
export DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=postgres DB_USERNAME=pipeline_test DB_PASSWORD=''
export DB_SCHEMA=readiness_http CACHE_STORE=array SESSION_DRIVER=array SUNAT_PRODUCTION=false
php scripts/verification/prepare-http.php
php artisan serve --host=127.0.0.1 --port=18081 --no-reload
```

Ejecutar `http.playwright.js` con browser_run_code_unsafe(filename) de Playwright MCP.
Comprueba diez respuestas HTTP, documentación y límites de identidad. La credencial
pública isolated-demo-token solo se instala en la base aislada, nunca en la real.
Las pruebas Pest cubren emisión, artefactos, outbox y aislamiento entre dos consumidores
con dos empresas; las comprobaciones HTTP del navegador son complementarias.

## SUNAT beta

En una terminal distinta (sin las variables del entorno HTTP), con certificado demo
configurado para una empresa beta:

```bash
SUNAT_PRODUCTION=false php scripts/verification/beta.php --company=1
```

El script bloquea producción, usa MODDATOS público en memoria, emite documentos nuevos
con series de prueba y no escribe documentos en la base. Conserva XML, ZIP CDR y JSON
en un directorio privado temporal. No incluye secretos en los resultados. Valida el
adaptador y sus XML contra SUNAT beta; no sustituye las pruebas del pipeline de cola.
Consulta tickets pendientes sin reenviar: `SUNAT_PRODUCTION=false php scripts/verification/beta.php --company=1 --poll-directory=/ruta/evidencia`. Sin CDR, el resultado sigue pendiente.
Las guías necesitan client_id/client_secret y el proveedor gre-test.nubefact.com;
esa aceptación debe registrarse separadamente de SUNAT beta SOAP.

Para recorrer el pipeline HTTP contra beta, ejecutar `prepare-beta-http.php` con
las mismas variables aisladas de la sección HTTP. Genera un certificado efímero
exclusivamente demo y configura series. Importar la colección Postman, usar
base_url=http://127.0.0.1:18081 y auth_token=isolated-demo-token. Admitir factura y
boleta, ejecutar el worker con --stop-when-empty y consultar submissions. Después
admitir las notas con reference.document_id igual al ID de la factura aceptada.
Para resumen y bajas, ejecutar el worker, `billing:fiscal-reconcile`, nuevamente
el worker y consultar operations. Conservar una clave por operación y no reenviar
si aparece manual_review. El certificado generado no sirve para producción.
