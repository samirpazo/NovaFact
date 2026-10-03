# NovaFact: nota_credito (07)

POST `/api/facturacion/emitir-factura` con credencial emit y clave de idempotencia.
Seleccionar empresa autorizada; registrar establecimiento DEFAULT y serie activa.
Actualizar fechas antes de ejecutar. Las notas internas necesitan el ID de un documento aceptado propio.
El correlativo lo asigna el servidor.

```json
{
    "tipoDoc": "07",
    "establishment": "DEFAULT",
    "serie": "FC01",
    "fechaEmision": "2026-10-03T10:00:00-05:00",
    "tipoMoneda": "PEN",
    "clientTipoDoc": "6",
    "clientNumDoc": "20123456789",
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
    ],
    "reference": {
        "kind": "internal",
        "reason_code": "04",
        "reason": "Ajuste probado",
        "document_id": 123
    }
}
```

La respuesta es HTTP 202, con submission_id y estado queued; consultar el envío para conocer la aceptación fiscal.
