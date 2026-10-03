async (page) => {
  // Run only against configure-demo.php's isolated readiness_gre schema.
  const base = 'http://127.0.0.1:18083';
  const headers = { Authorization: 'Bearer isolated-gre-demo-token', Accept: 'application/json' };
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
  const component = name => parts.find(part => part.type === name).value;
  const today = `${component('year')}-${component('month')}-${component('day')}`;
  const results = [];
  for (const type of ['09', '31']) {
    const payload = {
      tipoDoc: type, serie: type === '09' ? 'T001' : 'V001', establishment: 'NOVA-GRE',
      fechaEmision: `${today}T00:01:00-05:00`,
      destinatario: { tipo_documento: '6', numero_documento: '20444444441', razon_social: 'Destinatario Demo' },
      traslado: {
        motivo: '01', modalidad: type === '09' ? '02' : '01', fecha_inicio: today,
        peso_bruto: '125.375', unidad_peso: 'KGM', bultos: 2,
        origen: { ubigeo: '150101', direccion: 'Av. Origen Demo 123' },
        destino: { ubigeo: '150122', direccion: 'Av. Destino Demo 456' },
        conductor: { tipo_documento: '1', numero_documento: '12345678', nombres: 'Ana', apellidos: 'Quispe', licencia: 'Q12345678' },
        vehiculo: { placa: 'ABC123' },
      },
      bienes: [{ codigo: 'P001', descripcion: 'Producto de prueba NovaFact', unidad: 'NIU', cantidad: '10.5' }],
    };
    if (type === '31') {
      payload.remitente = { tipo_documento: '6', numero_documento: '20333333331', razon_social: 'Remitente Demo' };
      payload.traslado.transportista = { tipo_documento: '6', numero_documento: '20161515648', razon_social: 'NovaFact GRE Demo', registro_mtc: '1512345CNG' };
    }
    const key = `nova-gre-v2-${today}-${type}`;
    const options = { headers: { ...headers, 'Idempotency-Key': key }, data: payload };
    const response = await page.request.post(`${base}/api/facturacion/emitir-guia`, options);
    const body = await response.json();
    if (response.status() !== 202) throw Error(`GRE ${type}: ${response.status()} ${JSON.stringify(body)}`);
    const duplicate = await page.request.post(`${base}/api/facturacion/emitir-guia`, options);
    const repeated = await duplicate.json();
    if (duplicate.status() !== 202 || repeated.document_id !== body.document_id) throw Error('Idempotency failure');
    const conflict = await page.request.post(`${base}/api/facturacion/emitir-guia`, {
      ...options, data: { ...payload, traslado: { ...payload.traslado, peso_bruto: '126.375' } },
    });
    if (conflict.status() !== 409) throw Error('Changed request must conflict');
    results.push({ type, key, payload, admission: body, duplicate_status: 202, conflict_status: 409 });
  }
  return results;
}
