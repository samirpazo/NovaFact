# NovaFact: guía de pase a producción

Esta guía corresponde al código actual de `main`. Preparar producción no autoriza
emitir documentos ficticios en SUNAT real. Las pruebas disponibles son SUNAT beta
SOAP y el proveedor GRE demo; ninguna habilita por sí sola la empresa para emitir.

## Qué debe facilitar cada empresa

| Dato | Origen | Uso en NovaFact |
|---|---|---|
| RUC, razón social, domicilio, ubigeo y establecimientos | Ficha RUC y registro de establecimientos SUNAT | Identidad del emisor y puntos de emisión |
| Usuario SOL y clave SOL con acceso al servicio utilizado | SUNAT Operaciones en Línea, administración de usuarios | Autenticación SOAP y obtención del token GRE |
| Certificado `.p12`/`.pfx` con clave privada y contraseña | CDT SUNAT si cumple requisitos, o entidad certificadora acreditada | Firma XML; debe corresponder al emisor, estar vigente y registrado cuando corresponda |
| `client_id` y `client_secret` GRE | Gestión Credenciales de API SUNAT en SOL | OAuth para enviar y consultar GRE |
| Series y próximos correlativos por tipo | Control fiscal de la empresa y sistemas anteriores | Evitar reutilizar números ya emitidos |
| Dominio HTTPS, servidor, PostgreSQL, receptor webhook | Infraestructura propia | Operación y consumo de la API |
| Logo PNG/JPEG y datos de contacto | Empresa | Representación impresa PDF |

Guardar secretos en un gestor de secretos o archivos privados del servidor. No
incluirlos en Git, URLs, capturas, logs o sistemas consumidores. No es necesario
enviar contraseñas al chat. Los consumidores reciben únicamente su token NovaFact.

### SOL y sistema de emisión

