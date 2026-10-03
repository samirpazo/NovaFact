# NovaFact: guia_remitente (09)

POST `/api/facturacion/emitir-guia` con credencial emit y clave de idempotencia.
Seleccionar empresa autorizada; registrar establecimiento DEFAULT y serie activa.
Actualizar fechas antes de ejecutar. Las notas internas necesitan el ID de un documento aceptado propio.
El correlativo lo asigna el servidor.

```json
{
    "tipoDoc": "09",
    "serie": "T001",
    "fechaEmision": "2026-10-03T10:00:00-05:00",
    "establishment": "DEFAULT",
    "destinatario": {
        "tipo_documento": "6",
        "numero_documento": "20444444441",
        "razon_social": "Destinatario SAC"
    },
    "traslado": {
        "motivo": "01",
        "modalidad": "02",
        "fecha_inicio": "2026-10-04",
        "peso_bruto": "125.375",
        "unidad_peso": "KGM",
        "bultos": 2,
        "origen": {
            "ubigeo": "150101",
            "direccion": "Av. Origen 123"
        },
        "destino": {
            "ubigeo": "150122",
            "direccion": "Av. Destino 456"
        },
        "conductor": {
            "tipo_documento": "1",
            "numero_documento": "12345678",
            "nombres": "Ana",
            "apellidos": "Quispe",
            "licencia": "Q12345678"
        },
        "vehiculo": {
            "placa": "ABC123"
        }
    },
    "bienes": [
        {
            "codigo": "P001",
            "descripcion": "Producto de prueba",
            "unidad": "NIU",
            "cantidad": "10.500000"
        }
    ],
    "documentos_relacionados": [
        {
            "tipo": "01",
            "numero": "F001-123",
            "emisor": "20123456789"
        }
    ]
}
```

La respuesta es HTTP 202, con submission_id y estado queued; consultar el envío para conocer la aceptación fiscal.
