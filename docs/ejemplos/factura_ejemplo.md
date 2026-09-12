# Ejemplo: Factura Electrónica (01)

Este ejemplo muestra cómo emitir una factura electrónica para un cliente con RUC, incluyendo el detalle de productos y la referencia a una guía de remisión previa.

### Endpoint
`POST /api/facturacion/emitir-factura`

### JSON Request Body
```json
{
  "tipoDoc": "01",
  "serie": "F001",
  "correlativo": "128",
  "fechaEmision": "2024-03-16T17:00:00-05:00",
  "tipoMoneda": "PEN",
  "clientTipoDoc": "6",
  "clientNumDoc": "20601234567",
  "clientRznSocial": "CONSTRUCTORA SOL DEL NORTE S.A.C.",
  "mtoOperGravada": 200.00,
  "mtoIGV": 36.00,
  "mtoTotal": 236.00,
  "items": [
    {
      "codigo": "SERV-001",
      "descripcion": "TRANSPORTE DE AGREGADOS - VIAJE 01",
      "unidad": "NIU",
      "cantidad": 1,
      "mtoBaseIgv": 200.00,
      "igv": 36.00,
      "mtoValorUnitario": 200.00,
      "mtoValorVenta": 200.00,
      "mtoPrecioUnitario": 236.00
    }
  ],
  "guias": [
    {
      "tipoDoc": "09",
      "nroDoc": "T001-0000045"
    }
  ],
  "observaciones": "Pago a 30 días"
}
```

### Descripción de campos clave
*   **`tipoDoc`**: '01' identifica que es una Factura.
*   **`clientTipoDoc`**: '6' indica que el `clientNumDoc` es un RUC.
*   **`mtoOperGravada`**: Suma de los valores de venta (sin IGV) de todos los items.
*   **`mtoIGV`**: 18% de la operación gravada.
*   **`mtoTotal`**: Suma de base gravada + impuestos.
*   **`guias`**: (Opcional) Array de guías de remisión relacionadas al comprobante.

### Respuesta Exitosa
```json
{
  "success": true,
  "message": "Comprobante emitido exitosamente",
  "id": "20600000000-01-F001-128",
  "description": "La Factura numero F001-128, ha sido aceptada",
  "cdr_base64": "UEY..."
}
```
