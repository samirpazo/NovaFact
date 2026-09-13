# Principios técnicos permanentes

## PostgreSQL como fuente de verdad

PostgreSQL es el motor de base de datos de referencia y la fuente de verdad del proyecto. El diseño de producción, las migraciones y las garantías de persistencia deben responder a su semántica.

Toda funcionalidad relacionada con migraciones, constraints, índices, índices parciales, `NULLS DISTINCT`, concurrencia, `SELECT ... FOR UPDATE`, locks, transacciones, aislamiento, `ON CONFLICT`, JSONB o SQL específico de PostgreSQL debe validarse obligatoriamente contra una instancia PostgreSQL temporal real. La misma regla se aplica a cualquier comportamiento cuya semántica pueda variar entre motores.

Una prueba verde únicamente en SQLite no constituye evidencia suficiente para cerrar una fase relacionada con persistencia. La evidencia de cierre debe identificar las pruebas ejecutadas en PostgreSQL y sus resultados.

SQLite puede mantenerse como apoyo para pruebas rápidas, unitarias o de lógica independientes del motor. No se adaptará ni simplificará una implementación de producción para obtener compatibilidad con SQLite cuando eso degrade o condicione el diseño correcto para PostgreSQL. Ante diferencias entre ambos motores, prevalece PostgreSQL.

Esta regla rige las fases presentes y futuras del proyecto.
