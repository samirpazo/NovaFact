#!/bin/sh
set -eu

# Solo normalizamos el árbol de comprobantes; no tocamos otras áreas del
# volumen privado que puedan contener secretos o archivos ajenos a facturación.
root=/var/www/html/storage/app/private/facturacion
mkdir -p "$root/pdf" "$root/xml" "$root/cdr"
chown www-data:www-data "$root" "$root/pdf" "$root/xml" "$root/cdr"
find "$root" -type d -exec chmod 750 {} \;
find "$root" -type f -exec chown www-data:www-data {} \; -exec chmod 640 {} \;

exec "$@"
