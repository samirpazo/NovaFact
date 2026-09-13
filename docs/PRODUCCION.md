# Nova Facturación — Pase a producción

Esta guía describe el cambio del microservicio desde beta/desarrollo hacia un VPS con Podman. No basta con cambiar una sola variable: también deben cambiarse las credenciales SUNAT, el certificado, la base de datos, las URLs públicas y los secretos compartidos con Nova.

## 1. Preparar el entorno

En el VPS clonar o copiar el proyecto y crear el archivo de producción:

```bash
cp .env.production.example .env.production
chmod 600 .env.production
```

No subir `.env` ni `.env.production` al repositorio.

## 2. Variables obligatorias del microservicio

Editar `.env.production`:

```dotenv
APP_NAME="Nova Facturación"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://facturacion.midominio.com
APP_KEY=              # generar con: php artisan key:generate --show

DB_CONNECTION=pgsql
DB_HOST=<host-postgresql>
DB_PORT=5432
DB_DATABASE=<base-produccion>
DB_USERNAME=<usuario-produccion>
DB_PASSWORD=<clave-produccion>
DB_SSLMODE=require

QUEUE_CONNECTION=database
CACHE_STORE=file
FILESYSTEM_DISK=local

SUNAT_RUC=<ruc-real>
SUNAT_USER=<usuario-sol-real>
SUNAT_PASSWORD=<clave-sol-real>
SUNAT_CERTIFICATE_NAME=<archivo-certificado>
SUNAT_CERTIFICATE_PASSWORD=<clave-certificado>
SUNAT_ENDPOINT=https://e-facturacion.sunat.gob.pe/ol-ti-itcpfegem/billService

BILLING_SERVICE_TOKEN=<token-largo-y-aleatorio>
```

El mismo `BILLING_SERVICE_TOKEN` debe configurarse en Nova (`BillingService:Token`). La URL que Nova usa debe ser la URL HTTPS pública del microservicio, no `127.0.0.1`.

## 3. Certificado digital

Copiar el certificado de producción al directorio privado configurado por la aplicación. No ponerlo en `public/`, no incluirlo en Git y no reutilizar el certificado beta.

```bash
mkdir -p storage/app/private/certificates
chmod 700 storage/app/private/certificates
cp certificado-produccion.pfx storage/app/private/certificates/
chmod 600 storage/app/private/certificates/certificado-produccion.pfx
```

Registrar el nombre exacto y la contraseña en la configuración tributaria. El certificado y las contraseñas permanecen únicamente en el microservicio; Nova nunca debe recibir sus valores.

## 4. Base de datos y numeración

Usar una base PostgreSQL de producción independiente. Antes de migrar, realizar un respaldo. Ejecutar:

```bash
podman compose -f podman-compose.yml run --rm billing-api php artisan migrate --force
podman compose -f podman-compose.yml run --rm billing-api php artisan config:cache
```

Configurar las series reales y su próximo correlativo desde la pantalla tributaria. No ejecutar `billing:reset-beta` en producción: ese comando elimina comprobantes y reinicia datos de prueba.

## 5. Despliegue con Podman

Desde la raíz de Nova:

```bash
bash scripts/podman-deploy.sh
podman compose -f podman-compose.yml ps
```

El servicio `billing-worker` debe quedar activo para procesar envíos sin bloquear las peticiones HTTP:

```bash
podman compose -f podman-compose.yml logs -f billing-worker
```

## 6. Configurar Nova

En el backend de Nova configurar:

```json
{
  "BillingService": {
    "Url": "https://facturacion.midominio.com",
    "Token": "<el-mismo-BILLING_SERVICE_TOKEN>",
    "ClientCode": "nova-restaurant",
    "CompanyId": 1
  }
}
```

Reconstruir y publicar Nova después de cambiar la URL. El frontend no debe apuntar directamente al microservicio para archivos privados; Nova actúa como proxy autenticado.

## 7. Verificación posterior

1. `GET https://facturacion.midominio.com/health/live` responde correctamente.
2. Nova puede consultar la configuración de empresa.
3. Emitir una boleta/factura de prueba autorizada.
4. Confirmar que el worker procesa la cola y que Nova obtiene el estado mediante consulta de `submission_id`.
5. Descargar PDF, XML y CDR mediante URLs protegidas.
6. Revisar logs y confirmar que no aparecen secretos.
7. Configurar HTTPS, firewall, copias de seguridad de PostgreSQL y rotación del token.

## 8. Orden recomendado de cambio

1. Crear PostgreSQL y respaldos.
2. Instalar Podman y desplegar el microservicio.
3. Cargar certificado y credenciales SOL de producción.
4. Configurar series y correlativos.
5. Publicar Nova con la URL/token nuevos.
6. Ejecutar una emisión controlada y validar archivos y estado mediante polling.
