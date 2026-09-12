# Ejemplo: Guía de Remisión Remitente (09)

Este documento es utilizado por el remitente para sustentar el traslado de bienes. En este ejemplo se usa la **Modalidad de Transporte Privado**.

### Endpoint
`POST /api/facturacion/emitir-guia`

### JSON Request Body
```json
{
  "tipoDoc": "09",
  "serie": "T001",
  "correlativo": "84",
  "fechaEmision": "2026-03-16",
  "destinatarioTipoDoc": "6",
  "destinatarioNumDoc": "20555444333",
  "destinatarioRznSocial": "ALMACENES GENERALES S.A.",
  "motivoTraslado": "01",
  "modalidadTraslado": "02",
  "fechaTraslado": "2026-03-17",
  "pesoTotal": 150.00,
  "unidadMedida": "KGM",
  "ubigeoPartida": "150101",
  "direccionPartida": "CALLE LOS PINOS 123, LIMA",
  "ubigeoLlegada": "150132",
  "direccionLlegada": "AV. INDUSTRIAL 456, SURRILLO",
  "placaVehiculo": "V3X998",
  "choferTipoDoc": "1",
  "choferNumDoc": "12345678",
  "choferNombres": "CARLOS",
  "choferApellidos": "TORRES",
  "choferLicencia": "A12345678",
  "details": [
    {
      "codigo": "ART-001",
      "descripcion": "CEMENTO PORTLAND TIPO I - 50KG",
      "unidad": "NIU",
      "cantidad": 3
    }
  ]
}
```

### Descripción de campos clave
*   **`motivoTraslado`**: '01' (Venta), '04' (Traslado entre establecimientos), etc.
*   **`modalidadTraslado`**: '02' significa transporte privado (se requiere vehículo y chofer).
*   **`ubigeoPartida` / `ubigeoLlegada`**: Códigos de 6 dígitos de SUNAT/INEI.
*   **`details`**: Lista de bienes (en el código también se mapea desde `bienes` si no se usa `details`).

### Respuesta Exitosa
```json
{
  "success": true,
  "message": "Guía aceptada correctamente",
  "ticket": "202403160000001",
  "cdr_base64": "UEY..."
}
```
