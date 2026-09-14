# SunFacturation

Microservicio de facturación electrónica para Nova. Admite facturas (`01`) y boletas (`03`) mediante un único pipeline: admisión idempotente, procesamiento asíncrono, emisión hacia SUNAT, generación de artefactos y notificación webhook al consumidor.

## Arquitectura operativa

```text
Nova Restaurante → SunFacturation API (:8081) → PostgreSQL + cola documents
                                      ↓
                         ProcessElectronicDocumentJob
                                      ↓
                   Outbox → webhooks:dispatch → DeliverWebhookJob
                                      ↓
             Nova /api/integrations/sunfacturation/webhook
```

PostgreSQL es el motor de referencia. SQLite queda reservado para pruebas rápidas que no dependan de semántica específica del motor. Migraciones, índices, constraints, concurrencia, locks, transacciones, JSONB y webhooks deben validarse con PostgreSQL real.

## Servicios locales

Para una demo integrada se ejecutan cinco procesos: frontend Nova (`:3000`), backend Nova (`:8080`), SunFacturation HTTP (`:8081`), worker de documentos y scheduler de Laravel.

```bash
# SunFacturation HTTP
cd /Users/edinson/Documents/Desarrollo/sunfacturation-main
php artisan serve --host=127.0.0.1 --port=8081

# Worker: documentos y webhooks
php artisan queue:work documents --queue=default --tries=1 --timeout=60 --sleep=1 --verbose

# Outbox y reconciliación
php artisan schedule:work
```

```bash
# Nova API
cd /Users/edinson/Documents/Desarrollo/Nova
dotnet run --project NovaApi --launch-profile http

# Nova frontend
cd /Users/edinson/Documents/Desarrollo/Nova/nova-web
npm run dev
```

El scheduler ejecuta `webhooks:dispatch --limit=100` cada minuto y `billing:reconcile --limit=100` cada cinco minutos. El worker procesa `ProcessElectronicDocumentJob` y `DeliverWebhookJob`.

El destino loopback (`WEBHOOK_ALLOW_UNSAFE_LOCAL=true`) solo debe habilitarse en desarrollo. En entornos compartidos o productivos se debe usar HTTPS y un destino autorizado.

## Flujo de emisión

Nova solicita la admisión con `Idempotency-Key` y `external_reference`. SunFacturation reserva serie y correlativo dentro de una transacción, persiste el documento y encola el job estándar.

Cuando el documento es aceptado, genera XML, CDR y PDF, publica `document.accepted` en `McrOutboxEvent` y lo entrega al webhook de Nova. Nova aplica el evento mediante `RstBillingWebhookEvent`, actualiza `RstSale` y conserva la versión de estado fiscal.

La pantalla de ventas no consulta continuamente ni usa polling o SignalR para este estado. El usuario recarga la página cuando desea ver cambios. Los endpoints PDF, XML y CDR recuperan las rutas de la submission si el webhook solo informó la disponibilidad de los artefactos.

## PostgreSQL

La conexión debe alinear la zona horaria de PHP y PostgreSQL:

```dotenv
DB_CONNECTION=pgsql
DB_TIMEZONE=America/Lima
```

No registrar credenciales reales en este archivo ni en el repositorio. Usar `.env` local o un gestor de secretos.

## Verificación rápida

```bash
curl -I http://127.0.0.1:8081/
curl -I http://127.0.0.1:8080/swagger/index.html
curl -I http://127.0.0.1:3000/restaurant/sale/
php artisan webhooks:dispatch --limit=100
```

Para comprobar una venta, validar en `RstSale` el estado `accepted`, `SalBillingDocumentID`, `SalBillingSubmissionID`, `SalInvoiceNumber` y los flags de PDF/XML/CDR. Los duplicados se controlan por `external_reference`, idempotencia y deduplicación del outbox.

## Pruebas

```bash
php artisan test
PIPELINE_TEST_POSTGRES=1 php artisan test
```

Las pruebas que dependen de PostgreSQL deben ejecutarse contra una instancia PostgreSQL real; una suite verde únicamente en SQLite no es suficiente para validar persistencia.

## Documentación adicional

- `docs/architecture/`: decisiones y fases de arquitectura.
- `docs/postman/`: colección de integración HTTP.
- `/docs`: documentación visual de la API.
