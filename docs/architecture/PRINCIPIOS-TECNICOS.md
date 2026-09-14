# Principios técnicos permanentes

## PostgreSQL como fuente de verdad

PostgreSQL es el motor de base de datos de referencia y la fuente de verdad del proyecto. El diseño de producción, las migraciones y las garantías de persistencia deben responder a su semántica.

Toda funcionalidad relacionada con migraciones, constraints, índices, índices parciales, `NULLS DISTINCT`, concurrencia, `SELECT ... FOR UPDATE`, locks, transacciones, aislamiento, `ON CONFLICT`, JSONB o SQL específico de PostgreSQL debe validarse obligatoriamente contra una instancia PostgreSQL temporal real. La misma regla se aplica a cualquier comportamiento cuya semántica pueda variar entre motores.

Una prueba verde únicamente en SQLite no constituye evidencia suficiente para cerrar una fase relacionada con persistencia. La evidencia de cierre debe identificar las pruebas ejecutadas en PostgreSQL y sus resultados.

SQLite puede mantenerse como apoyo para pruebas rápidas, unitarias o de lógica independientes del motor. No se adaptará ni simplificará una implementación de producción para obtener compatibilidad con SQLite cuando eso degrade o condicione el diseño correcto para PostgreSQL. Ante diferencias entre ambos motores, prevalece PostgreSQL.

Esta regla rige las fases presentes y futuras del proyecto.

## Ambigüedad y resultado fiscal

Nunca se reenvía automáticamente una submission cuyo resultado remoto sea ambiguo. Desde que comienza el contacto con SUNAT, una interrupción sin respuesta verificable exige consulta/reconciliación o revisión manual. El texto de una excepción no constituye evidencia de que SUNAT no recibió el documento.

El estado fiscal debe persistirse antes de generar artefactos secundarios. Un fallo de PDF, registro local o archivo auxiliar no cambia un documento aceptado a `failed` y nunca vuelve a emitirlo; sólo activa recuperación de artefactos usando el snapshot y el XML firmado exacto.

Toda recuperación fiscal usa la empresa persistida en el documento/submission. No se permite seleccionar primera empresa, empresa activa global ni fallback implícito para certificados, SOL, OAuth, series, envío, polling o reconciliación.

Las colas delayed mejoran latencia, pero no son la fuente de verdad del retry. La próxima acción, el checkpoint, los contadores y `NextAttemptAt` se persisten para que el reconciliador reconstruya la operación después de perder la cola o reiniciar workers.

## Documentos con ticket

Cuando SUNAT responde con ticket, el documento entra en `awaiting_sunat` y cada consulta se ejecuta mediante un delayed job que transporta IDs y persiste su intento. Ningún request ni worker mantiene bucles de polling o usa `sleep()`; un resultado desconocido se conserva recuperable y nunca se clasifica como rechazo fiscal.

## Exactitud monetaria fiscal

Las decisiones de integridad monetaria se realizan con decimales exactos o unidades menores enteras. No se usan `float` para comparar límites, recomponer totales, calcular impuestos ni decidir admisiones. Cada contrato declara su escala y su regla de redondeo; una entrada con precisión excedente se rechaza en lugar de redondearse silenciosamente. Las conversiones exigidas por una librería externa sólo ocurren en su frontera, después de completar las validaciones y persistir el snapshot decimal canónico.
