# Limpieza y presentación NovaFact

Actualización del 2 de octubre de 2026, posterior a la evidencia fiscal existente.

- Se eliminó únicamente el documento demo local F001-1 y sus registros dependientes en `nova_structural_test`. Se conservaron empresas, credenciales, certificados y correlativos.
- Se retiró el árbol antiguo `storage/app/private/facturacion`: XML, CDR, PDF F001-1, logo compartido y `.DS_Store`.
- El logo propio existente `public/logo-nova.svg` se rasterizó mediante canvas Chromium a PNG transparente de 600 × 401. No es un activo generado por IA.
- Se regeneraron los cuatro PDF existentes de factura, boleta y notas en `storage/app/private/readiness/2026-10-02`. Los hashes de todos los XML y CDR se comprobaron sin cambios. No hubo nuevos envíos fiscales.
- Los hashes y tamaños actualizados de los PDF constan en `../2026-10-02.json`.
- Inicio y documentación comparten plantilla, tipografía Outfit local, colores, navegación y pie. La documentación incorpora búsqueda, navegación móvil, ejemplos de los seis documentos y botones de copia.
- Playwright MCP verificó ambas rutas a 1440, 1024, 390 y 320 px sin desbordamiento horizontal; JSON válido en los seis ejemplos, búsqueda con y sin resultados, menú móvil y copia al portapapeles. Consola sin errores ni advertencias.
- Vite y compilación Blade correctos. Suite PostgreSQL final: 238 pruebas aprobadas, 887 aserciones, sin omisiones. Regresión adicional: logo PNG en demo, prioridad del logo configurado, ausencia de logo compartido y separación beta/producción.

Las capturas adjuntas documentan la revisión visual. El PDF de factura muestra el logo visible; los cuatro PDF contienen el PNG con su canal de transparencia.

Las guías GRE siguen pendientes de credenciales y validación externa, según el informe fiscal previo. Esta actualización no altera su clasificación.

## Guías con estilo compartido

`guide-09.png` y `guide-31.png` representan los PDFs demo regenerados con la cabecera,
tipografía, bordes y tabla de comprobantes. Se conservó el logo, el QR del CDR demo,
peso, bultos, remitente, destinatario, vehículo, conductor, licencia y registro MTC.
Los hashes actualizados de los PDFs están en `../2026-10-02-gre.json`; XML/ZIP/CDR no
se modificaron.

`production-desktop.png` y `production-mobile.png` muestran la sección de producción.
Playwright verificó 11 anclas existentes, ausencia de desbordamiento móvil y descarga
HTTP 200 de `NovaFact-Produccion.md`. El enlace de inicio apunta a `#produccion`.
Una guía de 45 bienes conservó todas las líneas y el QR en cuatro páginas.