Entrar a [SUNAT Operaciones en Línea](https://www.sunat.gob.pe/sol.html) con el RUC de
la empresa. Confirmar su condición de emisor y el sistema que le corresponde en
[SEE del contribuyente](https://cpe.sunat.gob.pe/sistema_emision/see_contribuyente).
Verificar con el responsable tributario si existe obligación de utilizar OSE;
NovaFact actualmente envía CPE directamente a SUNAT y no configura un OSE.

SOL, OAuth GRE y el token NovaFact son credenciales distintas. El usuario SOL se
almacena sin concatenar el RUC; los adaptadores forman `RUC + usuario` para SUNAT.
MODDATOS, RUC demo y credenciales con prefijo `test-` se reservan para pruebas.

### OSE pendiente

La integración OSE no está implementada. Por ahora se mantiene el envío directo a
SUNAT. Antes de activar cada empresa, confirmar con su responsable tributario si
su sistema de emisión permite ese envío o requiere un OSE. Una empresa que requiere
OSE no debe emitir mediante el transporte directo actual hasta completar esa integración.

Antes de implementarla, elegir el OSE y obtener su documentación técnica, credenciales,
endpoints y acceso a homologación. Confirmar con el proveedor qué comprobantes,
notas, bajas y resúmenes cubre, cómo autentica, cómo entrega el CDR y qué consultas
permite para tickets y resultados ambiguos.

La futura selección SUNAT/OSE será **por empresa**, con configuración y credenciales
propias. Incluirá envío, consulta de tickets/CDR y recuperación, reutilizando el
pipeline de cola, idempotencia y conservación de artefactos. Cambiar únicamente la
URL SOAP global no implementa soporte OSE y afectaría a las demás empresas.
Las guías GRE conservarán su transporte actual salvo un requisito comprobado que
justifique otro flujo.

La homologación deberá cubrir una empresa con envío directo y otra con OSE, sin
cruce de credenciales, documentos, tickets ni archivos. Probar comprobantes, notas,
bajas y resúmenes; aceptación, observaciones y rechazo; polling de tickets, timeouts,
reinicios y recuperación sin duplicar envíos. Habilitar OSE solo para las empresas
configuradas cuando esas pruebas y la evidencia del proveedor estén completas.
Esta decisión no añade configuración OSE ni modifica el funcionamiento actual.

### Dónde obtener el certificado

Si corresponde al contribuyente, solicitar el Certificado Digital Tributario en SOL:
Empresas → Comprobantes de Pago → Certificado Digital Tributario → Solicitar Certificado
Digital Tributario. Completar la solicitud y descargar el `.p12` desde el mensaje de
emisión recibido en Buzón SOL, creando y conservando su contraseña. SUNAT publica los
[requisitos, procedimiento y tutorial CDT](https://cpe.sunat.gob.pe/certificado-digital).
Si no cumple requisitos, obtener un certificado de una entidad acreditada y confirmar
su uso/registro para el sistema de emisión elegido. Un certificado autofirmado demo
no sirve para producción. La carga de NovaFact verifica apertura y correspondencia
entre clave privada y certificado; **no certifica habilitación fiscal, RUC ni vigencia**.

### Dónde obtener `client_id` / `client_secret` GRE

En SOL de la empresa, buscar **Credenciales de API SUNAT → Gestión Credenciales de API
SUNAT**, registrar la aplicación que usará GRE y generar ambas credenciales. Guardar
el secreto inmediatamente en el gestor privado. Si cambia el menú, consultar el
[manual oficial de autenticación GRE](https://cpe.sunat.gob.pe/sites/default/files/inline-files/Manual_Servicios_GRE%20%281%29_0.pdf).

El adaptador obtiene automáticamente el token con `grant_type=password`, scope
`https://api-cpe.sunat.gob.pe`, OAuth propio y usuario/clave SOL de la empresa.
No copiar un `access_token` temporal a la configuración ni confundir OAuth SUNAT con
`Authorization: Bearer` de NovaFact. El token SUNAT se renueva por el adaptador.

## Entornos y endpoints efectivos

| Servicio | Beta actual | Producción |
|---|---|---|
| CPE SOAP | `https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService` | `https://e-facturacion.sunat.gob.pe/ol-ti-itcpfegem/billService` |
| GRE OAuth base | `https://gre-test.nubefact.com/v1` | `https://api-seguridad.sunat.gob.pe/v1` |
| GRE API base | `https://gre-test.nubefact.com/v1` | `https://api-cpe.sunat.gob.pe/v1` |

`config/sunat.php` los selecciona con `SUNAT_PRODUCTION`. La selección es global por
instancia; desplegar beta y producción por separado. Las empresas tienen además
`McrEnvironment=beta` o `production`; el middleware exige que coincidan. La variable
histórica `SUNAT_ENDPOINT` no selecciona el transporte actual.
`credentials.local.json` es una ayuda privada para la demo: producción no lo importa.
Los campos `SUNAT_RUC/USER/PASSWORD` de `.env` no reemplazan los datos por empresa.

## Desplegar una instancia nueva

1. Preparar PostgreSQL independiente y almacenamiento privado persistente. Separar
   redes, usuarios, backups y `APP_KEY` de beta. Un usuario de despliegue ejecuta las
   migraciones; limitar el usuario de operación a las tablas necesarias.
2. Publicar un dominio con TLS válido. El servidor web sirve exclusivamente `public/`;
   no exponer `.env`, `storage/app/private`, certificados ni respaldo de base.
3. Instalar PHP compatible con Composer (imagen existente PHP 8.4), extensiones
   `pdo_pgsql`, `pgsql`, `zip`, `gd`, `bcmath`, `soap`, `dom`, `mbstring`, OpenSSL,
   Composer y Node para compilar. Verificar requisitos con Composer.

Desde un checkout limpio de `main` en el servidor o en el proceso de empaquetado:

```bash
cp .env.production.example .env
chmod 600 .env
# Editar dominio, PostgreSQL y demás valores privados antes de continuar.
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs
npm ci
npm run build
# Solo en una instancia NUEVA, nunca sobre una APP_KEY con datos cifrados:
php artisan key:generate
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Comprobar estos valores en la instancia productiva:

```dotenv
APP_ENV=production
APP_DEBUG=false
SUNAT_PRODUCTION=true
WEBHOOK_ALLOW_UNSAFE_LOCAL=false
```

Laravel usa `.env` por defecto. Si el orquestador inyecta variables, mantener una única
fuente de configuración. `.env.production.example` es una plantilla, no un archivo
cargado automáticamente por Podman. No ejecutar `composer setup`, seed demo,
`configure-demo.php`, `migrate:fresh`, `migrate:refresh` ni limpieza beta en producción.

Existe `Dockerfile.podman`; construir en un contexto limpio sin archivos privados:

```bash
podman build -f Dockerfile.podman -t novafact:RELEASE .
```

La imagen excluye `credentials.local.json` y los archivos privados mediante
`.dockerignore`. No agregar secretos al contexto de build.

No existe Compose de producción en este repositorio. El despliegue debe definir API,
worker y scheduler con la misma imagen, variables, `APP_KEY` y volumen privado, más
proxy TLS y PostgreSQL. `public/build` debe existir antes del build; la imagen no
compila los assets Node. En despliegue nativo usar PHP-FPM/servidor web; `artisan serve`
es exclusivo de desarrollo. Dar escritura al usuario de PHP en `storage/` y
`bootstrap/cache/`, con permisos privados (directorios 0750, archivos 0640 o más
restrictivos compatibles). No aplicar permisos 0777.

## Registrar empresa, establecimiento y consumidor

Usar una base nueva; no convertir la empresa demo que ya contiene documentos.
No hay endpoint de creación de empresa/series/OAuth GRE: se realiza mediante modelos
en una sesión administrativa de `php artisan tinker`. Las rutas de configuración solo
actualizan una empresa existente. Ejemplo de **datos a sustituir**, sin secretos:

```php
use App\Models\Empresa;
use App\Models\McrApiClient;
use App\Models\McrEstablishment;
use App\Models\McrSeries;

$company = Empresa::create([
    'McrRuc' => 'RUC_REAL_11_DIGITOS', 'McrBusinessName' => 'RAZON SOCIAL REAL',
    'McrTradeName' => 'NOMBRE COMERCIAL', 'McrEnvironment' => 'production',
    'McrAddress' => 'DOMICILIO REAL', 'McrUbigeo' => 'UBIGEO',
    'McrDepartment' => 'DEPARTAMENTO', 'McrProvince' => 'PROVINCIA',
    'McrDistrict' => 'DISTRITO', 'McrCurrencyCode' => 'PEN',
    'McrIgvRate' => 16, 'McrIpmRate' => 2, 'McrTotalTaxRate' => 18,
    'McrIsActive' => true, 'SecStatus' => true,
]);
$store = McrEstablishment::create([
    'McrCompanyConfigID' => $company->getKey(), 'McrExternalCode' => 'CENTRAL',
    'McrSunatCode' => '0000', 'McrName' => 'SEDE REAL', 'McrAddress' => 'DIRECCION REAL',
    'McrUbigeo' => 'UBIGEO', 'McrCountryCode' => 'PE',
    'McrIsDefault' => true, 'McrIsActive' => true, 'SecStatus' => true,
]);
McrApiClient::create(['McrCode' => 'mi-sistema', 'McrName' => 'Mi sistema',
    'McrIsActive' => true, 'SecStatus' => true]);
$company->getKey(); // Anotar el ID real, no asumir que es 1.
```

Ajustar tasas al régimen real soportado. `0000` solo corresponde cuando aplica a la sede
principal; las otras sedes usan su código SUNAT. Crear series por tipo, verificando el
siguiente correlativo en todos los sistemas previos. Ejemplo para una serie aprobada:

```php
McrSeries::create([
    'McrCompanyConfigID' => $company->getKey(), 'McrEstablishmentID' => $store->getKey(),
    'McrDocumentType' => '01', 'McrSeriesCode' => 'F001',
    'McrNextCorrelative' => 1, // Sustituir por el siguiente número fiscal libre.
    'McrIsActive' => true, 'SecStatus' => true,
]);
```

Para 03 usar B; 07/08 usan serie F o B según el documento relacionado; 09 usa T; 31
usa V. Cada tipo tiene su registro de serie. No reiniciar ni reutilizar correlativos
al actualizar, restaurar o cambiar de versión. No ejecutar los ejemplos de creación
más de una vez sobre la misma empresa.

Emitir credenciales con el ID real seleccionado:

```bash
php artisan billing:credential issue --client=mi-sistema --company=ID_REAL --permission=read --permission=emit
php artisan billing:credential issue --client=mi-sistema --company=ID_REAL --permission=admin
```

Guardar cada token mostrado una sola vez en el gestor del consumidor; conservar el ID
para revocación. Separar administración del token de emisión. Para varias empresas,
repetir `--company=ID`; el consumidor selecciona con `X-Company-Id`. La administración
de credenciales se realiza por CLI, no con un token HTTP admin.

## Cargar SOL, certificado, logo y OAuth

Las llamadas administrativas usan HTTPS, token con permiso `admin` y
`X-Company-Id: ID_REAL`. Crear los cuerpos privados en el servidor con permisos 0600;
los nombres siguientes son ejemplos, no archivos entregados con secretos.

- `PUT /api/facturacion/configuracion/empresa/credenciales-sol`:
  `{"sol_user":"USUARIO_SOL","sol_password":"CLAVE_SOL"}`.
- `POST /api/facturacion/configuracion/empresa/certificado`: multipart `certificate`
  con `.p12`/`.pfx` y `certificate_password`. El archivo queda en el disco privado
  y su nombre se registra automáticamente. Un archivo/contraseña inválido devuelve 422.
- `POST /api/facturacion/configuracion/empresa/logo`: multipart `logo` PNG/JPEG/WebP,
  máximo 512 KiB. Recomendar PNG para ambos servicios PDF.
- `PUT /api/facturacion/configuracion/empresa`: datos públicos de identidad/tasas.
  El RUC queda bloqueado tras admitir documentos; no sirve para convertir una demo.

Ejemplos desde el servidor: crear un archivo privado `/run/secrets/novafact-admin.headers`
con `Authorization: Bearer TOKEN_ADMIN` y `X-Company-Id: ID_REAL`, y archivos privados
`sol.json` y `certificado-password.txt` con los valores reales. Sustituir la URL y las
rutas; no usar una credencial `read/emit` para estas llamadas.

```bash
curl --fail-with-body https://billing.example.com/api/facturacion/configuracion/empresa/credenciales-sol \
  -X PUT -H @/run/secrets/novafact-admin.headers -H 'Accept: application/json' \
  -H 'Content-Type: application/json' --data-binary @/run/secrets/sol.json
curl --fail-with-body https://billing.example.com/api/facturacion/configuracion/empresa/certificado \
  -H @/run/secrets/novafact-admin.headers -H 'Accept: application/json' \
  -F 'certificate=@/run/secrets/empresa.p12' \
  -F 'certificate_password=</run/secrets/certificado-password.txt'
```

OAuth GRE todavía no tiene ruta HTTP de configuración. En Tinker, seleccionar la
empresa exacta y solicitar los valores con prompts, evitando escribir secretos en
el historial. `Laravel\Prompts\password()` oculta el valor escrito:

```php
(function (): void {
    $company = App\Models\Empresa::whereKey(ID_REAL)->where('McrEnvironment', 'production')->firstOrFail();
    $company->McrClientId = Laravel\Prompts\text('GRE client_id generado en SOL');
    $company->McrClientSecret = Laravel\Prompts\password('GRE client_secret generado en SOL');
    $company->save();
})();
```

La función no devuelve secretos al intérprete; no imprimir el modelo ni contraseñas.
Los casts Eloquent cifran SOL password, OAuth secret y contraseña del certificado con
`APP_KEY`; **no usar UPDATE SQL para esos campos**. Las credenciales deben pertenecer
al mismo RUC. La consulta administrativa informa `has_sol_credentials` y
`has_certificate`, pero no valida OAuth remoto ni muestra secretos.

## Workers, scheduler y webhooks

Mantener un worker supervisado permanentemente, con el mismo entorno de la API:

```bash
php artisan queue:work documents --queue=default --tries=1 --timeout=60 --sleep=1
```

Ejemplo Supervisor (ajustar rutas/usuario; instalar Supervisor y habilitar el servicio):

```ini
[program:novafact-worker]
directory=/srv/novafact
command=/usr/bin/php artisan queue:work documents --queue=default --tries=1 --timeout=60 --sleep=1
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=180
redirect_stderr=true
stdout_logfile=/var/log/novafact-worker.log
```

Cron cada minuto, también como usuario de la aplicación:

```cron
* * * * * cd /srv/novafact && /usr/bin/php artisan schedule:run >> /var/log/novafact-scheduler.log 2>&1
```

`documents.retry_after=180` supera el timeout de 60 segundos. El scheduler despacha
webhooks cada minuto, reconcilia RC/RA cada minuto y documentos cada cinco minutos.
Registrar suscripciones HTTPS por `/api/webhooks/subscriptions`, conservar el secreto
HMAC y deduplicar por `event_id`; responder 2xx después de persistir el evento.
`WEBHOOK_ALLOW_UNSAFE_LOCAL=false` debe permanecer en producción.

## Validar el pase antes de admitir tráfico

- Revisar RUC, sistema de emisión, vigencia/registro del certificado, SOL/OAuth,
  series, correlativos y tipos de operación con el responsable fiscal.
- Verificar `/up`, consultas autenticadas, TLS, permisos de disco, worker, scheduler,
  espacio libre y restauración. `/up` no comprueba toda la infraestructura.
- Repetir primero la suite PostgreSQL y los flujos beta en instancias aisladas.
- En producción, validar una operación **real y autorizada**, nunca un comprobante
  ficticio. Confirmar CDR/estado remoto y descargar XML firmado, PDF y CDR.
- Una admisión HTTP 202 o un ticket no autoriza el traslado por sí solo. Confirmar el
  resultado GRE que corresponda antes de entregar documentación para un traslado real.
- No trasladar la aceptación «CDR de prueba» del proveedor demo como aceptación SUNAT.
- Conservar idempotencia en el consumidor y verificación HMAC del receptor.

El contrato inicial cubre ventas habituales y traslado implementado. Antes de ofrecer
casos especiales, revisar reglas/XSL actuales, modalidades de traslado, documentos
relacionados, detracciones, regímenes específicos y pago a crédito. No hay baja GRE
por RC/RA; requiere el procedimiento aplicable fuera de estas rutas.

## Respaldo, restauración, incidentes y actualización

Respaldar PostgreSQL, `storage/app/private`, `APP_KEY` y configuración privada como un
conjunto coherente. Cifrar el respaldo y conservar una copia fuera del servidor.
Para una copia coherente, detener admisiones y esperar/detener workers sin interrumpir
contactos fiscales en curso; respaldar base y volumen durante esa ventana.
`pg_dump -Fc` permite restaurar en una base nueva con `pg_restore`. No publicar dumps.

Ensayar recuperación en una instancia aislada **sin acceso de salida a SUNAT ni
workers/scheduler activos**: restaurar base, almacenamiento y la misma APP_KEY;
comprobar descifrado sin imprimir secretos, referencias y hashes de XML/CDR,
correlativos, estados y entregas pendientes. Un restore productivo exige reconciliar
contactos remotos posteriores al punto del backup antes de emitir de nuevo.

Para actualizar, fijar un commit de `main`, respaldar, detener admisiones, aplicar
migraciones aditivas, recalcular cachés y reiniciar workers supervisados con
`php artisan queue:restart`. No regenerar `APP_KEY`. No hacer rollback destructivo de
las migraciones originales; revertir código compatible o restaurar de forma controlada.

`manual_review` requiere contrastar CDR, ticket, XML y evidencia remota; no reenviar con
otra clave para salir de un timeout ambiguo. `billing:reconcile` y
`billing:fiscal-reconcile` recuperan estados vencidos por cola. Vigilar jobs fallidos,
antigüedad de cola, errores fiscales, falta de artefactos, fallos webhook, caducidad de
certificado y backups. Rotar tokens emitiendo uno nuevo, actualizando el consumidor y
revocando el anterior con `billing:credential revoke --id=ID`.

Consultar [integración](API-INTEGRATION.md), [evidencia y límites](READINESS-2026-10-02.md)
y [verificación reproducible](../scripts/verification/README.md).
