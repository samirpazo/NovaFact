# NovaFact: boleta (03)

POST `/api/facturacion/emitir-factura` con credencial emit y clave de idempotencia.
Seleccionar empresa autorizada; registrar establecimiento DEFAULT y serie activa.
Actualizar fechas antes de ejecutar. Las notas internas necesitan el ID de un documento aceptado propio.
El correlativo lo asigna el servidor.

```json
{
    "tipoDoc": "03",
    "establishment": "DEFAULT",
    "serie": "B001",
    "fechaEmision": "2026-10-03T10:00:00-05:00",
    "tipoMoneda": "PEN",
    "clientTipoDoc": "1",
    "clientNumDoc": "12345678",
    "clientRznSocial": "Test client",
    "mtoOperGravada": 100,
    "mtoIGV": 18,
    "mtoTotal": 118,
    "items": [
        {
            "descripcion": "Test item",
            "cantidad": 1,
            "mtoBaseIgv": 100,
            "igv": 18,
            "mtoValorUnitario": 100,
            "mtoPrecioUnitario": 118,
            "mtoValorVenta": 100
        }
    ]
}
```

La respuesta es HTTP 202, con submission_id y estado queued; consultar el envío para conocer la aceptación fiscal.
