# Despliegue VPS con Podman

1. Copiar `.env.production.example` a `.env.production` y completar secretos. No subir `.env` al repositorio.
2. En Nova, copiar `.env.production.example` a `.env.production` y configurar la URL pública del microservicio en `NOVA_BILLING_SERVICE_URL`/`BillingService:Url` y el mismo token en ambos servicios.
3. Ejecutar `composer install --no-dev --prefer-dist --optimize-autoloader` dentro de la imagen; no se requiere Node para Laravel.
4. Desde Nova ejecutar `bash scripts/podman-deploy.sh`.
5. Ejecutar una sola vez dentro del contenedor del microservicio: `php artisan migrate --force`, `php artisan config:cache` y `php artisan storage:link` si se usa disco público.
6. Verificar `podman compose -f podman-compose.yml ps` y los endpoints `/health/live`, `/up` y `/api/facturacion/configuracion/empresa`.

El worker corre como contenedor separado y comparte el volumen privado de archivos. PostgreSQL se mantiene externo al VPS/contenedores para facilitar respaldos y alta disponibilidad.

## Limpieza beta previa a pruebas

Respaldar ambas bases. En el microservicio ejecutar `php artisan billing:reset-beta --confirm`; en Nova eliminar únicamente las ventas/pagos de prueba acordados y reiniciar las secuencias correspondientes. No ejecutar esta limpieza contra producción ni contra comprobantes reales aceptados por SUNAT.
