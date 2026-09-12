# Ejemplo: Boleta de Venta Electrónica (03)

Ejemplo de emisión de una boleta de venta para una persona natural identificada con DNI.

### Endpoint
`POST /api/facturacion/emitir-factura`

### JSON Request Body
```json
{
  "tipoDoc": "03",
  "serie": "B001",
  "correlativo": "52",
  "fechaEmision": "2024-03-16T17:05:00-05:00",
  "tipoMoneda": "PEN",
  "clientTipoDoc": "1",
  "clientNumDoc": "44556677",
  "clientRznSocial": "JUAN PEREZ GARCIA",
  "mtoOperGravada": 42.37,
  "mtoIGV": 7.63,
  "mtoTotal": 50.00,
  "items": [
    {
      "codigo": "P-002",
      "descripcion": "ALQUILER DE EQUIPOS",
      "unidad": "NIU",
      "cantidad": 1,
      "mtoBaseIgv": 42.37,
      "igv": 7.63,
      "mtoValorUnitario": 42.37,
      "mtoValorVenta": 42.37,
      "mtoPrecioUnitario": 50.00
    }
  ]
}
```

### Descripción de campos clave
*   **`tipoDoc`**: '03' identifica que es una Boleta.
*   **`clientTipoDoc`**: '1' indica que el `clientNumDoc` es un DNI.
*   **`clientRznSocial`**: Nombres completos del cliente.
*   **Impuestos**: El mtoTotal debe cuadrar con la suma de gravada + IGV (redondeado a 2 decimales).

### Respuesta Exitosa
```json
{
  "success": true,
  "message": "Comprobante emitido exitosamente",
  "id": "20600000000-03-B001-52",
  "description": "La Boleta numero B001-52, ha sido aceptada",
  "cdr_base64": "UEY..."
}
```
