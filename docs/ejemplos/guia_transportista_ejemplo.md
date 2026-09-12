# Ejemplo: Guía de Remisión Transportista (31)

Este documento es emitido por el transportista cuando presta el servicio de transporte público.

### Endpoint
`POST /api/facturacion/emitir-guia`

### JSON Request Body
```json
{
  "tipoDoc": "31",
  "serie": "V001",
  "correlativo": "10",
  "fechaEmision": "2024-03-16",
  "destinatarioTipoDoc": "6",
  "destinatarioNumDoc": "20999888777",
  "destinatarioRznSocial": "CLIENTE FINAL S.A.C.",
  "remitenteTipoDoc": "6",
  "remitenteNumDoc": "20111222333",
  "remitenteRznSocial": "PROVEEDOR ORIGEN S.A.",
  "motivoTraslado": "01",
  "modalidadTraslado": "01",
  "fechaTraslado": "2024-03-17",
  "pesoTotal": 2500.00,
  "unidadMedida": "KGM",
  "ubigeoPartida": "150101",
  "direccionPartida": "BASE LOGISTICA CALLE 5",
  "ubigeoLlegada": "040101",
  "direccionLlegada": "ALMACEN AREQUIPA",
  "placaVehiculo": "B4C-887",
  "transportistaTipoDoc": "6",
  "transportistaNumDoc": "20444555666",
  "transportistaRznSocial": "TRANSPORTES LOGISTICOS S.A.",
  "transportistaNroMtc": "999888-MTC",
  "details": [
    {
      "codigo": "LOTE-99",
      "descripcion": "CARGA GENERAL FRACCIONADA",
      "unidad": "KGM",
      "cantidad": 2500
    }
  ]
}
```

### Descripción de campos clave
*   **`tipoDoc`**: '31' identifica Guía de Remisión Transportista.
*   **`remitenteTipoDoc` / `remitenteNumDoc`**: Requerido para identificar quién envía la mercadería.
*   **`transportistaNroMtc`**: Número de registro en el Ministerio de Transportes y Comunicaciones.
*   **`modalidadTraslado`**: '01' (Transporte Público).

### Respuesta Exitosa
```json
{
  "success": true,
  "message": "Guía aceptada correctamente",
  "ticket": "202403160000005"
}
```
